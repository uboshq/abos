<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\DeliveryEventLine;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ডেলিভারির ধাপ — একটাই দরজা (NEXUS §২১–২২)।
 *
 * ── ⭐ দুই রকমের বদল, একই নিয়মের খাতায় ────────────────────────────────
 *   • **হাতে** — গুদামের লোক বলেন "তোলা হচ্ছে", "প্যাক হয়েছে", কুরিয়ারে
 *     পাঠালে "রওনা", আর ফেরার পর "পৌঁছেছে / আংশিক / পৌঁছায়নি"। [[move()]]
 *   • **কাগজ থেকে** — চালান নিশ্চিত হলে "বরাদ্দ", বাতিল হলে "বাতিল";
 *     ট্রিপ বেরোলে "রওনা", চালকের কথায় "পৌঁছেছে / ফেরত / আংশিক"।
 *     [[TellsTheDeliveryStage]] এখানে ডাকে।
 *
 * দুইটাই [[DeliveryStage::TRANSITIONS]]-এ মাপা হয় — নিয়মের একটাই তালিকা।
 *
 * ── ⛔ স্টক ────────────────────────────────────────────────────────────
 * এই সেবা [[StockService]]-কে চেনেই না। মাল নামে চালান নিশ্চিত হলে,
 * ফেরে বাতিল বা বিক্রয় ফেরতে। ধাপ কেবল বলে মালটা কোথায়।
 */
final class DeliveryStageService
{
    /**
     * ব্যর্থতার কারণ কোন তালিকা থেকে বাছা যায়।
     *
     * ⓘ `delivery_failure` নিজের প্রসঙ্গ — [[ReasonCode::CONTEXTS]]-এ যোগ
     * হলে ডিপো আলাদা তালিকা রাখতে পারে ("দোকান বন্ধ", "ক্রেতা নেননি")।
     * ততদিন বিক্রয় ফেরত ও বাতিলের কারণগুলোই চলে — ওগুলোই সবচেয়ে কাছের।
     */
    public const REASON_CONTEXTS = ['delivery_failure', ReasonCode::SALES_RETURN, ReasonCode::CANCELLATION];

    // ── হাতের বদল ──────────────────────────────────────────────────────

