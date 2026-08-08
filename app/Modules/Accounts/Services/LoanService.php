<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ঋণ — নেওয়া, শোধ করা, আর সুদ।
 *
 * ── প্রতিটা নড়াচড়া খতিয়ানে, আর সেটাই একমাত্র সত্য ─────────────────
 * বকেয়া কোনো কলামে জমা রাখা হয় না; খতিয়ান থেকে গোনা হয়
 * (Loan::outstanding)। দুই জায়গায় একই সংখ্যা রাখলে একদিন আলাদা হবেই —
 * একটা পরিশোধ দুইবার বসলে, বা কেউ ভাউচার দিয়ে সরাসরি ঋণের খাতে হাত
 * দিলে — আর তখন কোনটা সত্যি তা বলার উপায় থাকে না।
 *
 * ── দিকগুলো একবারই ঠিক করা হয় ───────────────────────────────────────
 * ঋণ একটা দায়। টাকা আসা মানে দায় বাড়া (ক্রেডিট), শোধ করা মানে দায়
 * কমা (ডেবিট)। সুদ দায় বাড়ায় না — ওটা খরচ, আর ব্যাংক সেটা আলাদা করে
 * নেয়। এই তিনটা দিক ডাকার জায়গায় ছেড়ে দিলে একদিন কেউ উল্টো বসাত,
 * আর দায়কে সম্পদ দেখাত।
 */
