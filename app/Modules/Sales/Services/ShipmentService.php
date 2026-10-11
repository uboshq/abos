<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * শিপমেন্ট — গাড়ি বেরোনো ও ফিরে আসা।
 *
 * ── এই সেবাটা স্টকে হাত দেয় না, আর সেটাই এর সবচেয়ে গুরুত্বপূর্ণ নিয়ম ──
 * মাল চালানে বেরিয়ে গেছে। ট্রিপ আবার বের করলে দুইবার বেরোত; ফেরত আসা
 * মাল এখানে ঢোকালে লট ভুল হত, কারণ কোন লট থেকে কী বেরিয়েছিল তা জানে
 * চালানের চলাচলগুলো, ট্রিপ নয়।
 *
 * তাই ফেরানোর কাজ একটাই পথে — চালান বাতিল বা বিক্রয় ফেরত — আর ট্রিপ
 * শুধু **বন্ধ হতে অস্বীকার করে** যতক্ষণ সেটা হয়নি। অস্বীকার করাটাই
 * এখানকার একমাত্র বল, আর ওটুকুই যথেষ্ট: গাড়ি ফিরেছে অথচ দুই চালানের
 * মাল খাতায় এখনো ক্রেতার কাছে — এই অবস্থাটাই আজ কেউ ধরে না।
 */