    /**
     * হাতে একটা ধাপ বসানো।
     *
     * @param  array<string, mixed>  $data  note · reason_code_id · receiver_name · receiver_phone · lines
     */
    public function move(DeliveryChallan $challan, string $to, array $data = []): DeliveryState
    {
        if (! in_array($to, DeliveryStage::ALL, true)) {
            throw ValidationException::withMessages([
                'stage' => __('sales::delivery.errors.unknown_stage'),
            ]);
        }

        return DB::transaction(function () use ($challan, $to, $data) {
            /*
             * ⚠️ সারিটা তালা দিয়ে পড়া — দুইজন একসাথে দুই বোতাম চাপলে
             * দুইজনেই "রওনা" দেখে দুই দিকে নিয়ে যেতেন, আর ইতিহাসে দুইটা
             * সারি একই "থেকে" দাবি করত।
             */
            $state = $this->ensure($challan, lock: true);
            $from = (string) $state->stage;

            if (! DeliveryStage::allows($from, $to, DeliveryStage::BY_HAND)) {
                throw ValidationException::withMessages([
                    'stage' => __('sales::delivery.errors.not_allowed', [
                        'from' => DeliveryStage::label($from),
                        'to' => DeliveryStage::label($to),
                    ]),
                ]);
            }

            /*
             * ⛔ চলতি ট্রিপে থাকা চালানের পথের খবর ট্রিপই দেয়।
             *
             * ⓘ হাতে "পৌঁছেছে" বসানো গেলে ট্রিপের সারি সন্ধ্যায় "ফেরত"
             * বলত, আর দুইটা উৎস দুই সত্য দেখাত — কোনটা ঠিক কেউ জানত না।
             */
            if (in_array($to, DeliveryStage::TRIP_OWNED, true)) {
                $trip = $this->activeTrip($challan);

                if ($trip !== null) {
                    throw ValidationException::withMessages([
                        'stage' => __('sales::delivery.errors.on_a_trip', ['trip' => $trip->document_no]),
                    ]);
                }
            }

            $note = $this->clean($data['note'] ?? null, 500, 'note');
            $reason = null;
            $receiver = null;
            $phone = null;
            $lines = [];

            if ($to === DeliveryStage::FAILED) {
                $reason = $this->reason($data['reason_code_id'] ?? null);

                /*
                 * ⛔ "পৌঁছায়নি" লিখতে কারণ লাগে — তালিকা থেকে, নাহলে লেখা।
                 *
                 * ⓘ ফেরত আসা মাল মানে কারো কিছু করার আছে; কারণ ছাড়া সারিটা
                 * পরে পড়ে কেউ বুঝতেন না কেন — [[ShipmentService::settle()]]-এর
                 * একই নিয়ম। ⚠️ ফাঁকা স্পেস কারণ নয়।
                 */
                if ($reason === null && $note === null) {
                    throw ValidationException::withMessages([
                        'note' => __('sales::delivery.errors.failed_needs_reason'),
                    ]);
                }
            }

            if (in_array($to, DeliveryStage::NEEDS_RECEIVER, true)) {
                $receiver = $this->clean($data['receiver_name'] ?? null, 191, 'receiver_name');
                $phone = $this->phone($data['receiver_phone'] ?? null);

                // ⭐ প্রমাণ — কে বুঝে নিলেন। নাম ছাড়া "পৌঁছেছে" একটা দাবি মাত্র।
                if ($receiver === null) {
                    throw ValidationException::withMessages([
                        'receiver_name' => __('sales::delivery.errors.receiver_required'),
                    ]);
                }
            }

            if ($to === DeliveryStage::PARTIALLY_DELIVERED) {
                $lines = $this->partialLines($challan, $data['lines'] ?? []);
            }

            return $this->write($state, $challan, $to, DeliveryStage::BY_HAND, [
                'note' => $note,
                'reason_code_id' => $reason?->id,
                'receiver_name' => $receiver,
                'receiver_phone' => $phone,
            ], $lines);
        });
    }

    /**
     * পর্দার বোতাম — এখন হাতে কোন ধাপগুলোতে যাওয়া যায়।
     *
     * ⓘ সেবার নিয়মের সাথে একই পরীক্ষা, যাতে পর্দা এমন বোতাম না দেখায়
     * যেটা চাপলে সেবা না বলবে।
     *
     * @return list<string>
     */
    public function manualChoices(DeliveryChallan $challan): array
    {
        $choices = DeliveryStage::manualNext((string) $this->ensure($challan)->stage);

        if ($this->activeTrip($challan) !== null) {
            $choices = array_values(array_diff($choices, DeliveryStage::TRIP_OWNED));
        }

        return $choices;
    }

    /** চালানটা এখন কোন চলতি ট্রিপে (খসড়া বা পথে) আছে। */
    public function activeTrip(DeliveryChallan $challan): ?Shipment
    {
        return ShipmentLine::query()
            ->where('delivery_challan_id', $challan->id)
            ->whereHas('shipment', fn ($q) => $q->whereIn('status',
                [DocumentStatus::DRAFT, DocumentStatus::CONFIRMED]))
            ->with('shipment')
            ->first()
            ?->shipment;
    }

