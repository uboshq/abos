<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBillLine;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\PurchaseReturnLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ক্রয় ফেরত — মাল সরবরাহকারীর কাছে ফেরত যাচ্ছে।
 *
 *     Dr  প্রদেয় হিসাব (2110, সরবরাহকারীর নামে)   ← দায় কমে
 *     Cr  মজুদ পণ্য (1120)                        ← মাল গুদাম ছাড়ে
 *     Cr  ভ্যাট (2120)                            ← উপকরণ ভ্যাটও ফেরত
 *
 * ── কেন বিলটা বাতিল করলেই হত না ─────────────────────────────────────
 * বিলে দশ বস্তা ছিল, তার দুইটা নষ্ট বেরিয়েছে। বিল বাতিল করলে বাকি
 * আটটার ক্রয়ও খাতা থেকে মুছে যেত — অথচ সেগুলো গুদামেই আছে আর টাকাও
 * দিতে হবে। ফেরত একটা আলাদা ঘটনা, তাই আলাদা কাগজ।
 *
 * ── স্টক ও খাতা একই লেনদেনে ─────────────────────────────────────────
 * ইভেন্টে নয় (প্ল্যান WP-0.3)। মাঝপথে কিছু ভাঙলে দুইটাই ফিরে যায় —
 * নাহলে মাল গুদাম ছাড়ত অথচ দায় কমত না।
 */
