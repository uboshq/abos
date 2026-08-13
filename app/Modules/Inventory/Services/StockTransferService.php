<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Inventory\Models\Warehouse;
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

        foreach ($transfer->lines as $line) {
            $this->assertEnoughAtSource($line->product, $transfer->fromWarehouse, (string) $line->qty);
        }

        return DB::transaction(function () use ($transfer) {
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
                    date: $transfer->trx_date,
                    documentNo: $transfer->document_no,
                    narration: __('inventory::message.transfer_on_the_way', ['no' => $transfer->document_no]),
                );
            }

            $transfer->update([
                'status' => DocumentStatus::CONFIRMED,
                'dispatched_at' => now(),
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
            if ($transfer->status === DocumentStatus::CONFIRMED) {
                $transfer->loadMissing(['lines.product', 'fromWarehouse']);

                // আটকানো মাল ছেড়ে দেওয়া — ট্রাক ফিরে এসেছে
                foreach ($transfer->lines as $line) {
                    $this->stock->move(
                        product: $line->product,
                        warehouse: $transfer->fromWarehouse,
                        sourceType: StockTransfer::STOCK_SOURCE,
                        sourceId: $transfer->id,
                        hold: bcmul((string) $line->qty, '-1', 4),
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
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('inventory::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