    /**
     * এই চালানের ধাপ — না থাকলে তথ্য থেকে গুনে প্রথম সারিটা বসিয়ে।
     *
     * ⓘ মাইগ্রেশন পুরনো সব চালানের সারি বসায়; এটা কেবল নিরাপত্তার জাল —
     * যেমন ট্রেইট বসার আগে তৈরি হওয়া চালান।
     */
    public function ensure(DeliveryChallan $challan, bool $lock = false): DeliveryState
    {
        $state = DeliveryState::query()
            ->where('delivery_challan_id', $challan->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();

        if ($state !== null) {
            return $state;
        }

        $trip = ShipmentLine::query()
            ->where('delivery_challan_id', $challan->id)
            // ⓘ বেরোনো ট্রিপ — পথে বা সম্পন্ন; তালিকাটা একটাই (DocumentStatus::POSTED)
            ->whereHas('shipment', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED))
            ->with('shipment')
            ->get()
            ->sortByDesc(fn (ShipmentLine $l) => sprintf('%012d-%012d',
                $l->shipment?->dispatched_at?->getTimestamp() ?? 0, $l->shipment_id))
            ->first();

        return $this->write(null, $challan, DeliveryStage::derive(
            (string) $challan->status,
            $trip?->shipment?->status,
            $trip?->outcome,
        ), DeliveryStage::BY_BACKFILL, [
            'shipment_id' => $trip?->shipment_id,
            'note' => $this->trimmed($trip?->outcome_note, 500),
        ]);
    }

    /**
     * ইতিহাস — পুরনো আগে, পর্দার সময়রেখার ক্রমে।
     *
     * ⚠️ সব সম্পর্ক আগেই টানা — local-এ `preventLazyLoading` চালু, আর
     * পর্দায় লেজি পড়া মানে ঠিক সময়রেখাটাই ৫০০ দিত।
     *
     * @return Collection<int, DeliveryEvent>
     */
    /**
     * তালিকার জন্য একবারে — চালান → সারাংশ ([[DeliveryStage::summary()]])।
     *
     * ⓘ এক প্রশ্নে পুরো পাতা; সারি ধরে প্রশ্ন করলে পঞ্চাশ সারির পাতায়
     * পঞ্চাশটা প্রশ্ন। ধাপের সারি না থাকলে (পুরনো চালান) চালানের অবস্থা
     * থেকে পড়া হয়: নিশ্চিত মানে অপেক্ষায়, বাতিল মানে বাতিল।
     *
     * @param  iterable<DeliveryChallan>  $challans
     * @return array<int, ?string>  চালানের id → সারাংশ
     */
    public function summaries(iterable $challans): array
    {
        $byId = collect($challans)->keyBy('id');

        if ($byId->isEmpty()) {
            return [];
        }

        $stages = DeliveryState::query()
            ->whereIn('delivery_challan_id', $byId->keys())
            ->pluck('stage', 'delivery_challan_id');

        return $byId->map(fn (DeliveryChallan $c) => DeliveryStage::summary(
            $stages[$c->id] ?? match ($c->status) {
                DocumentStatus::CANCELLED => DeliveryStage::CANCELLED,
                DocumentStatus::DRAFT => DeliveryStage::PENDING,
                default => DeliveryStage::ALLOCATED,
            },
        ))->all();
    }

    /**
     * বিলের তালিকার জন্য — বিলের চালানগুলো সব পৌঁছালে "ডেলিভার্ড", নাহলে অপেক্ষায়।
     *
     * ⓘ বাতিল চালান গোনা হয় না — বাতিলের পরে নতুন চালানে মাল গেলে সেটাই কথা।
     * ⚠️ কোনো চালান না থাকলে (বিলটা চালান ছাড়া) কোনো কথা নেই — '—'।
     *
     * @param  iterable<int>  $invoiceIds
     * @return array<int, ?string>  বিলের id → সারাংশ
     */
    public function invoiceSummaries(iterable $invoiceIds): array
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $pairs = DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            // ⓘ id-গুলো এই কোম্পানির পাতা থেকেই আসে, তবু কাঁচা কোয়েরি নিজে বলে কোন কোম্পানি
            ->where('c.company_id', CompanyContext::id())
            ->whereIn('il.sales_invoice_id', $ids)
            ->where('c.status', '<>', DocumentStatus::CANCELLED)
            ->select('il.sales_invoice_id', 'c.id')
            ->distinct()
            ->get();

        $challans = DeliveryChallan::query()->whereIn('id', $pairs->pluck('id')->unique())->get(['id', 'status']);
        $summary = $this->summaries($challans);

        $out = [];

