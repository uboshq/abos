<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

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
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বিক্রয় ফেরত — মাল গ্রাহকের কাছ থেকে ফিরে এসেছে।
 *
 * ── চারটা দাখিলা, দুই জোড়ায় ─────────────────────────────────────────
 *     Dr  বিক্রয় ফেরত (4110)      ← আয় কমে, কিন্তু আলাদা খাতে
 *     Dr  ভ্যাট (2120)             ← সরবরাহ ভ্যাটও ফেরত
 *     Cr  প্রাপ্য হিসাব (1110)     ← গ্রাহকের পাওনা কমে
 *
 *     Dr  মজুদ পণ্য (1120)         ← মাল গুদামে ফিরল
 *     Cr  বিক্রীত পণ্যের ব্যয় (5100)
 *
 * ── কেন বিক্রয় খাতে সরাসরি ডেবিট নয় ────────────────────────────────
 * ৪১০০-এ ডেবিট বসালে মোট বিক্রয়ের অঙ্কটাই ছোট হয়ে যেত, আর "এই মাসে
 * কত বেচলাম, তার কতটা ফেরত এল" প্রশ্নের উত্তর হারাত। ফেরত আলাদা খাতে
 * থাকলে দুইটাই দেখা যায় — আর ফেরতের হার বেড়ে গেলে সেটা চোখে পড়ে।
 *
 * ── নষ্ট মাল আবার বিক্রি হয়ে যাবে না ────────────────────────────────
 * লাইনে "to_hold" থাকলে মালটা গুদামে ঢোকে কিন্তু একই সাথে Hold-এ যায়,
 * তাই বিক্রয়যোগ্য হয় না। এটা না থাকলে ফেরত আসা নষ্ট মাল পরদিন আবার
 * কারও কাছে চলে যেত।
 */
