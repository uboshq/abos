<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * এক গুদাম থেকে আরেক গুদামে মাল সরানো।
 *
 * ── কেন দুই ধাপ, এক ধাপ নয় ──────────────────────────────────────────
 * মাল ট্রাকে ওঠে সকালে, পৌঁছায় বিকেলে — কখনো পরদিন। এক ধাপে করলে ট্রাক
 * ছাড়ার মুহূর্তেই গন্তব্য গুদামে মাল দেখাত, আর ওই গুদামের লোক এমন মাল
 * বেচার প্রতিশ্রুতি দিতেন যা তখনো রাস্তায়।
 *
 *     রওনা (confirmed) : উৎস গুদামে hold +qty
 *     পৌঁছাল (closed)  : উৎসে floor −qty ও hold −qty, গন্তব্যে floor +qty
 *
 * রওনার পর মালটা কাগজে এখনো উৎস গুদামেই — কিন্তু Hold-এ, তাই বিক্রয়যোগ্য
 * নয়। ট্রাক না পৌঁছালে মালটা হারায় না, একটা প্রশ্ন হয়ে ঝুলে থাকে, আর
 * সেটাই সত্যি।
 *
 * ── খতিয়ানে কিছু বসে না ─────────────────────────────────────────────
 * একই কোম্পানির দুই গুদামের মধ্যে মাল সরলে মজুদের মূল্য বদলায় না — একই
 * খাত, একই অঙ্ক। দাখিলা বসালে ডেবিট ও ক্রেডিট দুইটাই ১১২০-এ যেত,
 * অর্থাৎ একটা অর্থহীন সারি। শাখাভিত্তিক মজুদ খাত এলে এটা বদলাবে।
 */