        foreach ($pairs->groupBy('sales_invoice_id') as $invoiceId => $rows) {
            $each = $rows->map(fn ($r) => $summary[$r->id] ?? null)->filter()->unique()->values();

            $out[(int) $invoiceId] = match (true) {
                $each->isEmpty() => null,
                $each->every(fn ($s) => $s === 'delivered') => 'delivered',
                $each->contains('failed') => 'failed',
                $each->contains('partial') => 'partial',
                default => 'awaiting',
            };
        }

        return $out;
    }

    public function timeline(DeliveryChallan $challan): Collection
    {
        return DeliveryEvent::query()
            ->where('delivery_challan_id', $challan->id)
            ->with(['creator', 'reasonCode', 'shipment', 'lines.challanLine.product'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * ট্যাবের গোনা — ধাপ ধরে, আর "হাতে থাকা কাজ" মিলিয়ে।
     *
     * ⓘ `whereHas('challan')` — চালানের নিজের শাখা-ছাঁকনি এখানেও খাটে, তাই
     * এক শাখার লোক অন্য শাখার চালান গুনতে পান না।
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $byStage = DeliveryState::query()
            ->whereHas('challan')
            ->selectRaw('stage, COUNT(*) as aggregate')
            ->groupBy('stage')
            ->pluck('aggregate', 'stage');

        $counts = ['open' => 0];

        foreach (DeliveryStage::ALL as $stage) {
            $counts[$stage] = (int) ($byStage[$stage] ?? 0);

            if (in_array($stage, DeliveryStage::OPEN, true)) {
                $counts['open'] += $counts[$stage];
            }
        }

        return $counts;
    }

    /** @return Collection<int, ReasonCode> */
    public function reasons(): Collection
    {
        return ReasonCode::query()
            ->active()
            ->whereIn('context', self::REASON_CONTEXTS)
            ->orderBy('code')
            ->get();
    }

    // ── কাগজ থেকে আসা বদল ([[TellsTheDeliveryStage]]) ──────────────────

    /** নতুন চালান — অপেক্ষায়। */
    public function challanWritten(DeliveryChallan $challan): void
    {
        $this->follow($challan, DeliveryStage::derive((string) $challan->status), DeliveryStage::BY_CHALLAN);
    }

    /** চালান নিশ্চিত → বরাদ্দ; বাতিল → বাতিল। */
    public function challanChanged(DeliveryChallan $challan): void
    {
        if (! $challan->wasChanged('status')) {
            return;
        }

        match ($challan->status) {
            DocumentStatus::CONFIRMED => $this->follow($challan, DeliveryStage::ALLOCATED, DeliveryStage::BY_CHALLAN),
            DocumentStatus::CANCELLED => $this->follow($challan, DeliveryStage::CANCELLED, DeliveryStage::BY_CHALLAN, [
                'note' => $this->trimmed($challan->cancel_reason, 500),
            ]),
            default => null,
        };
    }

    /**
     * ট্রিপ বেরোল → রওনা; পথে থাকা ট্রিপ বাতিল → গাড়িতে ওঠার আগের ধাপ।
     *
     * ⚠️ বেরোনোর সময় নিয়ম ভাঙলে (যেমন হাতে "পৌঁছেছে" বসানো চালান গাড়িতে)
     * ট্রিপটাই আটকায় — ঘটনাটা [[ShipmentService::dispatch()]]-এর লেনদেনের
     * ভেতরে, তাই গোটা বেরোনোটা ফিরে যায়, অর্ধেক কিছু থাকে না।
     */
    public function tripChanged(Shipment $shipment): void
    {
        if (! $shipment->wasChanged('status')) {
            return;
        }

        $was = (string) $shipment->getOriginal('status');

        if ($shipment->status === DocumentStatus::CONFIRMED && $was === DocumentStatus::DRAFT) {
            foreach ($this->challansOn($shipment) as $challan) {
                $current = (string) $this->ensure($challan, lock: true)->stage;

                // ⛔ নতুন ট্রিপ কেবল গাড়িতে ওঠার আগের ধাপ থেকে — [[DeliveryStage::DISPATCHABLE]]
                if (! in_array($current, DeliveryStage::DISPATCHABLE, true)) {
                    throw ValidationException::withMessages([
                        'lines' => __('sales::delivery.errors.cannot_travel', [
                            'no' => $challan->document_no,
                            'stage' => DeliveryStage::label($current),
                        ]),
                    ]);
                }

                $this->follow($challan, DeliveryStage::DISPATCHED, DeliveryStage::BY_SHIPMENT, [
                    'shipment_id' => $shipment->id,
                ], strict: true);
            }

            return;
        }

        if ($shipment->status === DocumentStatus::CANCELLED && $was === DocumentStatus::CONFIRMED) {
            foreach ($this->challansOn($shipment) as $challan) {
                $state = $this->ensure($challan);

                // চালকের কথা বসে গেছে এমন সারি বদলায় না — ওটা ঘটে যাওয়া খবর
                if ($state->stage !== DeliveryStage::DISPATCHED) {
                    continue;
                }

                $before = DeliveryEvent::query()
                    ->where('delivery_challan_id', $challan->id)
                    ->where('shipment_id', $shipment->id)
                    ->where('to_stage', DeliveryStage::DISPATCHED)
                    ->orderByDesc('id')
                    ->value('from_stage');

                $back = in_array($before, [DeliveryStage::PICKING, DeliveryStage::PACKED], true)
                    ? $before
                    : DeliveryStage::ALLOCATED;

                $this->follow($challan, $back, DeliveryStage::BY_SHIPMENT, [
                    'shipment_id' => $shipment->id,
                    'note' => $this->trimmed($shipment->cancel_reason, 500),
                ]);
            }
        }
    }

    /** চালক বললেন কী হলো — ট্রিপ পথে থাকতেই। */
    public function tripLineChanged(ShipmentLine $line): void
    {
        if (! $line->wasChanged('outcome')) {
            return;
        }

        $line->loadMissing(['shipment', 'challan']);
        $shipment = $line->shipment;

        if ($shipment === null || $shipment->status !== DocumentStatus::CONFIRMED) {
            return;
        }

        $challan = $line->challan;

        if ($challan === null) {
            return;
        }

        $this->follow($challan, DeliveryStage::fromOutcome((string) $line->outcome), DeliveryStage::BY_SHIPMENT, [
            'shipment_id' => $shipment->id,
            'note' => $this->trimmed($line->outcome_note, 500),
        ]);
    }

    // ── ভেতরের কাজ ──────────────────────────────────────────────────────

    /**
     * কাগজের খবরে ধাপ বদলানো।
     *
     * ⓘ `strict` না হলে নিয়মের বাইরের খবর চুপচাপ বাদ — লগে লেখা থাকে।
     * ⚠️ কারণ: চালকের সারি বসানো ([[ShipmentService::settle()]]) লেনদেনের
     * বাইরে; এখানে ছুড়লে সারিটা বসে যেত অথচ পর্দা ত্রুটি দেখাত। যে পথগুলো
     * বাস্তবে আসে সেগুলো সবই তালিকায় বৈধ — বাদ পড়ে কেবল বাতিল চালানের
     * পরের খবর।
     *
     * @param  array<string, mixed>  $extras
     */
    private function follow(DeliveryChallan $challan, string $to, string $source, array $extras = [], bool $strict = false): void
    {
        $state = DeliveryState::query()
            ->where('delivery_challan_id', $challan->id)
            ->lockForUpdate()
            ->first();

        if ($state === null) {
            $this->write(null, $challan, $to, $source, $extras);

            return;
        }

        $from = (string) $state->stage;

        if ($from === $to) {
            return;
        }

        if (! DeliveryStage::allows($from, $to, $source)) {
            if ($strict) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::delivery.errors.cannot_travel', [
                        'no' => $challan->document_no,
                        'stage' => DeliveryStage::label($from),
                    ]),
                ]);
            }

            Log::warning('delivery stage: an event outside the rules was ignored', [
                'challan' => $challan->id, 'from' => $from, 'to' => $to, 'source' => $source,
            ]);

            return;
        }

        $this->write($state, $challan, $to, $source, $extras);
    }

    /**
     * ইতিহাসের সারি ও এখনকার ধাপ — একসাথে।
     *
     * @param  array<string, mixed>  $extras
     * @param  array<int, string>  $lines  চালানের সারি → কতটা গেল
     */
    private function write(?DeliveryState $state, DeliveryChallan $challan, string $to, string $source, array $extras = [], array $lines = []): DeliveryState
    {
        return DB::transaction(function () use ($state, $challan, $to, $source, $extras, $lines) {
            $now = now();

            $event = DeliveryEvent::create([
                'company_id' => $challan->company_id,
                'delivery_challan_id' => $challan->id,
                'from_stage' => $state?->stage,
                'to_stage' => $to,
                'source' => $source,
                'shipment_id' => $extras['shipment_id'] ?? null,
                'reason_code_id' => $extras['reason_code_id'] ?? null,
                'note' => $extras['note'] ?? null,
                'receiver_name' => $extras['receiver_name'] ?? null,
                'receiver_phone' => $extras['receiver_phone'] ?? null,
                'occurred_at' => $now,
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $lineId => $qty) {
                DeliveryEventLine::create([
                    'company_id' => $challan->company_id,
                    'delivery_event_id' => $event->id,
                    'delivery_challan_line_id' => $lineId,
                    'delivered_qty' => $qty,
                ]);
            }

            /*
             * ⭐ গেট পাস রওনার মুহূর্তে — মালিকের নকশা, ২৮ সেপ্টেম্বর ২০২৬ (রাত)।
             * ⓘ ট্রিপ বেরোলে আর হাতে "রওনা" বসালে দুইটাই এই পথে আসে — তাই কোনো রওনা কাগজ ছাড়া
             * বেরোয় না ([[GatePassService::issueFor()]])। একই লেনদেনে: গেট পাস না হলে রওনাও না।
             */
            if ($this->leavesTheGate($state?->stage, $to)) {
                /*
                 * ⭐ মাল বের হলেই বিল — মালিক, ২৯ সেপ্টেম্বর ২০২৬: *"ডেলিভারি বের হলেই ইনভয়েজ"*
                 * ([[DispatchBill]])। ⓘ গেট পাসের আগে, একই লেনদেনে: বিল আটকালে (বাকির দেয়াল)
                 * গেট পাসও নয়, রওনাও নয়। আগে থেকে বিল থাকলে কিছুই করে না।
                 */
                app(DispatchBill::class)->forDispatch($challan);
                app(GatePassService::class)->issueFor($challan, $event);
            }

            if ($state === null) {
                return DeliveryState::create([
                    'company_id' => $challan->company_id,
                    'delivery_challan_id' => $challan->id,
                    'stage' => $to,
                    'stage_at' => $now,
                    'updated_by' => auth()->id(),
                ]);
            }

            $state->update([
                'stage' => $to,
                'stage_at' => $now,
                'updated_by' => auth()->id(),
            ]);

            return $state;
        });
    }

    /**
     * আংশিক ডেলিভারির পরিমাণ — চালানের নিজের সারি ধরে।
     *
     * ⛔ তিনটা ফাঁদ, তিনটাই আটকানো:
     *   • অন্য চালানের সারির নম্বর — এই চালানের না হলে না।
     *   • ঋণাত্মক বা চালানের চেয়ে বেশি — ক্রেতা যা পাননি তা "পেয়েছেন" নয়।
     *   • সব শূন্য বা সব পুরো — ওটা আংশিক নয়; "পৌঁছায়নি" বা "পৌঁছেছে" বাছুন।
     *
     * @return array<int, string>
     */
    private function partialLines(DeliveryChallan $challan, mixed $given): array
    {
        if (! is_array($given)) {
            $given = [];
        }

        $challanLines = DeliveryChallanLine::query()
            ->where('delivery_challan_id', $challan->id)
            ->get()
            ->keyBy('id');

        foreach (array_keys($given) as $lineId) {
            if (! $challanLines->has((int) $lineId)) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::delivery.errors.line_not_on_challan'),
                ]);
            }
        }

        $result = [];
        $any = false;
        $allFull = true;

        foreach ($challanLines as $id => $line) {
            $value = $given[$id] ?? '0';
            $raw = is_scalar($value) ? trim((string) $value) : 'x';
            $raw = $raw === '' ? '0' : $raw;

            if (preg_match('/^\d{1,14}(\.\d{1,4})?$/', $raw) !== 1) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::delivery.errors.qty_invalid'),
                ]);
            }

            $shipped = (string) $line->delivered_qty;

            if (bccomp($raw, $shipped, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::delivery.errors.qty_over', ['line' => $line->line_no]),
                ]);
            }

            if (bccomp($raw, '0', 4) > 0) {
                $any = true;
            }

            if (bccomp($raw, $shipped, 4) !== 0) {
                $allFull = false;
            }

            $result[(int) $id] = bcadd($raw, '0', 4);
        }

        if (! $any) {
            throw ValidationException::withMessages([
                'lines' => __('sales::delivery.errors.partial_needs_something'),
            ]);
        }

        if ($allFull) {
            throw ValidationException::withMessages([
                'lines' => __('sales::delivery.errors.partial_is_full'),
            ]);
        }

        return $result;
    }

    private function reason(mixed $id): ?ReasonCode
    {
        if (blank($id)) {
            return null;
        }

        /*
         * ⚠️ কোম্পানির ছাঁকনি মডেলেরই ([[BelongsToCompany]]), আর প্রসঙ্গও মাপা —
         * ছাড়ের কারণ বা অন্য কোম্পানির কারণের নম্বর পাঠালে না।
         */
        $reason = ReasonCode::query()
            ->active()
            ->whereIn('context', self::REASON_CONTEXTS)
            ->whereKey((int) $id)
            ->first();

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason_code_id' => __('sales::delivery.errors.unknown_reason'),
            ]);
        }

        return $reason;
    }

    private function phone(mixed $value): ?string
    {
        $phone = $this->trimmed($value, 64);

        if ($phone === null) {
            return null;
        }

        if (preg_match('/^\+?[0-9][0-9\- ]{5,19}$/', $phone) !== 1) {
            throw ValidationException::withMessages([
                'receiver_phone' => __('sales::delivery.errors.phone_invalid'),
            ]);
        }

        return $phone;
    }

    /**
     * মাল কি এই ধাপে গেট পেরোল?
     *
     * ⓘ রওনা তো বটেই। ⚠️ আর গুদাম থেকে সোজা "পৌঁছেছে" (ক্রেতার নিজের গাড়ি, বা হাতে হাতে) —
     * তখন রওনার ধাপ আসে না, অথচ মালটা গেট পেরোচ্ছে; এখানে না ধরলে ঐ পথে বিলও হত না, গেট
     * পাসও না। ⛔ রওনার পরে পৌঁছানো নয় — সেখানে গেট আগেই পেরিয়েছে।
     */
    private function leavesTheGate(?string $from, string $to): bool
    {
        if ($to === DeliveryStage::DISPATCHED) {
            return true;
        }

        return in_array($to, DeliveryStage::NEEDS_RECEIVER, true)
            && in_array($from, [DeliveryStage::ALLOCATED, DeliveryStage::PICKING, DeliveryStage::PACKED], true);
    }

    /** ফাঁকা হলে null; সীমার বেশি হলে না। */
    private function clean(mixed $value, int $max, string $field): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([
                $field => __('sales::delivery.errors.too_long', ['max' => $max]),
            ]);
        }

        return $text;
    }

    /** কাগজ থেকে আসা লেখা — ছোঁড়ে না, কেটে রাখে। */
    private function trimmed(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** @return Collection<int, DeliveryChallan> */
    private function challansOn(Shipment $shipment): Collection
    {
        return ShipmentLine::query()
            ->where('shipment_id', $shipment->id)
            ->with('challan')
            ->orderBy('line_no')
            ->get()
            ->pluck('challan')
            ->filter()
            ->values();
    }
}