final class ShipmentService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $challanIds
     */
    public function create(array $data, array $challanIds): Shipment
    {
        return DB::transaction(function () use ($data, $challanIds) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? null);
            $documentNo = $this->numbers->next('TRP');

            $shipment = Shipment::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
                'document_no' => $documentNo,
                'trx_date' => $trxDate->toDateString(),
                'warehouse_id' => $warehouse->id,
                ...$this->crew($data),
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($shipment, $challanIds);
            $this->stampFare($shipment, $data);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => Shipment::drillSourceType(),
                    'source_id' => $shipment->id,
                ]);

            return $shipment->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $challanIds
     */
    public function update(Shipment $shipment, array $data, array $challanIds): Shipment
    {
        $this->assertEditable($shipment);

        return DB::transaction(function () use ($shipment, $data, $challanIds) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $shipment->trx_date);
            $warehouse = $this->resolveWarehouse($data['warehouse_id'] ?? $shipment->warehouse_id);

            $shipment->update([
                'trx_date' => $trxDate->toDateString(),
                'warehouse_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id ?? $shipment->branch_id,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
                ...$this->crew($data),
            ]);

            $this->replaceLines($shipment, $challanIds);
            $this->stampFare($shipment, $data);

            return $shipment->fresh(['lines']);
        });
    }

    /**
     * ⭐ ট্রিপের ভাড়া — অঙ্ক, বাহক, এখন বা পরে, খাত (মালিক, ৭ অক্টোবর ২০২৬; সিদ্ধান্ত ঘ; [[FarePayment::stamp()]])।
     *
     * ⓘ ফর্ম ঘরগুলো পাঠালে তবেই; না পাঠালে (পুরনো দরজা) কিছুই বদলায় না। ⓘ ভাড়ার ঘর fillable নয় — এখানে forceFill।
     * ⛔ ভাড়াওয়ালা ট্রিপে নিজের ভাড়াওয়ালা চালান নয় ([[FarePayment::assertOneFarePerTrip()]])।
     *
     * @param  array<string, mixed>  $data
     */
    private function stampFare(Shipment $shipment, array $data): void
    {
        if (! array_key_exists('fare_when', $data) && ! array_key_exists('transport_cost', $data)) {
            return;
        }

        $shipment->forceFill([
            'transport_cost' => is_numeric($data['transport_cost'] ?? null) ? (string) $data['transport_cost'] : null,
            'carrier_id' => ($data['carrier_id'] ?? null) ?: null,
        ])->save();

        $fares = app(FarePayment::class);
        $fares->stamp($shipment->fresh(), $data);
        $fares->assertOneFarePerTrip($shipment->fresh(), $this->challansOf($shipment));
    }

    /** @return \Illuminate\Support\Collection<int, DeliveryChallan> */
    private function challansOf(Shipment $shipment): \Illuminate\Support\Collection
    {
        return DeliveryChallan::query()->whereIn('id', ShipmentLine::query()->where('shipment_id', $shipment->id)->select('delivery_challan_id'))->get();
    }

    /**
     * গাড়ি বেরোল।
     *
     * ── কেন এখানে আবার সব যাচাই হয় ──────────────────────────────────
     * খসড়াটা সকাল ছয়টায় লেখা হতে পারে আর গাড়ি বেরোয় আটটায়। ওই দুই
     * ঘণ্টায় একটা চালান বাতিল হয়ে যেতে পারে, বা অন্য কেউ সেটাকে অন্য
     * গাড়িতে তুলে দিতে পারেন। বেরোনোর মুহূর্তের সত্যটাই কাগজে থাকা
     * উচিত, লেখার মুহূর্তেরটা নয়।
     */
    public function dispatch(Shipment $shipment): Shipment
    {
        if ($shipment->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_dispatches', ['no' => $shipment->document_no]),
            ]);
        }

        $shipment->loadMissing('lines.challan');

        if ($shipment->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.shipment_needs_a_challan'),
            ]);
        }

        return DB::transaction(function () use ($shipment) {
            $ids = $shipment->lines->pluck('delivery_challan_id')->all();

            $this->assertChallansCanTravel($ids, $shipment);

            /*
             * ⭐ ট্রাক রওনা — ভাড়াও খাতায়, একই লেনদেনে (সিদ্ধান্ত ঘ; [[FarePayment::bookTrip()]])। ⓘ সারি তালা দিয়ে আসল
             * অবস্থা — দুই চাপে দুইবার রওনা আর দুইবার ভাড়া নয়; খসড়ার পরে কোনো চালানে ভাড়া বসে থাকলে এখানে আবার ধরা পড়ে।
             */
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($locked->status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.only_draft_dispatches', ['no' => $shipment->document_no]),
                ]);
            }

            $fares = app(FarePayment::class);
            $fares->assertOneFarePerTrip($locked, $this->challansOf($locked));

            $locked->update([
                'status' => DocumentStatus::CONFIRMED,
                'dispatched_at' => now(),
            ]);

            $fares->bookTrip($locked->fresh());

            return $locked->fresh(['lines']);
        });
    }

    /**
     * একটা সারির হিসাব বুঝে নেওয়া — চালক যা বললেন।
     */
    public function settle(ShipmentLine $line, string $outcome, ?string $note = null): ShipmentLine
    {
        if (! in_array($outcome, ShipmentLine::OUTCOMES, true)) {
            throw ValidationException::withMessages([
                'outcome' => __('sales::validation.unknown_outcome'),
            ]);
        }

        $shipment = $line->shipment;

        if ($shipment->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'outcome' => __('sales::validation.only_a_dispatched_trip_settles'),
            ]);
        }

        /*
         * "ফেরত এসেছে" লিখতে গেলে কারণটাও লিখতে হয়।
         *
         * মাল ফেরা মানে কারও না কারও কিছু একটা করার আছে — দোকান বন্ধ
         * ছিল, ক্রেতা নেননি, ঠিকানা ভুল। কারণ ছাড়া সারিটা পরে কেউ
         * পড়ে বুঝতে পারতেন না কেন ওই দিনের মাল ফিরেছিল।
         */
        if (in_array($outcome, ShipmentLine::NEEDS_GOODS_BACK, true) && trim((string) $note) === '') {
            throw ValidationException::withMessages([
                'outcome_note' => __('sales::validation.a_return_needs_a_reason'),
            ]);
        }

        $line->update([
            'outcome' => $outcome,
            'outcome_note' => $note,
            'settled_at' => $outcome === ShipmentLine::PENDING ? null : now(),
        ]);

        return $line->fresh();
    }

    /**
     * গাড়ি ফিরল ও হিসাব মিলল।
     *
     * ── কেন এখানে আটকানো হয় ─────────────────────────────────────────
     * "ফেরত এসেছে" লেখা মানে মালটা এখন গুদামে, অথচ খাতায় সেটা ক্রেতার
     * কাছে। দুইটা একসাথে সত্যি হতে পারে না। ট্রিপ বন্ধ করে দিলে ওই
     * অমিলটা চিরকালের জন্য চাপা পড়ে যেত — আর মাসের শেষে মজুদ মেলাতে
     * গিয়ে কেউ একজন খুঁজে বের করার চেষ্টা করতেন কোথায় কী হয়েছিল।
     *
     * @param  array<string, mixed>  $data
     */
    public function close(Shipment $shipment, array $data = []): Shipment
    {
        if ($shipment->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_a_dispatched_trip_closes', ['no' => $shipment->document_no]),
            ]);
        }

        $shipment->loadMissing('lines.challan');

        $unsettled = $shipment->lines->reject->isSettled();

        if ($unsettled->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.every_challan_needs_an_outcome', [
                    'count' => $unsettled->count(),
                ]),
            ]);
        }

        $stranded = $shipment->lines
            ->filter->needsGoodsBack()
            ->reject(fn (ShipmentLine $line) => $this->goodsAreBackInTheBooks($line));

        if ($stranded->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.goods_are_not_back_in_the_books', [
                    'documents' => $stranded->map(fn (ShipmentLine $l) => $l->challan?->document_no)
                        ->filter()->implode(', '),
                ]),
            ]);
        }

        $closing = $data['closing_km'] ?? null;

        $this->assertMeterMovedForward($shipment, $closing);

        $shipment->update([
            'status' => DocumentStatus::CLOSED,
            'returned_at' => now(),
            'closing_km' => $closing,
            'narration' => $data['narration'] ?? $shipment->narration,
        ]);

        return $shipment->fresh(['lines']);
    }

    /**
     * ট্রিপ বাতিল — গাড়ি বেরোয়নি, বা ভুল কাগজ।
     *
     * চালানগুলোর কিছুই বদলায় না: ট্রিপ কখনো তাদের অবস্থা বদলায়নি, তাই
     * ফেরানোরও কিছু নেই। বাতিল হলে চালানগুলো আবার মুক্ত, অন্য গাড়িতে
     * তোলা যায়।
     */
    public function cancel(Shipment $shipment, string $reason): Shipment
    {
        if ($shipment->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $shipment->document_no]),
            ]);
        }

        if ($shipment->status === DocumentStatus::CLOSED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.a_finished_trip_does_not_cancel', ['no' => $shipment->document_no]),
            ]);
        }

        /*
         * ⭐ ভাড়াও ফেরে — EV বাতিল বা দেনার দাখিলা উল্টো ([[FarePayment::undoTrip()]], ১০ অক্টোবর ২০২৬)। ⓘ টাকা নড়ে,
         * তাই সারি তালা দিয়ে আসল অবস্থা আবার দেখা — দুই চাপে ভাড়া দুইবার উল্টায় না ([[EveryMoneyActionLocksItsRowTest]])।
         */
        return DB::transaction(function () use ($shipment, $reason) {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if (in_array($locked->status, [DocumentStatus::CANCELLED, DocumentStatus::CLOSED], true)) {
                throw ValidationException::withMessages([
                    'status' => __($locked->status === DocumentStatus::CANCELLED ? 'sales::validation.already_cancelled'
                        : 'sales::validation.a_finished_trip_does_not_cancel', ['no' => $locked->document_no]),
                ]);
            }

            $locked->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            app(FarePayment::class)->undoTrip($locked->fresh(), $reason);

            /*
             * ⛔ যে চালান গুদামের ধাপে ফিরল, তার এই ট্রিপের গেট পাসও বাতিল — Sales অডিট, ১০ অক্টোবর ২০২৬।
             * ⚠️ আগে পাসটা "জারি" থেকে যেত: গাড়ি বেরোয়নি, অথচ কাগজে মাল গেট পেরিয়েছে। ⓘ চালকের কথা বসে যাওয়া
             * চালান (পৌঁছেছে ইত্যাদি) ধাপে ফেরে না ([[DeliveryStageService::tripChanged()]]) — তাদের পাস থাকে, মাল সত্যিই গেছে।
             * ⓘ বিল আর মাল বের হওয়া থাকে: বিক্রিটা দাঁড়িয়ে, পরের ট্রিপে আবার হয় না ([[DispatchBill]], [[GoodsIssue]]
             * একবারই) — আর নিশ্চিত বিল বাতিল হয় না (মালিক, ২ অক্টোবর ২০২৬)। পরের রওনায় নতুন পাস (/2)।
             */
            $backHome = DeliveryState::query()
                ->whereIn('delivery_challan_id', $locked->lines()->pluck('delivery_challan_id'))
                ->whereIn('stage', DeliveryStage::DISPATCHABLE)
                ->pluck('delivery_challan_id');

            GatePass::query()
                ->where('shipment_id', $locked->id)
                ->where('status', GatePass::ISSUED)
                ->whereIn('delivery_challan_id', $backHome)
                ->get()
                ->each(fn (GatePass $pass) => app(GatePassService::class)->cancel($pass, $reason));

            return $locked->fresh(['lines']);
        });
    }

    // ── ভেতরের কাজ ──────────────────────────────────────────────────────

    /**
     * ফেরত আসা মাল খাতায় ফিরেছে কি না।
     *
     * দুইটাই বৈধ পথ, আর কোনটা লাগবে তা নির্ভর করে বিল কাটা হয়েছে কি
     * না তার উপর:
     *
     *   • বিল হয়নি — চালানটা বাতিল হলেই মাল গুদামে ফেরে, ঠিক যে লট
     *     থেকে বেরিয়েছিল সেই লটেই।
     *   • বিল হয়ে গেছে — চালান আর বাতিল হয় না (হলে বিক্রিটাই মুছে
     *     যেত), তাই পথটা বিক্রয় ফেরত।
     */
    private function goodsAreBackInTheBooks(ShipmentLine $line): bool
    {
        $challan = $line->challan;

        if ($challan === null) {
            return false;
        }

        if ($challan->status === DocumentStatus::CANCELLED) {
            return true;
        }

        $billed = SalesInvoiceLine::query()
            ->whereIn('delivery_challan_line_id',
                fn ($q) => $q->select('id')->from('sal_challan_lines')
                    ->where('delivery_challan_id', $challan->id))
            ->pluck('qty', 'id');

        if ($billed->isEmpty()) {
            return false;
        }

        /*
         * ⛔ এই চালানের বিলের সারিতেই ফেরত, আর যতটা ফেরার কথা ততটা — Sales অডিট, ১০ অক্টোবর ২০২৬।
         * ⚠️ আগে বিলের যেকোনো একটা পাকা ফেরতই "মাল ফিরেছে" বলত: পুরো চালান ফেরত এলেও এক পিসের ফেরত লিখে ট্রিপ
         * বন্ধ করা যেত, বাকিটা খাতায় ক্রেতার কাছে চিরকাল। ⓘ "পুরো ফেরত" = প্রতিটা সারির পুরো পরিমাণ; "কম পৌঁছেছে"
         * = কিছু একটা ফেরত, কতটা কম তা চালকের কথাতেই।
         */
        $returned = SalesReturnLine::query()
            ->whereIn('sales_invoice_line_id', $billed->keys())
            ->whereIn('sales_return_id', SalesReturn::query()->whereIn('status', DocumentStatus::POSTED)->select('id'))
            ->groupBy('sales_invoice_line_id')
            ->selectRaw('sales_invoice_line_id, SUM(qty) as returned')
            ->pluck('returned', 'sales_invoice_line_id');

        if ($line->outcome === ShipmentLine::SHORT) {
            return $returned->contains(fn ($qty) => bccomp((string) $qty, '0', 4) > 0);
        }

        return $billed->every(fn ($qty, $id) => bccomp((string) ($returned[$id] ?? '0'), (string) $qty, 4) >= 0);
    }

    /**
     * @param  list<int>  $challanIds
     */
    private function replaceLines(Shipment $shipment, array $challanIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $challanIds)));

        $this->assertChallansCanTravel($ids, $shipment);

        $shipment->lines()->delete();

        $lineNo = 1;

        foreach ($ids as $id) {
            ShipmentLine::create([
                'company_id' => $shipment->company_id,
                'shipment_id' => $shipment->id,
                'delivery_challan_id' => $id,
                'line_no' => $lineNo++,
                'outcome' => ShipmentLine::PENDING,
            ]);
        }
    }

    /**
     * চালানগুলো সত্যিই গাড়িতে তোলা যায় কি না।
     *
     * @param  list<int>  $ids
     */
    private function assertChallansCanTravel(array $ids, Shipment $shipment): void
    {
        if ($ids === []) {
            return;
        }

        /*
         * ⛔ চালানের সারি আটকে — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬।
         *
         * নিচের "অন্য ট্রিপে আছে কি না" প্রশ্নটা তালা ছাড়া হলে দুই ডেসপ্যাচার একই মুহূর্তে একই চালান দুই
         * গাড়িতে তুলতেন — দুজনেই "কোথাও নেই" দেখতেন। ⓘ এখন দ্বিতীয়জন তালার অপেক্ষায় থাকেন, তারপর
         * প্রথমজনের ট্রিপ দেখে ফেরেন। প্রতিটা ডাক লেনদেনের ভেতরে (create, update, dispatch)।
         * ⓘ `orderBy('id')` — দুই ট্রিপে একই চালানগুলো ভিন্ন ক্রমে এলেও তালা একই ক্রমে পড়ে, আটকাআটকি হয় না।
         * পাহারা: [[TwoTripsCannotTakeOneChallanTest]]।
         */
        $challans = DeliveryChallan::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($ids as $id) {
            $challan = $challans->get($id);

            if ($challan === null) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.challan_not_found'),
                ]);
            }

            /*
             * খসড়া চালান গাড়িতে ওঠে না।
             *
             * খসড়া মানে মাল এখনো তাকেই আছে — স্টক নামেনি। ওটা গাড়িতে
             * তুললে কাগজে মাল যাচ্ছে অথচ গুদামের হিসাবে কিছুই কমেনি।
             */
            if ($challan->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.only_confirmed_challans_travel', [
                        'no' => $challan->document_no,
                    ]),
                ]);
            }

            // ⛔ পাকা ফেরত আছে এমন চালান গাড়িতে ওঠে না — গভীর অডিট (৯); বেরোনোর মুহূর্তেও একই পাহারা
            app(DeliveryStageService::class)->assertNothingCameBack($challan, 'lines');

            /*
             * এক গুদাম, এক গাড়ি।
             *
             * দুই গুদামের মাল এক ট্রিপে থাকলে গাড়িটা কোথা থেকে বেরোল
             * তার উত্তর থাকত না, আর ফিরে আসা মাল কোন গুদামে ঢুকবে
             * সেটাও।
             */
            if ((int) $challan->warehouse_id !== (int) $shipment->warehouse_id) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.one_warehouse_one_trip', [
                        'no' => $challan->document_no,
                    ]),
                ]);
            }

            /*
             * একটা চালান একসাথে দুই গাড়িতে থাকতে পারে না।
             *
             * ── কেন শেষ হওয়া ট্রিপটা গোনা হয় না ─────────────────────
             * ফেরত আসা মাল পরদিন আবার পাঠানো হয়, আর সেটা স্বাভাবিক।
             * তাই বাধাটা কেবল **চলতি** ট্রিপে — খসড়া ও পথে থাকা।
             */
            $elsewhere = ShipmentLine::query()
                ->where('delivery_challan_id', $id)
                ->when($shipment->exists, fn ($q) => $q->where('shipment_id', '<>', $shipment->id))
                ->whereHas('shipment', fn ($q) => $q->whereIn('status',
                    [DocumentStatus::DRAFT, DocumentStatus::CONFIRMED]))
                ->with('shipment')
                ->first();

            if ($elsewhere !== null) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.challan_is_already_on_a_trip', [
                        'no' => $challan->document_no,
                        'trip' => $elsewhere->shipment?->document_no ?? '',
                    ]),
                ]);
            }
        }
    }

    /**
     * মিটার পিছিয়ে যেতে পারে না।
     *
     * ফেরার পাঠ বেরোনোর পাঠের চেয়ে কম মানে হয় কেউ ভুল টাইপ করেছেন,
     * নয় সংখ্যাটা অন্য গাড়ির। দুইটার কোনোটাই খাতায় থাকা উচিত নয়,
     * কারণ পরে ওই দুইটা বিয়োগ করেই ট্রিপের দূরত্ব বের করা হবে।
     */
    private function assertMeterMovedForward(Shipment $shipment, mixed $closing): void
    {
        if ($closing === null || $shipment->opening_km === null) {
            return;
        }

        if (bccomp((string) $closing, (string) $shipment->opening_km, 4) < 0) {
            throw ValidationException::withMessages([
                'closing_km' => __('sales::validation.the_meter_does_not_run_backwards'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function crew(array $data): array
    {
        return [
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'driver_name' => $data['driver_name'] ?? null,
            'driver_phone' => $data['driver_phone'] ?? null,
            'helper_name' => $data['helper_name'] ?? null,
            'carrier_name' => $data['carrier_name'] ?? null,
            'route_location_id' => $data['route_location_id'] ?? null,
            'opening_km' => $data['opening_km'] ?? null,
            'narration' => $data['narration'] ?? null,
        ];
    }

    private function assertEditable(Shipment $shipment): void
    {
        if ($shipment->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $shipment->document_no]),
            ]);
        }
    }

    private function resolveWarehouse(mixed $id): Warehouse
    {
        $warehouse = $id === null
            ? Warehouse::query()->where('is_default', true)->first()
            : Warehouse::query()->find($id);

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('sales::validation.warehouse_missing'),
            ]);
        }

        return $warehouse;
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->where('company_id', CompanyContext::id())
            ->where('starts_on', '<=', $date->toDateString())
            ->where('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('sales::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