final class SalesReturnService
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
    public function create(array $data, array $lines): SalesReturn
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $documentNo = $this->numbers->next('SR');

            $return = SalesReturn::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $this->resolveWarehouse($data['warehouse_id'] ?? null)->id,
                'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
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
                    'source_type' => SalesReturn::drillSourceType(),
                    'source_id' => $return->id,
                ]);

            return $return->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(SalesReturn $return, array $data, array $lines): SalesReturn
    {
        $this->assertEditable($return);

        return DB::transaction(function () use ($return, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $return->trx_date);

            $return->update([
                'warehouse_id' => $this->resolveWarehouse($data['warehouse_id'] ?? $return->warehouse_id)->id,
                'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
                'reason_code_id' => $data['reason_code_id'] ?? null,
                'trx_date' => $trxDate->toDateString(),
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($return, $lines);

            return $return->fresh(['lines']);
        });
    }

    public function confirm(SalesReturn $return): SalesReturn
    {
        if ($return->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $return->document_no]),
            ]);
        }

        $return->loadMissing(['lines.product', 'lines.invoiceLine', 'warehouse', 'reasonCode']);

        if ($return->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        foreach ($return->lines as $line) {
            $this->assertWithinSold($line);
        }

        return DB::transaction(function () use ($return) {
            foreach ($return->lines as $line) {
                /*
                 * মাল তাকে ফেরে, আর নষ্ট হলে একই সাথে আটকে যায়।
                 *
                 * দুইটা আলাদা সারি নয়, একটাই: floor বাড়ে, আর hold-ও
                 * বাড়ে। ফলে গুদামে গুনলে মালটা পাওয়া যায় (যা সত্যি),
                 * অথচ বিক্রয়যোগ্য হিসাবে আসে না (যেটাও সত্যি)।
                 */
                $this->stock->move(
                    product: $line->product,
                    warehouse: $return->warehouse,
                    sourceType: SalesReturn::STOCK_SOURCE,
                    sourceId: $return->id,
                    floor: (string) $line->qty,
                    hold: $line->to_hold ? (string) $line->qty : '0',
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

    public function cancel(SalesReturn $return, string $reason, Carbon|string|null $onDate = null): SalesReturn
    {
        if ($return->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $return->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($return, $reason, $date) {
            if ($return->status === DocumentStatus::CONFIRMED) {
                $return->loadMissing(['lines.product', 'warehouse']);

                foreach ($return->lines as $line) {
                    $this->stock->move(
                        product: $line->product,
                        warehouse: $return->warehouse,
                        sourceType: SalesReturn::STOCK_SOURCE,
                        sourceId: $return->id,
                        floor: bcmul((string) $line->qty, '-1', 4),
                        hold: $line->to_hold ? bcmul((string) $line->qty, '-1', 4) : '0',
                        date: $date,
                        documentNo: $return->document_no,
                        narration: $reason,
                    );
                }

                $this->posting->reverse(
                    sourceType: SalesReturn::drillSourceType(),
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

    private function postToLedger(SalesReturn $return): void
    {
        $total = (string) $return->total;

        if (bccomp($total, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.zero_value_return'),
            ]);
        }

        $lines = [
            [
                'account_id' => $this->account(StandardChart::SALES_RETURN)->id,
                'debit' => (string) $return->subtotal,
                'narration' => __('sales::message.return_lowers_sales', ['no' => $return->document_no]),
            ],
            [
                'account_id' => $this->account(StandardChart::RECEIVABLE)->id,
                'credit' => $total,
                'party_type' => 'customer',
                'party_id' => $return->customer_id,
                'narration' => __('sales::message.return_lowers_receivable', ['no' => $return->document_no]),
            ],
        ];

        $tax = (string) $return->tax;

        if (bccomp($tax, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::VAT_PAYABLE)->id,
                'debit' => $tax,
                'narration' => __('sales::message.return_vat', ['no' => $return->document_no]),
            ];
        }

        /*
         * মালের ব্যয়ও একই দাখিলায় ফেরে, আলাদা পোস্টে নয়।
         *
         * ── কেন এক দাখিলায় ──────────────────────────────────────────
         * পোস্টিং ইঞ্জিন একটা ডকুমেন্টের জন্য একবারই খাতায় লেখে —
         * দ্বিতীয়বার ডাকলে "এটা তো আগেই খাতায় আছে" বলে থামিয়ে দেয়।
         * আর সেটাই ঠিক: একটা কাগজের দুইটা আলাদা দাখিলা থাকলে বাতিল
         * করার সময় একটা উল্টে যেত আর অন্যটা থেকে যেত।
         *
         * বিক্রয় বিলও ঠিক এভাবেই আয় ও ব্যয় একসাথে লেখে। দুই দিক
         * মেলে: ডেবিট = ফেরত + ভ্যাট + ব্যয়, ক্রেডিট = পাওনা + মজুদ।
         *
         * বিক্রির সময় মজুদ কমে খরচ বেড়েছিল; মাল ফিরে এলে দুইটাই উল্টো
         * দিকে যায়। না ফেরালে লাভ-ক্ষতিতে খরচটা থেকে যেত অথচ মালটা
         * গুদামেই — অর্থাৎ একই মালের ব্যয় দুইবার গোনা হত।
         */
        $cost = (string) $return->cost_of_goods;

        if (bccomp($cost, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::INVENTORY)->id,
                'debit' => $cost,
                'narration' => __('sales::message.return_stock_back', ['no' => $return->document_no]),
            ];

            $lines[] = [
                'account_id' => $this->account(StandardChart::COST_OF_GOODS_SOLD)->id,
                'credit' => $cost,
                'narration' => __('sales::message.return_cost_back', ['no' => $return->document_no]),
            ];
        }

        $this->posting->post(
            sourceType: SalesReturn::drillSourceType(),
            sourceId: $return->id,
            trxDate: $return->trx_date,
            lines: $lines,
            documentNo: $return->document_no,
            branchId: $return->branch_id,
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(SalesReturn $return, array $lines): void
    {
        $return->lines()->delete();

        $subtotal = '0';
        $taxTotal = '0';
        $cost = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $qty = $this->money($line['qty'] ?? null);

            if (bccomp($qty, '0', 4) <= 0) {
                continue;
            }

            $product = Product::query()->whereKey((int) ($line['product_id'] ?? 0))->first();

            if ($product === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_product')]);
            }

            $invoiceLine = null;

            if (filled($line['sales_invoice_line_id'] ?? null)) {
                $invoiceLine = SalesInvoiceLine::query()->whereKey((int) $line['sales_invoice_line_id'])->first();

                if ($invoiceLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => __('sales::validation.unknown_invoice_line'),
                    ]);
                }

                if ((int) $invoiceLine->product_id !== (int) $product->id) {
                    throw ValidationException::withMessages([
                        'lines' => __('sales::validation.line_product_mismatch'),
                    ]);
                }
            }

            /*
             * দর বিল থেকে, হাতে লেখা নয় (থাকলে)।
             *
             * ফেরতের দর বিক্রির দরই হওয়া উচিত। হাতে বসাতে দিলে কেউ বেশি
             * দরে ফেরত দেখিয়ে গ্রাহকের পাওনা বেশি কমাতে পারত।
             */
            $rate = $invoiceLine !== null
                ? (string) $invoiceLine->rate
                : $this->money($line['rate'] ?? $product->sale_price);

            $amount = bcmul($qty, $rate, 4);
            $tax = $this->money($line['tax'] ?? '0');

            SalesReturnLine::create([
                'company_id' => $return->company_id,
                'sales_return_id' => $return->id,
                'product_id' => $product->id,
                'sales_invoice_line_id' => $invoiceLine?->id,
                'qty' => $qty,
                'rate' => $rate,
                'tax' => $tax,
                'amount' => $amount,
                'to_hold' => (bool) ($line['to_hold'] ?? false),
                'line_no' => ++$lineNo,
            ]);

            $subtotal = bcadd($subtotal, $amount, 4);
            $taxTotal = bcadd($taxTotal, $tax, 4);

            /*
             * ফেরত মালের ব্যয় — পণ্যের ক্রয়মূল্য ধরে।
             *
             * বিক্রয় বিলেও ব্যয়টা ঠিক এভাবেই হিসাব হয়, তাই দুইটা মেলে।
             * অন্য কোনো নিয়ম (গড় দর, FIFO) বসালে বিক্রি আর ফেরতে দুই
             * রকম ব্যয় বসত, আর মজুদের অঙ্ক ধীরে ধীরে সরে যেত।
             */
            $cost = bcadd($cost, bcmul($qty, (string) $product->purchase_price, 4), 4);
        }

        $return->update([
            'subtotal' => $subtotal,
            'tax' => $taxTotal,
            'total' => bcadd($subtotal, $taxTotal, 4),
            'cost_of_goods' => $cost,
        ]);
    }

    /**
     * যত বেচা হয়েছে তার বেশি ফেরত নয়।
     */
    private function assertWithinSold(SalesReturnLine $line): void
    {
        $invoiceLine = $line->invoiceLine;

        if ($invoiceLine === null) {
            return;
        }

        $alreadyReturned = SalesReturnLine::query()
            ->where('sales_invoice_line_id', $invoiceLine->id)
            ->whereKeyNot($line->id)
            ->whereHas('return', fn ($q) => $q->whereIn('status', [
                DocumentStatus::CONFIRMED,
                DocumentStatus::CLOSED,
            ]))
            ->sum('qty');

        $room = bcsub((string) $invoiceLine->qty, (string) ($alreadyReturned ?: '0'), 4);

        if (bccomp((string) $line->qty, $room, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.over_returned', [
                    'no' => $invoiceLine->invoice?->document_no ?? '',
                    'room' => rtrim(rtrim($room, '0'), '.'),
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
                'warehouse_id' => __('sales::validation.unknown_warehouse'),
            ]);
        }

        return $warehouse;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.not_a_number')]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    private function assertEditable(SalesReturn $return): void
    {
        if ($return->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $return->document_no]),
            ]);
        }
    }

    private function account(string $code): Account
    {
        $account = Account::query()->where('code', $code)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.missing_account', ['code' => $code]),
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
                'trx_date' => __('sales::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