final class PurchaseReturnService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): PurchaseReturn
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $documentNo = $this->numbers->next('PR');

            $return = PurchaseReturn::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $this->resolveWarehouse($data['warehouse_id'] ?? null)->id,
                'purchase_bill_id' => $data['purchase_bill_id'] ?? null,
                'reason_code_id' => $data['reason_code_id'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'status' => DocumentStatus::DRAFT,
                'narration' => $data['narration'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($return, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => PurchaseReturn::drillSourceType(),
                    'source_id' => $return->id,
                ]);

            return $return->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(PurchaseReturn $return, array $data, array $lines): PurchaseReturn
    {
        $this->assertEditable($return);

        return DB::transaction(function () use ($return, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $return->trx_date);

            $return->update([
                'warehouse_id' => $this->resolveWarehouse($data['warehouse_id'] ?? $return->warehouse_id)->id,
                'purchase_bill_id' => $data['purchase_bill_id'] ?? null,
                'reason_code_id' => $data['reason_code_id'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($return, $lines);

            return $return->fresh(['lines']);
        });
    }

    public function confirm(PurchaseReturn $return): PurchaseReturn
    {
        if ($return->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_confirms', ['no' => $return->document_no]),
            ]);
        }

        $return->loadMissing(['lines.product', 'lines.billLine', 'warehouse']);

        if ($return->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.no_lines')]);
        }

        /*
         * খাতায় বসানোর মুহূর্তে আবার পরীক্ষা।
         *
         * খসড়া অবস্থায় লেখার পর অন্য কেউ ওই বিলের বাকিটা ফেরত দিয়ে
         * দিতে পারে, বা মালটা বিক্রি হয়ে যেতে পারে। মাল আর টাকা নড়ে
         * এখানেই, তাই শেষ পাহারাটাও এখানে।
         */
        foreach ($return->lines as $line) {
            $this->assertWithinBilled($line);
            $this->assertEnoughInStock($line->product, $return->warehouse, (string) $line->qty);
        }

        return DB::transaction(function () use ($return) {
            foreach ($return->lines as $line) {
                $this->stock->move(
                    product: $line->product,
                    warehouse: $return->warehouse,
                    sourceType: PurchaseReturn::STOCK_SOURCE,
                    sourceId: $return->id,
                    floor: bcmul((string) $line->qty, '-1', 4),
                    reason: $return->reasonCode,
                    date: $return->trx_date,
                    documentNo: $return->document_no,
                );
            }

            $this->postToLedger($return);

            $return->update(['status' => DocumentStatus::CONFIRMED]);

            return $return->fresh(['lines']);
        });
    }

    public function cancel(PurchaseReturn $return, string $reason, Carbon|string|null $onDate = null): PurchaseReturn
    {
        if ($return->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.already_cancelled', ['no' => $return->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($return, $reason, $date) {
            if ($return->status === DocumentStatus::CONFIRMED) {
                $return->loadMissing(['lines.product', 'warehouse']);

                // মালটা গুদামে ফিরে আসে — সারি মুছে নয়, উল্টো সারিতে
                foreach ($return->lines as $line) {
                    $this->stock->move(
                        product: $line->product,
                        warehouse: $return->warehouse,
                        sourceType: PurchaseReturn::STOCK_SOURCE,
                        sourceId: $return->id,
                        floor: (string) $line->qty,
                        date: $date,
                        documentNo: $return->document_no,
                        narration: $reason,
                    );
                }

                $this->posting->reverse(
                    sourceType: PurchaseReturn::drillSourceType(),
                    sourceId: $return->id,
                    reversalDate: $date,
                    reason: $reason,
                );
            }

            $return->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $return->fresh(['lines']);
        });
    }

    private function postToLedger(PurchaseReturn $return): void
    {
        $total = (string) $return->total;

        if (bccomp($total, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.zero_value_return'),
            ]);
        }

        $lines = [
            [
                'account_id' => $this->account(StandardChart::PAYABLE)->id,
                'debit' => $total,
                'party_type' => 'supplier',
                'party_id' => $return->supplier_id,
                'narration' => __('purchase::message.return_lowers_payable', ['no' => $return->document_no]),
            ],
            [
                'account_id' => $this->account(StandardChart::INVENTORY)->id,
                'credit' => (string) $return->subtotal,
                'narration' => __('purchase::message.stock_out', ['no' => $return->document_no]),
            ],
        ];

        $tax = (string) $return->tax;

        if (bccomp($tax, '0', 4) > 0) {
            /*
             * উপকরণ ভ্যাটও ফেরত যায়।
             *
             * কেনার সময় ওটা দাবি করা হয়েছিল; মালটা ফেরত গেলে দাবিটাও
             * থাকে না। না ফেরালে ভ্যাটের হিসাবে এমন একটা দাবি থেকে যেত
             * যার পেছনে কোনো মাল নেই।
             */
            $lines[] = [
                'account_id' => $this->account(StandardChart::VAT_PAYABLE)->id,
                'credit' => $tax,
                'narration' => __('purchase::message.return_vat', ['no' => $return->document_no]),
            ];
        }

        $this->posting->post(
            sourceType: PurchaseReturn::drillSourceType(),
            sourceId: $return->id,
            trxDate: $return->trx_date,
            lines: $lines,
            documentNo: $return->document_no,
            branchId: $return->branch_id,
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(PurchaseReturn $return, array $lines): void
    {
        $return->lines()->delete();

        $subtotal = '0';
        $taxTotal = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $qty = $this->money($line['qty'] ?? null);

            if (bccomp($qty, '0', 4) <= 0) {
                continue;
            }

            $product = Product::query()->whereKey((int) ($line['product_id'] ?? 0))->first();

            if ($product === null) {
                throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_product')]);
            }

            $billLine = null;

            if (filled($line['purchase_bill_line_id'] ?? null)) {
                $billLine = PurchaseBillLine::query()->whereKey((int) $line['purchase_bill_line_id'])->first();

                if ($billLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => __('purchase::validation.unknown_bill_line'),
                    ]);
                }

                if ((int) $billLine->product_id !== (int) $product->id) {
                    throw ValidationException::withMessages([
                        'lines' => __('purchase::validation.line_product_mismatch'),
                    ]);
                }
            }

            /*
             * দর বিল থেকে, হাতে লেখা নয় (থাকলে)।
             *
             * ফেরতের দর কেনার দরই হওয়া উচিত। হাতে বসাতে দিলে কেউ বেশি
             * দরে ফেরত দেখিয়ে প্রদেয় বেশি কমাতে পারত, আর মজুদের মূল্যও
             * ভুল হত।
             */
            $rate = $billLine !== null
                ? (string) $billLine->rate
                : $this->money($line['rate'] ?? $product->purchase_price);

            $amount = bcmul($qty, $rate, 4);
            $tax = $this->money($line['tax'] ?? '0');

            PurchaseReturnLine::create([
                'company_id' => $return->company_id,
                'purchase_return_id' => $return->id,
                'product_id' => $product->id,
                'purchase_bill_line_id' => $billLine?->id,
                'qty' => $qty,
                'rate' => $rate,
                'tax' => $tax,
                'amount' => $amount,
                'line_no' => ++$lineNo,
            ]);

            $subtotal = bcadd($subtotal, $amount, 4);
            $taxTotal = bcadd($taxTotal, $tax, 4);
        }

        $return->update([
            'subtotal' => $subtotal,
            'tax' => $taxTotal,
            'total' => bcadd($subtotal, $taxTotal, 4),
        ]);
    }

    /**
     * যত কেনা হয়েছে তার বেশি ফেরত নয়।
     *
     * বিলের লাইন ধরে ফেরত হলে এটা মেলানো যায়। না মেলালে দশ বস্তার
     * বিলে বারো বস্তা ফেরত দেখিয়ে প্রদেয় বেশি কমানো যেত, আর গুদামেও
     * ঋণাত্মক মাল বসত।
     */
    private function assertWithinBilled(PurchaseReturnLine $line): void
    {
        $billLine = $line->billLine;

        if ($billLine === null) {
            return;
        }

        $alreadyReturned = PurchaseReturnLine::query()
            ->where('purchase_bill_line_id', $billLine->id)
            ->whereKeyNot($line->id)
            ->whereHas('return', fn ($q) => $q->whereIn('status', [
                DocumentStatus::CONFIRMED,
                DocumentStatus::CLOSED,
            ]))
            ->sum('qty');

        $room = bcsub((string) $billLine->qty, (string) ($alreadyReturned ?: '0'), 4);

        if (bccomp((string) $line->qty, $room, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.over_returned', [
                    'no' => $billLine->bill?->document_no ?? '',
                    'room' => rtrim(rtrim($room, '0'), '.'),
                ]),
            ]);
        }
    }

    /**
     * গুদামে মালটা আছে তো।
     *
     * ফেরত পাঠানো মানে গুদাম থেকে বেরোনো — যা নেই তা পাঠানো যায় না।
     * না দেখলে স্টক ঋণাত্মক হয়ে যেত, আর ঋণাত্মক স্টক মানে কোথাও
     * একটা গণনা ভুল, যেটা মাস শেষে ধরা পড়ে।
     */
    private function assertEnoughInStock(Product $product, ?Warehouse $warehouse, string $qty): void
    {
        if ($warehouse === null) {
            return;
        }

        $available = $this->stock->availableQty($product, $warehouse);

        if (bccomp($available, $qty, 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.not_enough_to_return', [
                    'product' => $product->name(),
                    'available' => rtrim(rtrim($available, '0'), '.'),
                ]),
            ]);
        }
    }

    private function resolveWarehouse(mixed $warehouseId): Warehouse
    {
        $warehouse = blank($warehouseId)
            ? Warehouse::query()->where('is_default', true)->active()->first()
            : Warehouse::query()->whereKey((int) $warehouseId)->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('purchase::validation.unknown_warehouse'),
            ]);
        }

        return $warehouse;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.not_a_number')]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['lines' => __('purchase::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    private function assertEditable(PurchaseReturn $return): void
    {
        if ($return->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_edits', ['no' => $return->document_no]),
            ]);
        }
    }

    private function account(string $code): Account
    {
        $account = Account::query()->where('code', $code)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.missing_account', ['code' => $code]),
            ]);
        }

        return $account;
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('purchase::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
