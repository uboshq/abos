<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PaymentLine;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * সরবরাহকারীকে পরিশোধ — টাকা গেছে।
 *
 *     Dr  প্রদেয় হিসাব (2110, সরবরাহকারীর নামে)
 *     Cr  নগদ / ব্যাংক (যেখান থেকে টাকাটা গেল)
 *
 * ── কেন লাইনগুলো বিলের ────────────────────────────────────────────────
 * মাস শেষে এক চেকে সাতটা চালানের টাকা যায়। ভাগটা না রাখলে "কোন বিলটা
 * এখনো বাকি" প্রশ্নের উত্তর থাকত না — সরবরাহকারীর সাথে বসে মেলানোর সময়
 * দুই পক্ষই আন্দাজ করত, আর সেই আন্দাজেই সম্পর্ক নষ্ট হয়।
 *
 * ── আদায়ের আয়না, কিন্তু হুবহু নকল নয় ─────────────────────────────────
 * গঠনটা CollectionService-এর মতোই ইচ্ছাকৃতভাবে: দুইটা পর্দা একই রকম
 * আচরণ করলে ব্যবহারকারীকে দুইবার শিখতে হয় না। তফাত কেবল দিকটায় —
 * টাকা যাচ্ছে, আসছে না, তাই ডেবিট-ক্রেডিট উল্টো।
 */
