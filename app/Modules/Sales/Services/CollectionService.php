<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * আদায় — টাকা এসেছে।
 *
 *     Dr  নগদ / ব্যাংক (যেখানে টাকাটা ঢুকল)
 *     Cr  প্রাপ্য হিসাব (1110, গ্রাহকের নামে)
 *
 * ── কেন লাইনগুলো বিলের, পণ্যের নয় ────────────────────────────────────
 * গ্রাহক এক লাখ টাকা দিলেন। তাতে তিনটা পুরনো বিল পুরো শোধ হলো আর চতুর্থটা
 * আংশিক। ভাগটা না রাখলে "কোন বিলটা এখনো বাকি" প্রশ্নের উত্তর থাকত না —
 * শুধু মোট বকেয়া জানা যেত, আর তাগাদা দিতে গিয়ে কোন বিলের কথা বলতে হবে
 * তা কেউ বলতে পারত না।
 */
final class CollectionService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): Collection
    {
        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $amount = $this->money($data['amount'] ?? null);

            if (bccomp($amount, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('sales::validation.collection_must_be_positive'),
                ]);
            }

            $documentNo = $this->numbers->next('COL');

            $collection = Collection::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $data['customer_id'],
                'account_id' => $this->resolveMoneyAccount($data['account_id'] ?? null)->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $amount,
                'instrument' => $data['instrument'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'instrument_date' => $data['instrument_date'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($collection, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => Collection::drillSourceType(),
                    'source_id' => $collection->id,
                ]);

            return $collection->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Collection $collection, array $data, array $lines): Collection
    {
        $this->assertEditable($collection);

        return DB::transaction(function () use ($collection, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $collection->trx_date);

            $collection->update([
                'account_id' => $this->resolveMoneyAccount($data['account_id'] ?? $collection->account_id)->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $this->money($data['amount'] ?? $collection->amount),
                'instrument' => $data['instrument'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'instrument_date' => $data['instrument_date'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($collection, $lines);

            return $collection->fresh(['lines']);
        });
    }

    public function confirm(Collection $collection): Collection
    {
        if ($collection->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $collection->document_no]),
            ]);
        }

        $this->assertStillFits($collection);

        return DB::transaction(function () use ($collection) {
            $this->posting->post(
                sourceType: Collection::drillSourceType(),
                sourceId: $collection->id,
                trxDate: $collection->trx_date,
                lines: [
                    [
                        'account_id' => $collection->account_id,
                        'debit' => (string) $collection->amount,
                        'narration' => __('sales::message.money_in', ['no' => $collection->document_no]),
                    ],
                    [
                        'account_id' => $this->account(StandardChart::RECEIVABLE)->id,
                        'credit' => (string) $collection->amount,
                        'party_type' => 'customer',
                        'party_id' => $collection->customer_id,
                        'narration' => __('sales::message.against_receivable', ['no' => $collection->document_no]),
                    ],
                ],
                documentNo: $collection->document_no,
                branchId: $collection->branch_id,
            );

            $collection->update(['status' => DocumentStatus::CONFIRMED]);

            return $collection->fresh(['lines']);
        });
    }

    public function cancel(Collection $collection, string $reason, Carbon|string|null $onDate = null): Collection
    {
        if ($collection->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $collection->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($collection, $reason, $date) {
            if ($collection->status === DocumentStatus::CONFIRMED) {
                $this->posting->reverse(
                    sourceType: Collection::drillSourceType(),
                    sourceId: $collection->id,
                    reversalDate: $date,
                    reason: $reason,
                );
            }

            $collection->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $collection->fresh(['lines']);
        });
    }

    /**
     * খাতায় বসানোর মুহূর্তে ভাগগুলো এখনো খাটে কি না।
     *
     * একই বিলের বিপরীতে দুইটা খসড়া আদায় লেখা যায় — তৈরির সময় দুইটাই
     * বৈধ ছিল, কারণ তখন কোনোটাই খাতায় বসেনি। দুইটাই নিশ্চিত হলে বিলে
     * তার মোটের চেয়ে বেশি টাকা বসত।
     */
    private function assertStillFits(Collection $collection): void
    {
        $collection->loadMissing('lines.invoice');

        foreach ($collection->lines as $line) {
            $invoice = $line->invoice;

            if ($invoice === null) {
                continue;
            }

            $due = $invoice->dueAmount();

            if (bccomp((string) $line->amount, $due, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.over_allocated', [
                        'no' => $invoice->document_no,
                        'due' => Money::format($due),
                    ]),
                ]);
            }
        }
    }

    /**
     * বিলভিত্তিক ভাগ।
     *
     * ভাগের যোগফল আদায়ের চেয়ে বেশি হতে পারে না — হলে গ্রাহকের নামে এমন
     * টাকা জমা দেখাত যা তিনি দেননি।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Collection $collection, array $lines): void
    {
        $collection->lines()->delete();

        $allocated = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $invoiceId = (int) ($line['sales_invoice_id'] ?? 0);
            $amount = $this->money($line['amount'] ?? null);

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $invoice = SalesInvoice::query()->whereKey($invoiceId)->first();

            if ($invoice === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_invoice')]);
            }

            if ((int) $invoice->customer_id !== (int) $collection->customer_id) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.invoice_other_customer')]);
            }

            if ($invoice->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.invoice_not_confirmed', ['no' => $invoice->document_no]),
                ]);
            }

            /*
             * একটা বিলে তার বকেয়ার চেয়ে বেশি বসানো যায় না।
             *
             * বসালে ওই বিলটা "অতিরিক্ত শোধ" দেখাত আর অন্যটা বাকি থেকে
             * যেত, অথচ মোট মিলে যেত — ভুলটা মোটে ধরা পড়ত না, শুধু
             * তাগাদার তালিকায় ভুল বিল উঠত।
             */
            $due = $invoice->dueAmount();

            if (bccomp($amount, $due, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.over_allocated', [
                        'no' => $invoice->document_no,
                        'due' => Money::format($due),
                    ]),
                ]);
            }

            CollectionLine::create([
                'collection_id' => $collection->id,
                'sales_invoice_id' => $invoice->id,
                'amount' => $amount,
                'line_no' => ++$lineNo,
            ]);

            $allocated = bcadd($allocated, $amount, 4);
        }

        if (bccomp($allocated, (string) $collection->amount, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.allocation_over_amount', [
                    'allocated' => Money::format($allocated),
                    'amount' => Money::format($collection->amount),
                ]),
            ]);
        }
    }

    /**
     * টাকাটা কোথায় ঢুকল — নগদ বা ব্যাংক জাতীয় খাতেই কেবল।
     *
     * যেকোনো খাত নিতে দিলে কেউ ভুল করে "বিক্রয়" খাতে আদায় বসাত, আর
     * তখন আয় দুইবার গোনা হত।
     */
    private function resolveMoneyAccount(mixed $accountId): Account
    {
        $account = blank($accountId)
            ? Account::query()->where('code', StandardChart::CASH_IN_HAND)->first()
            : Account::query()->whereKey((int) $accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => __('sales::validation.unknown_account'),
            ]);
        }

        $money = [StandardChart::CASH_IN_HAND, StandardChart::BANK_AND_MFS];

        // সরাসরি ওই দুইটা খাত, নয়তো তাদের নিচের কোনো খাত
        $isMoney = in_array($account->code, $money, true)
            || Account::query()->whereKey($account->parent_id)->whereIn('code', $money)->exists();

        if (! $isMoney) {
            throw ValidationException::withMessages([
                'account_id' => __('sales::validation.not_a_money_account', ['name' => $account->name()]),
            ]);
        }

        return $account;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages(['amount' => __('sales::validation.not_a_number')]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['amount' => __('sales::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    private function assertEditable(Collection $collection): void
    {
        if ($collection->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $collection->document_no]),
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