final class LoanService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * নতুন ঋণ — টাকা ঢোকে, দায় জন্মায়, আর টার্ম লোনে সূচি বসে।
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $intoAccountId = null): Loan
    {
        return DB::transaction(function () use ($data, $intoAccountId) {
            $kind = $data['kind'] ?? Loan::TERM;

            $loan = Loan::create([
                ...$data,
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'kind' => $kind,
                'document_no' => $this->numbers->next('LN'),
                'status' => DocumentStatus::CONFIRMED,
                'created_by' => auth()->id(),
            ]);

            if ($loan->isTerm()) {
                $this->buildSchedule($loan);

                /*
                 * টার্ম লোনে পুরো টাকাটা একবারেই আসে, তাই দাখিলাটা
                 * এখানেই। CC-তে নয় — ওখানে সীমা মঞ্জুর হওয়া আর টাকা
                 * তোলা দুইটা আলাদা ঘটনা, আর মঞ্জুরির দিনে খাতায় কিছু
                 * বসে না। সীমা একটা অনুমতি, দায় নয়।
                 */
                if ($intoAccountId !== null) {
                    $this->drawDown($loan, (string) $loan->sanctioned, $intoAccountId);
                }
            }

            return $loan->fresh();
        });
    }

    /**
     * টাকা তোলা — টার্ম লোনে একবার, CC-তে যতবার খুশি।
     *
     * @throws ValidationException
     */
    public function drawDown(Loan $loan, string $amount, int $intoAccountId, Carbon|string|null $date = null): void
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.loan_amount_positive'),
            ]);
        }

        /*
         * সীমার বাইরে তোলা যায় না।
         *
         * CC-র পুরো ব্যাপারটাই একটা সীমা। ওটা না দেখলে ব্যাংক নিজেই
         * ফিরিয়ে দিত, কিন্তু ততক্ষণে আমাদের খাতায় টাকাটা বসে গেছে —
         * আর ব্যাংকের বিবরণীর সাথে মেলাতে গিয়ে কেউ বুঝত না গরমিলটা
         * কোথা থেকে এল।
         */
        if ($loan->isCc()) {
            $after = bcadd($loan->outstanding(), $amount, 4);

            if (bccomp($after, (string) $loan->sanctioned, 4) > 0) {
                throw ValidationException::withMessages([
                    'amount' => __('accounts::validation.loan_over_limit', [
                        'available' => number_format((float) $loan->available(), 2),
                    ]),
                ]);
            }
        }

        // টাকা এল (সম্পদ ডেবিট), দায় জন্মাল (ক্রেডিট)
        $this->posting->post(
            sourceType: Loan::drillSourceType(),
            sourceId: $loan->id,
            trxDate: $this->dateFor($date),
            lines: [
                ['account_id' => $intoAccountId, 'debit' => $amount],
                ['account_id' => $loan->liability_account_id, 'credit' => $amount],
            ],
            documentNo: $loan->document_no,
        );
    }

    /**
     * একটা কিস্তি পরিশোধ — আসল দায় কমায়, সুদ খরচে যায়।
     *
     * ── কেন দুইটা লাইন, একটা নয় ────────────────────────────────────
     * ব্যাংক একটাই টাকা কাটে, কিন্তু ওই টাকার দুইটা আলাদা অর্থ: একটা
     * অংশ ধার শোধ (দায় কমে), আরেকটা ভাড়া (খরচ)। একসাথে দায় থেকে
     * কাটলে ঋণ দ্রুত শোধ হয়ে যেত খাতায়, আর সুদটা লাভ-লোকসানে কোথাও
     * দেখাত না — অর্থাৎ মুনাফা বেশি দেখাত।
     *
     * @throws ValidationException
     */
    public function payInstalment(
        LoanInstalment $instalment,
        int $fromAccountId,
        Carbon|string|null $date = null,
        ?string $amount = null,
    ): LoanInstalment {
        if ($instalment->isPaid()) {
            throw ValidationException::withMessages([
                'paid_on' => __('accounts::validation.instalment_already_paid'),
            ]);
        }

        $loan = $instalment->loan;
        $paid = $amount ?? $instalment->total();

        return DB::transaction(function () use ($instalment, $loan, $fromAccountId, $date, $paid) {
            /*
             * ব্যাংক যা কেটেছে তা যদি সূচির চেয়ে আলাদা হয়, সুদটাই
             * সূচির মানে থাকে আর বাকিটা আসলে যায়।
             *
             * উল্টোটা করলে (সুদ বদলানো) খরচের খাত কাগজের সাথে মিলত না,
             * আর সুদের অঙ্ক বছরের কর হিসাবেও যায়।
             */
            $interest = (string) $instalment->interest;
            $principal = bcsub($paid, $interest, 4);

            $this->posting->post(
                sourceType: Loan::drillSourceType(),
                sourceId: $loan->id,
                trxDate: $this->dateFor($date),
                lines: [
                    ['account_id' => $loan->liability_account_id, 'debit' => $principal],
                    ['account_id' => $loan->interest_account_id, 'debit' => $interest],
                    ['account_id' => $fromAccountId, 'credit' => $paid],
                ],
                documentNo: $loan->document_no.'/'.$instalment->no,
            );

            $instalment->forceFill([
                'paid_amount' => $paid,
                'paid_on' => $this->dateFor($date),
                'status' => LoanInstalment::PAID,
            ])->save();

            return $instalment->fresh();
        });
    }

    /**
     * CC-তে জমা — কেবল দায় কমে, সুদ আলাদা।
     *
     * @throws ValidationException
     */
    public function repay(Loan $loan, string $amount, int $fromAccountId, Carbon|string|null $date = null): void
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.loan_amount_positive'),
            ]);
        }

        $this->posting->post(
            sourceType: Loan::drillSourceType(),
            sourceId: $loan->id,
            trxDate: $this->dateFor($date),
            lines: [
                ['account_id' => $loan->liability_account_id, 'debit' => $amount],
                ['account_id' => $fromAccountId, 'credit' => $amount],
            ],
            documentNo: $loan->document_no,
        );
    }

    /**
     * মাসের সুদ বসানো — CC-তে।
     *
     * ── কেন এটা দায় বাড়ায় ──────────────────────────────────────────
     * ব্যাংক CC-র সুদ আলাদা করে চায় না; মাসের শেষে হিসাবেই বসিয়ে দেয়,
     * অর্থাৎ বকেয়া বেড়ে যায়। তাই খরচ ডেবিট আর দায় ক্রেডিট — টাকা
     * কোথাও নড়ে না, কিন্তু ধার বাড়ে।
     *
     * টার্ম লোনে এটা লাগে না: ওখানে সুদ কিস্তির ভেতরেই আছে।
     */
    public function chargeInterest(Loan $loan, string $amount, Carbon|string|null $date = null): void
    {
        if (bccomp($amount, '0', 4) <= 0) {
            return;
        }

        $this->posting->post(
            sourceType: Loan::drillSourceType(),
            sourceId: $loan->id,
            trxDate: $this->dateFor($date),
            lines: [
                ['account_id' => $loan->interest_account_id, 'debit' => $amount],
                ['account_id' => $loan->liability_account_id, 'credit' => $amount],
            ],
            documentNo: $loan->document_no,
        );
    }

    /** সূচিটা একবারই বসে, ঋণ তৈরির সময়। */
    private function buildSchedule(Loan $loan): void
    {
        $rows = LoanSchedule::build(
            principal: (string) $loan->sanctioned,
            annualRate: (string) $loan->interest_rate,
            months: (int) $loan->tenure_months,
            firstDueOn: $loan->first_instalment_on ?? $loan->start_date,
            method: $loan->interest_method ?? LoanSchedule::REDUCING,
        );

        foreach ($rows as $row) {
            LoanInstalment::create([
                'loan_id' => $loan->id,
                'no' => $row['no'],
                'due_date' => $row['due_date'],
                'principal' => $row['principal'],
                'interest' => $row['interest'],
                'status' => LoanInstalment::DUE,
            ]);
        }
    }

    private function dateFor(Carbon|string|null $date): string
    {
        return $date === null
            ? Carbon::today()->toDateString()
            : ($date instanceof Carbon ? $date->toDateString() : (string) $date);
    }
}
