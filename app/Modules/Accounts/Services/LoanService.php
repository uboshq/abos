<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Models\LoanMovement;
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

                /*
                 * দিকটা এখানে ঠিক হয়, কন্ট্রোলারে নয়।
                 *
                 * ---- কেন সার্ভিসে ----
                 * নিয়মটা একসময় কন্ট্রোলারে বসানো ছিল, আর তাতে সার্ভিস
                 * সরাসরি ডাকলে (ইমপোর্ট, API, বা টেস্ট) দিকটা ভুল বসত
                 * -- পাওনা দায় হয়ে যেত, আর দাখিলাও উল্টো দিকে।
                 *
                 * হাতধারে দিকটা সত্যিই বাছাইয়ের ("আমি দিয়েছি" না "আমি
                 * নিয়েছি"), তাই সেখানে যা দেওয়া হয়েছে তাই। টার্ম আর
                 * সিসি সবসময় নেওয়া -- ব্যাংক আমাদের দেয়, উল্টোটা নয়।
                 */
                'direction' => $kind === Loan::HAND
                    ? ($data['direction'] ?? Loan::TAKEN)
                    : Loan::TAKEN,
                'document_no' => $this->numbers->next('LN'),
                'status' => DocumentStatus::CONFIRMED,
                'created_by' => auth()->id(),
            ]);

            /*
             * হাতধারে টাকাটা একবারেই নড়ে, কিন্তু কোনো সূচি হয় না।
             *
             * টার্ম লোনের মতোই "মঞ্জুরির দিনেই পুরো টাকা", তাই দাখিলা
             * এখানেই বসে। কিন্তু `buildSchedule()` ডাকা হয় না — হাতধারে
             * কিস্তি নেই, আর শূন্য কিস্তির একটা সূচি বানালে পর্দায়
             * একটা খালি টেবিল বসে থাকত, যা কিছুই বলে না।
             */
            if ($loan->isHandLoan()) {
                if ($intoAccountId !== null) {
                    $this->drawDown($loan, (string) $loan->sanctioned, $intoAccountId, $loan->start_date);
                }

                return $loan->fresh();
            }

            if ($loan->isTerm()) {
                $this->buildSchedule($loan);

                /*
                 * টার্ম লোনে পুরো টাকাটা একবারেই আসে, তাই দাখিলাটা
                 * এখানেই। CC-তে নয় — ওখানে সীমা মঞ্জুর হওয়া আর টাকা
                 * তোলা দুইটা আলাদা ঘটনা, আর মঞ্জুরির দিনে খাতায় কিছু
                 * বসে না। সীমা একটা অনুমতি, দায় নয়।
                 */
                if ($intoAccountId !== null) {
                    // টাকাটা ঋণের শুরুর তারিখেই ঢুকেছে, আজকের তারিখে নয়
                    $this->drawDown($loan, (string) $loan->sanctioned, $intoAccountId, $loan->start_date);
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
    /**
     * @return LoanMovement তোলার সারি — সইয়ের ছক থামালে `awaiting`, নইলে খাতায় বসা
     */
    public function drawDown(Loan $loan, string $amount, int $intoAccountId, Carbon|string|null $date = null): LoanMovement
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.loan_amount_positive'),
            ]);
        }

        /*
         * ⛔ এক লেনদেন, ঋণের সারিতে তালা — চূড়ান্ত অডিট ⛔৮, ৩০ সেপ্টেম্বর ২০২৬
         * ([[TwoDrawsPassedTheCashCreditLimitTest]])। ⓘ আগে সীমা দেখা হত তালা ছাড়া, লেনদেনের
         * বাইরে: দুইজন একসাথে ৬০০ তুললে দুইজনেই "১,০০০ খালি" দেখতেন আর খাতায় ১,২০০ বসত।
         * আর খাতায় বসানো আটকালে তোলার সারিটা একা পড়ে থাকত।
         */
        return DB::transaction(function () use ($loan, $amount, $intoAccountId, $date): LoanMovement {
            Loan::query()->whereKey($loan->getKey())->lockForUpdate()->first();

            return $this->draw($loan, $amount, $intoAccountId, $date);
        });
    }

    /** তালার ভিতরে তোলা — সীমা তাজা বাকি দেখে, তারপর সারি আর দাখিলা (সইয়ের ছক থামালে কেবল সারি)। */
    private function draw(Loan $loan, string $amount, int $intoAccountId, Carbon|string|null $date): LoanMovement
    {
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
                        'available' => Money::format($loan->available()),
                    ]),
                ]);
            }
        }

        $movement = $this->movement($loan, LoanMovement::DRAW, $amount, $date, $intoAccountId);

        return $this->postOrHold($movement, AccountsSignature::LOAN_DRAW);
    }

    /**
     * ⛔ সইয়ের ছক থাকলে থামে, নইলে এখনই খাতায় — অডিট (সমন্বয়ক, ১০ অক্টোবর ২০২৬): ঋণের টাকা আগে সই ছাড়াই নড়ত।
     *
     * ⓘ কাগজ প্রতিটা নড়াচড়ার নিজের সারি, ঋণ নয় — ঋণের সারি নড়ায় বদলায় না, তাই একবার সই হওয়া ৫০ হাজারের তোলা পরের ৫০ হাজারকেও
     * ঢেকে দিত ([[DocumentApproval::stopping()]] কাগজের ছাপ আর অঙ্ক মেলায়)। ছক না থাকলে (UB-এর মতো সব বন্ধ) আজকের মতোই এখনই।
     * শেষ সইয়ে [[finishSigned()]]।
     */
    private function postOrHold(LoanMovement $movement, string $action): LoanMovement
    {
        if (app(AccountsSignature::class)->holds($movement, $action, (string) $movement->amount, $movement->narration)) {
            $movement->forceFill(['status' => LoanMovement::AWAITING])->save();

            return $movement;
        }

        $this->postMovement($movement);

        return $movement;
    }

    /**
     * ⭐ শেষ সই পড়ল — অপেক্ষার সারিটা এবার খাতায় ([[FinishTheAccountsPaperOnTheLastSignature]])।
     *
     * ⓘ ঋণের সারিতে তালা, নড়াচড়ার সারি আবার পড়া: একই সই দুইবার ঘটনা পাঠালে দ্বিতীয়বার কিছু হয় না। ⛔ CC-র তোলায় সীমা আবার
     * দেখা হয় — একাধিক তোলা একসাথে সইয়ের অপেক্ষায় থাকলে প্রতিটা চাওয়ার সময় সীমার ভিতরে ছিল, সব মিলে নাও থাকতে পারে;
     * সীমা পেরোলে সারিটা অপেক্ষাতেই থাকে, খাতায় ওঠে না।
     */
    public function finishSigned(LoanMovement $movement): void
    {
        DB::transaction(function () use ($movement): void {
            $loan = Loan::query()->whereKey($movement->loan_id)->lockForUpdate()->firstOrFail();
            $fresh = LoanMovement::query()->whereKey($movement->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isAwaiting()) {
                return;
            }

            if ($fresh->kind === LoanMovement::DRAW && $loan->isCc()
                && bccomp(bcadd($loan->outstanding(), (string) $fresh->amount, 4), (string) $loan->sanctioned, 4) > 0) {
                return;
            }

            $this->postMovement($fresh);
        });
    }

    /** ⓘ সই ফেরত — অপেক্ষার সারি "প্রত্যাখ্যাত", খাতায় কিছুই ওঠেনি */
    public function rejectSigned(LoanMovement $movement): void
    {
        LoanMovement::query()->whereKey($movement->getKey())->where('status', LoanMovement::AWAITING)
            ->update(['status' => LoanMovement::REJECTED]);
    }

    /** নড়াচড়ার সারিটা খাতায় — দাখিলা তার ধরন থেকে ([[linesFor()]]), তারপর অবস্থা "posted" */
    private function postMovement(LoanMovement $movement): void
    {
        $this->posting->post(
            sourceType: LoanMovement::drillSourceType(),
            sourceId: $movement->id,
            trxDate: $movement->trx_date->toDateString(),
            lines: $this->linesFor($movement),
            documentNo: $movement->document_no,
        );

        if ($movement->status !== LoanMovement::POSTED) {
            $movement->forceFill(['status' => LoanMovement::POSTED])->save();
        }
    }

    /**
     * দাখিলার সারিগুলো — নড়াচড়ার ধরন আর ঋণের দিক থেকে।
     *
     * @return list<array<string, mixed>>
     */
    private function linesFor(LoanMovement $movement): array
    {
        $loan = $movement->loan;
        $amount = (string) $movement->amount;
        $other = $movement->counter_account_id;

        return match ($movement->kind) {
            /*
             * নেওয়া ধারে: টাকা এল (সম্পদ ডেবিট), দায় জন্মাল (ক্রেডিট)।
             *
             * দেওয়া ধারে ঠিক উল্টো — টাকা বেরোল, আর পাওনা জন্মাল। একই
             * দাখিলা দুই দিকেই বসালে দেওয়া টাকাটা খাতায় দায় হয়ে বসত,
             * অর্থাৎ যাঁকে ধার দিলাম তাঁকেই আমাদের পাওনাদার দেখাত।
             */
            LoanMovement::DRAW => $loan->isGiven()
                ? [['account_id' => $loan->principal_account_id, 'debit' => $amount], ['account_id' => $other, 'credit' => $amount]]
                : [['account_id' => $other, 'debit' => $amount], ['account_id' => $loan->principal_account_id, 'credit' => $amount]],

            /*
             * নেওয়া ধারে পরিশোধ মানে দায় কমা; দেওয়া ধারে "পরিশোধ" মানে
             * টাকা ফেরত আসা, অর্থাৎ পাওনা কমা আর নগদ বাড়া।
             */
            LoanMovement::REPAY => $loan->isGiven()
                ? [['account_id' => $other, 'debit' => $amount], ['account_id' => $loan->principal_account_id, 'credit' => $amount]]
                : [['account_id' => $loan->principal_account_id, 'debit' => $amount], ['account_id' => $other, 'credit' => $amount]],

            /*
             * নেওয়া ঋণে সুদ খরচ; দেওয়া টাকায় সুদ আয়।
             *
             * FD বা DPS-এ ব্যাংক আমাদের সুদ দেয়, আর ওটা টাকাটার সাথেই
             * জমে — অর্থাৎ সম্পদ বাড়ে, আয় হয়। একই দাখিলা দুই দিকেই বসালে
             * পাওয়া সুদটা খরচ হয়ে বসত, আর মুনাফা দুইবার কমত: একবার আয়টা
             * না দেখিয়ে, আরেকবার ওটাকে খরচ দেখিয়ে।
             */
            default => $loan->isGiven()
                ? [['account_id' => $loan->principal_account_id, 'debit' => $amount], ['account_id' => $loan->interest_account_id, 'credit' => $amount]]
                : [['account_id' => $loan->interest_account_id, 'debit' => $amount], ['account_id' => $loan->principal_account_id, 'credit' => $amount]],
        };
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

            /*
             * ⛔ সুদের চেয়ে কম দেওয়া যায় না — অডিট গ১৭, ৪ অক্টোবর ২০২৬। আগে আসল হত ঋণাত্মক, আর খাতায় বসত
             * "ঋণ খাতে ঋণাত্মক ডেবিট" — অর্থাৎ কিস্তি দিয়ে ঋণ **বাড়ত**।
             */
            if (bccomp($paid, '0', 4) <= 0 || bccomp($paid, $interest, 4) < 0) {
                throw ValidationException::withMessages([
                    'amount' => __('accounts::validation.instalment_below_interest', ['interest' => $interest]),
                ]);
            }

            $principal = bcsub($paid, $interest, 4);

            /*
             * ⛔ সইয়ের ছক থাকলে থামে — অডিট (সমন্বয়ক, ১০ অক্টোবর ২০২৬)। ⓘ কাগজ কিস্তির নিজের সারি; শেষ সইয়ে একই তথ্যে আবার
             * এখানে আসে ([[FinishTheAccountsPaperOnTheLastSignature]]), তখন সইটা খাটে আর খাতায় বসে। ছক না থাকলে আজকের মতো এখনই।
             */
            if (app(AccountsSignature::class)->holds($instalment, AccountsSignature::LOAN_INSTALMENT, $paid, null, [
                'from_account_id' => $fromAccountId, 'on' => $this->dateFor($date), 'amount' => $paid,
            ])) {
                return $instalment;
            }

            /*
             * কিস্তিটাই এখানে ডকুমেন্ট — ঋণ নয়।
             *
             * প্রতিটা কিস্তির নিজের id আছে, তাই ছত্রিশটা কিস্তি মানে
             * ছত্রিশটা আলাদা ডকুমেন্ট, আর একই কিস্তি দুইবার বসাতে গেলে
             * পোস্টিং ইঞ্জিন নিজেই আটকায়।
             */
            $this->posting->post(
                sourceType: LoanInstalment::drillSourceType(),
                sourceId: $instalment->id,
                trxDate: $this->dateFor($date),
                /*
                 * ⭐ শূন্যের সারি বাদ — অডিট গ১৭। বিনা সুদের ঋণে সুদের সারি শূন্য, আর ইঞ্জিন শূন্য সারি নেয় না;
                 * তাই আগে এমন ঋণের একটা কিস্তিও দেওয়া যেত না। পুরো কিস্তি সুদ হলে আসলের সারিও শূন্য।
                 */
                lines: array_values(array_filter([
                    ['account_id' => $loan->principal_account_id, 'debit' => $principal],
                    ['account_id' => $loan->interest_account_id, 'debit' => $interest],
                    ['account_id' => $fromAccountId, 'credit' => $paid],
                ], fn (array $l) => bccomp((string) ($l['debit'] ?? $l['credit']), '0', 4) > 0)),
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
    /** @return LoanMovement জমার সারি — সইয়ের ছক থামালে `awaiting` ([[postOrHold()]]) */
    public function repay(Loan $loan, string $amount, int $fromAccountId, Carbon|string|null $date = null): LoanMovement
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.loan_amount_positive'),
            ]);
        }

        return DB::transaction(fn (): LoanMovement => $this->postOrHold(
            $this->movement($loan, LoanMovement::REPAY, $amount, $date, $fromAccountId),
            AccountsSignature::LOAN_REPAY,
        ));
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
    /** @return LoanMovement|null সুদের সারি — শূন্য হলে কিছুই নয়; সইয়ের ছক থামালে `awaiting` ([[postOrHold()]]) */
    public function chargeInterest(Loan $loan, string $amount, Carbon|string|null $date = null): ?LoanMovement
    {
        if (bccomp($amount, '0', 4) <= 0) {
            return null;
        }

        return DB::transaction(fn (): LoanMovement => $this->postOrHold(
            $this->movement($loan, LoanMovement::INTEREST, $amount, $date, null),
            AccountsSignature::LOAN_INTEREST,
        ));
    }

    /**
     * নড়াচড়ার সারিটা — খতিয়ানে বসার আগে।
     *
     * নম্বরটা ঋণের নম্বরের সাথে ক্রম জুড়ে হয় (LN-2026-2027-0001/M3),
     * আলাদা সিরিজ নয়: কাগজে ঋণটাই এক, আর এগুলো তারই ভেতরের ঘটনা।
     */
    private function movement(
        Loan $loan,
        string $kind,
        string $amount,
        Carbon|string|null $date,
        ?int $counterAccountId,
    ): LoanMovement {
        $next = LoanMovement::query()->where('loan_id', $loan->id)->count() + 1;

        return LoanMovement::create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'loan_id' => $loan->id,
            'kind' => $kind,
            'document_no' => $loan->document_no.'/M'.$next,
            'trx_date' => $this->dateFor($date),
            'amount' => $amount,
            'counter_account_id' => $counterAccountId,
            'created_by' => auth()->id(),
        ]);
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