final class PaymentService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly CashTillService $tills,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): Payment
    {
        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $amount = $this->money($data['amount'] ?? null);

            if (bccomp($amount, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('purchase::validation.payment_must_be_positive'),
                ]);
            }

            $documentNo = $this->numbers->next('SP');

            $payment = Payment::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'supplier_id' => $data['supplier_id'],
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

            $this->replaceLines($payment, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => Payment::drillSourceType(),
                    'source_id' => $payment->id,
                ]);

            return $payment->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Payment $payment, array $data, array $lines): Payment
    {
        $this->assertEditable($payment);

        return DB::transaction(function () use ($payment, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $payment->trx_date);

            $payment->update([
                'account_id' => $this->resolveMoneyAccount($data['account_id'] ?? $payment->account_id)->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $this->money($data['amount'] ?? $payment->amount),
                'instrument' => $data['instrument'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'instrument_date' => $data['instrument_date'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($payment, $lines);

            return $payment->fresh(['lines']);
        });
    }

    public function confirm(Payment $payment): Payment
    {
        if ($payment->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_confirms', ['no' => $payment->document_no]),
            ]);
        }

        $this->assertStillFits($payment);

        return DB::transaction(function () use ($payment) {
            $this->posting->post(
                sourceType: Payment::drillSourceType(),
                sourceId: $payment->id,
                trxDate: $payment->trx_date,
                lines: [
                    [
                        'account_id' => $this->account(StandardChart::PAYABLE)->id,
                        'debit' => (string) $payment->amount,
                        'party_type' => 'supplier',
                        'party_id' => $payment->supplier_id,
                        'narration' => __('purchase::message.against_payable', ['no' => $payment->document_no]),
                    ],
                    [
                        'account_id' => $payment->account_id,
                        'credit' => (string) $payment->amount,
                        'narration' => __('purchase::message.money_out', ['no' => $payment->document_no]),
                    ],
                ],
                documentNo: $payment->document_no,
                branchId: $payment->branch_id,
            );

            $payment->update(['status' => DocumentStatus::CONFIRMED]);

            return $payment->fresh(['lines']);
        });
    }

    public function cancel(Payment $payment, string $reason, Carbon|string|null $onDate = null): Payment
    {
        if ($payment->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.already_cancelled', ['no' => $payment->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($payment, $reason, $date) {
            if ($payment->status === DocumentStatus::CONFIRMED) {
                /*
                 * উল্টো দাখিলা, মুছে ফেলা নয় (নিয়ম ৫)।
                 *
                 * টাকা চলে গিয়েছিল, তারপর ফেরত এসেছে — খাতায় দুইটাই
                 * থাকা উচিত। মুছে ফেললে ব্যাংকের কাগজের সাথে মেলানোর
                 * সময় একটা লেনদেন কম পড়ত।
                 */
                $this->posting->reverse(
                    sourceType: Payment::drillSourceType(),
                    sourceId: $payment->id,
                    reversalDate: $date,
                    reason: $reason,
                );
            }

            $payment->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $payment->fresh(['lines']);
        });
    }

    /**
     * খাতায় বসানোর মুহূর্তে ভাগগুলো এখনো খাটে কি না।
     *
     * ── কেন তৈরির সময়ের পরীক্ষাটা যথেষ্ট নয় ─────────────────────────
     * একই বিলের বিপরীতে দুইটা খসড়া পরিশোধ লেখা যায় — দুইজন আলাদা
     * মানুষ, বা একজনই ভুলে দুইবার। তৈরির সময় দুইটাই বৈধ ছিল, কারণ
     * তখন কোনোটাই খাতায় বসেনি। কিন্তু দুইটাই নিশ্চিত হলে বিলে তার
     * মোটের চেয়ে বেশি টাকা বসে যেত, আর সরবরাহকারীর খাতা অতিরিক্ত
     * শোধ দেখাত।
     *
     * টাকা নড়ে নিশ্চিত করার মুহূর্তে, তাই শেষ পাহারাটাও এখানেই।
     */
    private function assertStillFits(Payment $payment): void
    {
        $payment->loadMissing('lines.bill');

        foreach ($payment->lines as $line) {
            $bill = $line->bill;

            if ($bill === null) {
                continue;
            }

            // এই পরিশোধটা এখনো খসড়া, তাই নিজের ভাগটা বাকির হিসাবে নেই
            $due = $bill->dueAmount();

            if (bccomp((string) $line->amount, $due, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.over_allocated', [
                        'no' => $bill->document_no,
                        'due' => Money::format($due),
                    ]),
                ]);
            }
        }
    }

    /**
     * বিলভিত্তিক ভাগ।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Payment $payment, array $lines): void
    {
        $payment->lines()->delete();

        $allocated = '0';
        $lineNo = 0;

        foreach ($lines as $line) {
            $billId = (int) ($line['purchase_bill_id'] ?? 0);
            $amount = $this->money($line['amount'] ?? null);

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $bill = PurchaseBill::query()->whereKey($billId)->first();

            if ($bill === null) {
                throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_bill')]);
            }

            if ((int) $bill->supplier_id !== (int) $payment->supplier_id) {
                throw ValidationException::withMessages(['lines' => __('purchase::validation.bill_other_supplier')]);
            }

            if ($bill->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.bill_not_confirmed', ['no' => $bill->document_no]),
                ]);
            }

            /*
             * একটা বিলে তার বকেয়ার চেয়ে বেশি বসানো যায় না।
             *
             * বসালে ওই বিলটা "অতিরিক্ত শোধ" দেখাত আর অন্যটা বাকি থেকে
             * যেত, অথচ মোট মিলে যেত — ভুলটা মোটে ধরা পড়ত না, শুধু
             * বকেয়ার তালিকায় ভুল বিল উঠত।
             *
             * এই পরিশোধের নিজের লাইনগুলো আগেই মুছে ফেলা হয়েছে, তাই
             * সম্পাদনার সময় নিজের পুরনো ভাগটা দুইবার গোনা হয় না।
             */
            $due = $bill->dueAmount();

            if (bccomp($amount, $due, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.over_allocated', [
                        'no' => $bill->document_no,
                        'due' => Money::format($due),
                    ]),
                ]);
            }

            PaymentLine::create([
                'company_id' => $payment->company_id,
                'payment_id' => $payment->id,
                'purchase_bill_id' => $bill->id,
                'amount' => $amount,
                'line_no' => ++$lineNo,
            ]);

            $allocated = bcadd($allocated, $amount, 4);
        }

        if (bccomp($allocated, (string) $payment->amount, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.allocation_over_amount', [
                    'allocated' => Money::format($allocated),
                    'amount' => Money::format($payment->amount),
                ]),
            ]);
        }
    }

    /**
     * টাকাটা কোথা থেকে গেল — নগদ বা ব্যাংক জাতীয় খাত থেকেই কেবল।
     *
     * যেকোনো খাত নিতে দিলে কেউ ভুল করে "ক্রয়" খাত থেকে পরিশোধ বসাত,
     * আর তখন খরচ দুইবার গোনা হত।
     */
    private function resolveMoneyAccount(mixed $accountId): Account
    {
        $account = blank($accountId)
            ? $this->tills->ensurePrimaryTill()->account
            : Account::query()->whereKey((int) $accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => __('purchase::validation.unknown_account'),
            ]);
        }

        /*
         * গ্রুপ খাত থেকে টাকা বেরোয় না।
         *
         * ডিফল্ট ছিল ১১০১ "হাতে নগদ" — একটা মাথা, খাত নয়। ওখানে বসানো
         * সারি কোনো ব্যালেন্সে দেখাত না, কারণ `Account::balanceOn()`
         * গ্রুপের নিজের সারি গোনে না। ফলে সরবরাহকারীকে নগদে পরিশোধ
         * করলে টাকাটা কোনো কাউন্টার থেকেই কমত না — না টিলে, না
         * ড্যাশবোর্ডে, না দিনশেষের গণনায়।
         *
         * আদায়ের পথেও হুবহু একই ভুল ছিল; দুইটাই একসাথে সারানো, কারণ
         * একটা সারালে অন্যটা রয়ে গেলে দুই দিক আর মিলত না।
         */
        if ($account->is_group) {
            throw ValidationException::withMessages([
                'account_id' => __('purchase::validation.group_takes_no_money', ['name' => $account->name()]),
            ]);
        }

        $money = StandardChart::MONEY_PARENTS;

        $isMoney = Account::query()->whereKey($account->parent_id)->whereIn('code', $money)->exists();

        if (! $isMoney) {
            throw ValidationException::withMessages([
                'account_id' => __('purchase::validation.not_a_money_account', ['name' => $account->name()]),
            ]);
        }

        return $account;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages(['amount' => __('purchase::validation.not_a_number')]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['amount' => __('purchase::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    private function assertEditable(Payment $payment): void
    {
        if ($payment->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_edits', ['no' => $payment->document_no]),
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