final class StockTransferService
{
    use ReadsPackedQuantities;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly StockService $stock,
        private readonly DocumentApproval $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): StockTransfer
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('inventory::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());

            $from = $this->warehouse($data['from_warehouse_id'] ?? null);
            $to = $this->warehouse($data['to_warehouse_id'] ?? null);

            $this->assertDifferent($from, $to);

            $documentNo = $this->numbers->next('STF');

            $transfer = StockTransfer::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
                'document_no' => $documentNo,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'trx_date' => $trxDate->toDateString(),
                'status' => DocumentStatus::DRAFT,
                'narration' => $data['narration'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($transfer, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => StockTransfer::drillSourceType(),
                    'source_id' => $transfer->id,
                ]);

            return $transfer->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(StockTransfer $transfer, array $data, array $lines): StockTransfer
    {
        $this->assertEditable($transfer);

        return DB::transaction(function () use ($transfer, $data, $lines) {
            /*
             * ⛔ তালা দিয়ে অবস্থা আবার — Inventory অডিট ম৩, ৫ অক্টোবর ২০২৬। উপরের পাহারা হাতের কপি দেখে; পুরনো কপি দিয়ে
             * রওনা-হওয়া স্থানান্তরের সারি বদলানো যেত — ট্রাকে ১০, কাগজে ১০০।
             */
            if ($this->lockedStatus($transfer) !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.only_draft_edits', ['no' => $transfer->document_no]),
                ]);
            }

            $trxDate = Carbon::parse($data['trx_date'] ?? $transfer->trx_date);

            $from = $this->warehouse($data['from_warehouse_id'] ?? $transfer->from_warehouse_id);
            $to = $this->warehouse($data['to_warehouse_id'] ?? $transfer->to_warehouse_id);

            $this->assertDifferent($from, $to);

            $transfer->update([
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'trx_date' => $trxDate->toDateString(),
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($transfer, $lines);

            return $transfer->fresh(['lines']);
        });
    }

    /**
     * রওনা — মাল ট্রাকে উঠল।
     */
    public function dispatch(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.only_draft_dispatches', ['no' => $transfer->document_no]),
            ]);
        }

        $transfer->loadMissing(['lines.product', 'fromWarehouse']);

        if ($transfer->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('inventory::validation.no_lines')]);
        }

        /*
         * অনুমোদন লাগে কি না — ছক না বসালে আগের মতোই রওনা হয়।
         *
         * ⓘ অঙ্ক পাঠানো হয় না, আর সেটা ইচ্ছাকৃত: গুদাম বদলে টাকার
         * অঙ্ক নেই, মাল কেবল এক তাক থেকে আরেক তাকে যায়। অঙ্ক `null`
         * মানে সীমা যা-ই বসানো হোক, ছক থাকলে সই লাগবে
         * (`ApprovalFlow::appliesTo`) — এখানে ওটাই ঠিক, কারণ প্রশ্নটা
         * "কত টাকার" নয়, "মালটা সরানো উচিত কি না"।
         */
        // ⓘ সই মাপার মুহূর্তের কাগজ — তালার পরে আবার মেলানো হয় (অডিট ম৩, নিচে)
        $signedShape = app(DocumentFingerprint::class)->of($transfer);

        $this->approvals->assertClear(
            document: $transfer,
            module: 'inventory',
            action: 'transfer',
            field: 'status',
            reason: $transfer->narration,
        );

        return DB::transaction(function () use ($transfer, $signedShape) {
            /*
             * ⛔ সারি আটকে অবস্থা আবার পড়া — ২৯ সেপ্টেম্বর ২০২৬।
             * দুইবার চাপ দিলে দুইটা অনুরোধই বাইরের প্রশ্নে "খসড়া" দেখত,
             * আর মাল দুইবার আটকাত। মজুদের হিসাবও তাই আটকানোর পরে।
             */
            if ($this->lockedStatus($transfer) !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.only_draft_dispatches', ['no' => $transfer->document_no]),
                ]);
            }

            /*
             * ⭐ তালার পরে কাগজটা সই মাপার মুহূর্তের মতোই আছে তো (Inventory অডিট ম৩, ৫ অক্টোবর ২০২৬)।
             * ⛔ আগে সারি তোলা হত তালার আগে: সই মাপার পরে কেউ সারি বদলালে ট্রাকে যেত পুরনো পরিমাণ (কাগজে নতুনটা), আর সইটা
             * যে কাগজে ছিল তার বাইরের কাগজ পার হত। ⓘ ছাপ ডাটাবেজ থেকে নতুন করে পড়ে ([[DocumentFingerprint::of()]]); মিললে হাতের
             * সারি আর ডাটাবেজের সারি এক। সম্পাদনাও এখন এই সারিতে তালা নেয় ([[update()]]), তাই তালার পরে আর বদলায় না।
             */
            if (app(DocumentFingerprint::class)->of($transfer) !== $signedShape) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.transfer_changed_while_sending', ['no' => $transfer->document_no]),
                ]);
            }

            foreach ($transfer->lines as $line) {
                $this->assertEnoughAtSource($line->product, $transfer->fromWarehouse, (string) $line->qty);
            }

            foreach ($transfer->lines as $line) {
                /*
                 * মালটা উৎস গুদামেই থাকে, কিন্তু আটকে যায়।
                 *
                 * floor কমানো হয় না: ট্রাক ছাড়লেও মালটা এখনো কোম্পানির,
                 * আর গন্তব্যে পৌঁছায়নি। কমিয়ে দিলে ওই সময়টুকুতে মালটা
                 * কোথাও থাকত না — না উৎসে, না গন্তব্যে।
                 */
                $this->stock->move(
                    product: $line->product,
                    warehouse: $transfer->fromWarehouse,
                    sourceType: StockTransfer::STOCK_SOURCE,
                    sourceId: $transfer->id,
                    hold: (string) $line->qty,
                    reason: $this->onTheWay(),
                    date: $transfer->trx_date,
                    documentNo: $transfer->document_no,
                    narration: __('inventory::message.transfer_on_the_way', ['no' => $transfer->document_no]),
                    /*
                     * ⭐ "পাওয়া যায়" থেকে, তালাসহ — Inventory অডিট ম২, ৫ অক্টোবর ২০২৬।
                     * ⛔ উপরের পাহারা প্রতিটা সারি আলাদা মাপে, তালা ছাড়া: তাকে ৮, একই পণ্য দুই সারিতে ৬ + ৬ — দুটোই পাস, ট্রাকে
                     * ১২; দুটো স্থানান্তর একসাথে বেরোলে দুজনেই পুরো ৮ দেখত। ⓘ এখানে মাপা হয় এই লেনদেনের আগের সারির আটকানোসহ,
                     * আর অন্য লেনদেন তালায় অপেক্ষা করে ([[StockService::assertEnoughAvailable()]])।
                     */
                    fromAvailable: true,
                );
            }

            $transfer->update([
                'status' => DocumentStatus::CONFIRMED,
                'dispatched_at' => now(),
                // ⓘ কে পাঠালেন — "দুজনের কাজ" সুইচ গ্রহণে এটাই মেলায় (অডিট ম৪)
                'dispatched_by' => auth()->id(),
            ]);

            return $transfer->fresh(['lines']);
        });
    }

    /**
     * বুঝে নেওয়া — মাল পৌঁছাল।
     *
     * ── কেন পরিমাণ আবার লেখা যায় না ─────────────────────────────────
     * কম পৌঁছালে সেটা স্থানান্তরের সংশোধন নয়, একটা ঘাটতি — আর ঘাটতির
     * নিজের কাগজ আছে (স্টক সমন্বয়), যেখানে কারণ ও অনুমোদন দুইটাই বসে।
     * এখানে পরিমাণ বদলাতে দিলে পথে হারানো মাল নীরবে মিলিয়ে যেত।
     */
    public function receive(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.only_dispatched_receives', ['no' => $transfer->document_no]),
            ]);
        }

        $transfer->loadMissing(['lines.product', 'fromWarehouse', 'toWarehouse']);

        return DB::transaction(function () use ($transfer) {
            // ⛔ সারি আটকে আবার দেখা — নাহলে দুইবার চাপে মাল দুইবার সরত (২৯ সেপ্টেম্বর ২০২৬)
            if ($this->lockedStatus($transfer) !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.only_dispatched_receives', ['no' => $transfer->document_no]),
                ]);
            }

            $this->assertTwoPeople($transfer);

            foreach ($transfer->lines as $line) {
                /*
                 * উৎস ছাড়ল — তাক থেকেও, আটকানো থেকেও।
                 *
                 * issue() ব্যবহার করা হয় move() নয়, কারণ লট ধরা পণ্যে
                 * কোন লটটা যাচ্ছে সেটা এখানেও ঠিক করতে হয়। move() দিয়ে
                 * করলে লট ছাড়া মাল বেরোত: উৎসে লটের যোগফল আর মোট মজুদ
                 * আলাদা হয়ে যেত, আর গন্তব্যের মালটা "লট ধরা শুরুর আগের"
                 * বলে চিরকাল অবিক্রেয় থাকত।
                 */
                $left = $this->stock->issue(
                    product: $line->product,
                    warehouse: $transfer->fromWarehouse,
                    sourceType: StockTransfer::STOCK_SOURCE,
                    sourceId: $transfer->id,
                    qty: (string) $line->qty,
                    hold: bcmul((string) $line->qty, '-1', 4),
                    reason: $this->onTheWay(),
                    date: now(),
                    documentNo: $transfer->document_no,
                    narration: __('inventory::message.transfer_left', ['no' => $transfer->document_no]),
                );

                /*
                 * গন্তব্যে ঢুকল — উৎসে যে লট থেকে যতটা গেছে, ঠিক ততটাই।
                 *
                 * একটা লাইন একাধিক লট থেকে পূরণ হতে পারে, তাই গন্তব্যেও
                 * তত সারি। ট্রাকে যা উঠেছে আর যা নেমেছে এক জিনিস — লট
                 * ধরে ধরে।
                 */
                foreach ($left as $movement) {
                    $this->stock->move(
                        product: $line->product,
                        warehouse: $transfer->toWarehouse,
                        sourceType: StockTransfer::STOCK_SOURCE,
                        sourceId: $transfer->id,
                        floor: bcmul((string) $movement->floor_change, '-1', 4),
                        date: now(),
                        documentNo: $transfer->document_no,
                        narration: __('inventory::message.transfer_arrived', ['no' => $transfer->document_no]),
                        batch: $movement->batch,
                    );
                }
            }

            $this->moveTheValueBetweenBranches($transfer);

            $transfer->update([
                'status' => DocumentStatus::CLOSED,
                'received_at' => now(),
            ]);

            return $transfer->fresh(['lines']);
        });
    }

    /**
     * বাতিল — কেবল পৌঁছানোর আগে।
     *
     * পৌঁছে যাওয়ার পর বাতিল করা যায় না: মালটা সত্যিই অন্য গুদামে চলে
     * গেছে, আর কাগজ ছিঁড়ে সেটা ফেরত আসে না। ফেরাতে হলে উল্টো দিকে
     * আরেকটা স্থানান্তর — তাতে দুইটা ট্রাকের যাত্রাই খাতায় থাকে।
     */
    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        if ($transfer->status === DocumentStatus::CLOSED) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.received_cannot_cancel', ['no' => $transfer->document_no]),
            ]);
        }

        if ($transfer->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.already_cancelled', ['no' => $transfer->document_no]),
            ]);
        }

        return DB::transaction(function () use ($transfer, $reason) {
            /*
             * ⛔ সারি আটকে আবার পড়া — ২৯ সেপ্টেম্বর ২০২৬। পুরনো পাতার
             * বাতিল আটকানো মাল দ্বিতীয়বার ছাড়ত (শূন্যের নিচে), বা পৌঁছে
             * যাওয়া স্থানান্তরও বাতিল করে দিত। ছাড়া হবে কি না, সেটাও
             * আটকানো অবস্থা থেকেই ঠিক হয়, হাতের পুরনো মডেল থেকে নয়।
             */
            $status = $this->lockedStatus($transfer);

            if ($status === DocumentStatus::CLOSED) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.received_cannot_cancel', ['no' => $transfer->document_no]),
                ]);
            }

            if ($status === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('inventory::validation.already_cancelled', ['no' => $transfer->document_no]),
                ]);
            }

            if ($status === DocumentStatus::CONFIRMED) {
                $transfer->loadMissing(['lines.product', 'fromWarehouse']);

                // আটকানো মাল ছেড়ে দেওয়া — ট্রাক ফিরে এসেছে
                foreach ($transfer->lines as $line) {
                    $this->stock->move(
                        product: $line->product,
                        warehouse: $transfer->fromWarehouse,
                        sourceType: StockTransfer::STOCK_SOURCE,
                        sourceId: $transfer->id,
                        hold: bcmul((string) $line->qty, '-1', 4),
                        reason: $this->onTheWay(),
                        date: now(),
                        documentNo: $transfer->document_no,
                        narration: $reason,
                    );
                }
            }

            $transfer->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $transfer->fresh(['lines']);
        });
    }

    /**
     * ⭐ "অন্য গুদামের পথে" — আটকানোর কারণ, ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ তিন জায়গাতেই একই কারণ, নাহলে রিপোর্ট ভুতুড়ে হত ─────────────
     * আটকানোর রিপোর্ট সারি ভাগ করে **কারণ ধরে**, আর শূন্যে নেমে আসা
     * জোড়া বাদ দেয়। ⚠️ রওনার সারিতে কারণ বসিয়ে ছাড়ার সারিতে না বসালে
     * যোগফল দুই ভাগ হয়ে যেত: `+৪০ পথে` আর `−৪০ (কারণ নেই)` — দুইটাই
     * টিকে থাকত, আর মাল পৌঁছে যাওয়ার পরও চিরকাল "পথে" দেখাত।
     *
     * ── ⓘ না পেলে `null`, ব্যতিক্রম নয় ─────────────────────────────
     * ⛔ সারিটা মাস্টার তালিকার, আর কেউ ওটা মুছে ফেলতে পারেন। ⚠️ তখন
     * ব্যতিক্রম ছুড়লে **মাল পাঠানোই বন্ধ** হয়ে যেত — একটা তালিকার
     * সারির জন্য গুদামের কাজ থামানো অসম্ভব বেশি দাম।
     *
     * ⓘ `null` ফিরলে আচরণটা ২৪ সেপ্টেম্বরের আগের মতোই: মালটা আটকায়,
     * কেবল কারণের ঘরটা ফাঁকা থাকে।
     */
    private function onTheWay(): ?ReasonCode
    {
        return ReasonCode::query()
            ->where('code', 'HOLD-TRN')
            ->where('context', ReasonCode::HOLD)
            ->first();
    }

    /**
     * স্থানান্তরের সারি আটকে তার এখনকার অবস্থা — ২৯ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ লেনদেনের ভেতরেই ডাকতে হয়; দ্বিতীয় অনুরোধ এখানে অপেক্ষা করে,
     * আর প্রথমটা শেষ হলে নতুন অবস্থাটা দেখে।
     */
    private function lockedStatus(StockTransfer $transfer): string
    {
        return (string) StockTransfer::query()
            ->whereKey($transfer->id)
            ->lockForUpdate()
            ->value('status');
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(StockTransfer $transfer, array $lines): void
    {
        $transfer->lines()->delete();

        $lineNo = 0;

        foreach ($lines as $line) {
            $qty = trim((string) ($line['qty'] ?? ''));

            if ($qty === '' || ! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                continue;
            }

            $product = Product::query()->whereKey((int) ($line['product_id'] ?? 0))->first();

            if ($product === null) {
                throw ValidationException::withMessages(['lines' => __('inventory::validation.unknown_product')]);
            }

            // "২ বাক্স পাঠানো হল" — গুদামের মধ্যেও প্যাকেই লেখা হয়
            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null);

            StockTransferLine::create([
                'company_id' => $transfer->company_id,
                'stock_transfer_id' => $transfer->id,
                'product_id' => $product->id,
                'qty' => bcadd($pack['qty'], '0', 4),
                'entered_qty' => $pack['entered_qty'],
                'entered_unit_id' => $pack['entered_unit_id'],
                'line_no' => ++$lineNo,
            ]);
        }

        if ($lineNo === 0) {
            throw ValidationException::withMessages(['lines' => __('inventory::validation.no_lines')]);
        }
    }

    private function assertDifferent(Warehouse $from, Warehouse $to): void
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => __('inventory::validation.same_warehouse'),
            ]);
        }
    }

    /**
     * ⭐ শাখা-পেরোনো গুদাম বদলে মজুদের টাকাও শাখা বদলায় — Inventory অডিট ম১১, ৫ অক্টোবর ২০২৬।
     *
     * ⛔ আগে গুদাম বদলে খাতায় কিছুই নড়ত না: নেত্রকোনায় কেনা মাল ময়মনসিংহে গিয়ে বিক্রি হলে ময়মনসিংহের স্থিতিপত্রে মজুদ ঋণাত্মক,
     * নেত্রকোনায় বাড়তি — শাখার খাতা মিথ্যা, কোম্পানির মোট ঠিক।
     * ⓘ গ্রহণে মজুদ খাতের একটা দাখিলা: পাঠানো শাখায় ক্রেডিট, পাওয়া শাখায় ডেবিট; দাম গড় খরচে (স্তর কোম্পানির, শাখার নয় —
     * [[CostLayerService]])। একই শাখার গুদাম বা শাখাহীন গুদামে কিছু নয়। ⓘ গ্রহণের পরে স্থানান্তর বাতিল হয় না, তাই উল্টানোর পথ লাগে না।
     */
    private function moveTheValueBetweenBranches(StockTransfer $transfer): void
    {
        $from = $transfer->fromWarehouse?->branch_id;
        $to = $transfer->toWarehouse?->branch_id;

        if ($from === null || $to === null || (int) $from === (int) $to) {
            return;
        }

        $layers = app(\App\Modules\Inventory\Services\CostLayerService::class);
        $amount = '0';

        foreach ($transfer->lines as $line) {
            $qtyOnHand = $layers->qtyOnHand($line->product);

            if (bccomp($qtyOnHand, '0', 4) <= 0) {
                continue;
            }

            $average = bcdiv($layers->valueOnHand($line->product), $qtyOnHand, 6);
            $amount = bcadd($amount, bcmul((string) $line->qty, $average, 6), 6);
        }

        $amount = bcadd($amount, '0', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            return;
        }

        $inventory = \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::INVENTORY);

        if ($inventory === null) {
            return;
        }

        $note = __('inventory::message.transfer_arrived', ['no' => $transfer->document_no]);

        app(\App\Core\Engines\Posting\PostingEngine::class)->post(
            sourceType: StockTransfer::drillSourceType(),
            sourceId: (int) $transfer->id,
            trxDate: now(),
            lines: [
                ['account_id' => $inventory->id, 'debit' => $amount, 'narration' => $note, 'branch_id' => (int) $to],
                ['account_id' => $inventory->id, 'credit' => $amount, 'narration' => $note, 'branch_id' => (int) $from],
            ],
            documentNo: $transfer->document_no,
        );
    }

    /**
     * ⭐ গুদাম বদলে দুজনের কাজ — Inventory অডিট ম৪; মালিক, ৫ অক্টোবর ২০২৬ ("এভাবেই করো")।
     *
     * ⛔ আগে একজনই পাঠাতেন আর গ্রহণ করতেন — পথে হারানো মাল "পৌঁছেছে" লেখা যেত, আর কেউ মিলিয়ে দেখত না।
     * ⓘ কোম্পানির সুইচ `inventory.transfer_two_people` (ডিফল্ট বন্ধ — বন্ধে আজকের মতো, এক-লোকের ডিপো আটকায় না)। চালুতে যিনি
     * পাঠালেন তিনি গ্রহণ করতে পারেন না; সুপার অ্যাডমিন পারেন, কিন্তু অডিটে লেখা থাকে যে একই মানুষ দুটোই করেছেন।
     * ⓘ পুরনো কাগজে পাঠানো মানুষ জানা নেই (`dispatched_by` খালি) — তখন থামানোর কিছু নেই।
     */
    private function assertTwoPeople(StockTransfer $transfer): void
    {
        $sender = (int) StockTransfer::query()->whereKey($transfer->id)->value('dispatched_by');
        $user = auth()->user();

        if (! (bool) app(\App\Core\Services\SettingsService::class)->get('inventory.transfer_two_people', false)
            || $sender === 0 || $user === null || (int) $user->getKey() !== $sender) {
            return;
        }

        if ($user->roles->contains('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)) {
            app(\App\Core\Engines\Audit\AuditEngine::class)->recordAction($transfer, 'received_by_its_sender', sprintf(
                '%s: sent and received by the same person (%s), allowed as super admin',
                $transfer->document_no,
                (string) ($user->name ?? $user->email),
            ));

            return;
        }

        throw ValidationException::withMessages([
            'status' => __('inventory::validation.transfer_same_person', ['no' => $transfer->document_no]),
        ]);
    }

    private function assertEnoughAtSource(Product $product, Warehouse $warehouse, string $qty): void
    {
        $available = $this->stock->availableQty($product, $warehouse);

        if (bccomp($available, $qty, 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('inventory::validation.not_enough_to_transfer', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                    'available' => rtrim(rtrim($available, '0'), '.'),
                ]),
            ]);
        }
    }

    private function warehouse(mixed $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()->whereKey((int) $warehouseId)->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'from_warehouse_id' => __('inventory::validation.unknown_warehouse'),
            ]);
        }

        return $warehouse;
    }

    private function assertEditable(StockTransfer $transfer): void
    {
        if ($transfer->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.only_draft_edits', ['no' => $transfer->document_no]),
            ]);
        }
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->where('starts_on', '<=', $date->toDateString())
            ->where('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('inventory::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
