<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\BankStatementMatch;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ব্যাংকের স্টেটমেন্টের সারিগুলো — পড়া, বসানো, আর মেলানো।
 *
 * ── ⛔ এই সেবাটা কোনো দাখিলা বসায় না ────────────────────────────────
 * মিলকরণের পুরো অংশটাই ইচ্ছাকৃতভাবে খতিয়ান ছোঁয় না
 * ([[BankReconciliationService]]-র একই নিয়ম)। ⓘ ব্যাংক "SERVICE CHARGE"
 * লিখলে সেটা কোন খাতে যাবে ব্যাংক জানে না — মানুষ জানেন, আর তিনি
 * স্বাভাবিক দরজা দিয়েই ভাউচার বসান।
 *
 * ⚠️ এখানকার সবচেয়ে নোংরা কাজটা হলো **ব্যাংকের ফাইল পড়া**: প্রতিটা
 * ব্যাংকের তারিখের ছক আলাদা, টাকায় কমা, আর কখনো `1,234.00 Dr`। সেই
 * নোংরা কাজটা একটাই জায়গায় রাখা হলো, যাতে পর্দা ও ইমপোর্টার দুইটাই
 * একই নিয়মে পড়ে।
 */
final class BankStatementService
{
    /**
     * ব্যাংক যত রকম তারিখ লেখে।
     *
     * ── ⚠️ কেন ক্রমটা এইভাবে ─────────────────────────────────────────
     * `d/m/Y` আগে, `m/d/Y` নেই — বাংলাদেশে ০৩/০৯/২০২৬ মানে ৩ সেপ্টেম্বর,
     * কোনোদিনই ৯ মার্চ নয়। ⛔ আমেরিকান ছকটা তালিকায় রাখলে ১২ তারিখের
     * আগের সব তারিখ **নীরবে** ভুল মাসে বসত, আর কেউ ধরত না।
     *
     * @var list<string>
     */
    private const DATE_FORMATS = [
        'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y',
        'd-M-Y', 'd M Y', 'd-M-y', 'd/m/y',
    ];

    /** ছকের কোড ধরে একটা ব্যাংক/MFS খাত — না পেলে null */
    public function bankAccountByCode(string $code): ?Account
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return Account::query()
            ->where('code', $code)
            ->whereIn('money_kind', [Account::BANK, Account::MFS])
            ->where('is_group', false)
            ->first();
    }

    /**
     * ব্যাংকের লেখা তারিখ → `Y-m-d`; বোঝা না গেলে `null`।
     *
     * ⚠️ `strtotime()` ব্যবহার করা হয়নি ইচ্ছাকৃতভাবে: ওটা ০৩/০৯/২০২৬-কে
     * আমেরিকান ধরে ৯ মার্চ বানিয়ে দেয়, আর ভুলটা চোখে পড়ে না।
     */
    public function dateOf(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            /*
             * ⚠️ `createFromFormat` ঢিলে — "32/13/2026"-ও সে পরের মাসে
             * গড়িয়ে নেয়। ⓘ তাই ফিরিয়ে লিখে মিলিয়ে দেখা হয়: একই ছকে
             * ছাপলে হুবহু একই লেখা না এলে তারিখটা আসলে বোঝা যায়নি।
             */
            if ($date !== null && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /**
     * ব্যাংকের লেখা টাকা → হিসাবযোগ্য সংখ্যা।
     *
     * ⓘ কমা, টাকার চিহ্ন, ফাঁকা, আর শেষের `Dr`/`Cr` সরানো হয়। ⚠️ ঋণাত্মক
     * চিহ্ন রাখা হয় **না**: কোন ঘরে বসেছে সেটাই দিক বলে, আর "-500"
     * ডেবিটের ঘরে বসলে সেটা আসলে ৫০০ ডেবিটই।
     */
    public function amountOf(?string $value): string
    {
        $clean = preg_replace('/[^0-9.]/u', '', (string) $value);

        return $clean === '' || $clean === null ? '0' : bcadd($clean, '0', 4);
    }

    /**
     * একটা সারি বসানো — আগে বসে থাকলে কিছুই হয় না।
     *
     * ⓘ ফেরত দেয় সারিটা নতুন কি না; ইমপোর্টের ফলাফলে "কয়টা নতুন" বলার
     * জন্য নয় (কাঠামো ওটা নিজেই গোনে), বরং পর্দা থেকে ডাকলে বলার জন্য।
     */
    public function add(
        Account $account,
        string $date,
        ?string $description,
        ?string $reference,
        string $debit,
        string $credit,
        ?string $balance = null,
    ): bool {
        $fingerprint = $this->fingerprint($account, $date, $description, $reference, $debit, $credit);

        $existing = BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->where('fingerprint', $fingerprint)
            ->exists();

        if ($existing) {
            return false;
        }

        BankStatementLine::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'bank_account_id' => $account->id,
            'trx_date' => $date,
            'description' => $description,
            'reference' => $reference,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
            'fingerprint' => $fingerprint,
            'created_by' => auth()->id(),
        ]);

        return true;
    }

    /**
     * ⭐ ব্যাংক যা জানে, আমাদের বই জানে না।
     *
     * ⓘ এই তালিকাটাই মিলকরণের পর্দার নতুন অর্ধেক — এতদিন কেবল উল্টো
     * দিকটা দেখা যেত ("আমাদের কোন সারি ব্যাংকে ওঠেনি")।
     *
     * @return Collection<int, BankStatementLine>
     */
    public function unmatchedFor(Account $account, string $upto): Collection
    {
        return BankStatementLine::query()
            ->unmatched()
            ->where('bank_account_id', $account->id)
            ->where('trx_date', '<=', $upto)
            ->orderBy('trx_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * ব্যাংকের সারিগুলোকে আমাদের সারির সাথে নিজে থেকে মেলানো।
     *
     * ── ⚠️ কীসে "মিল" বলা হয়, আর কীসে বলা হয় না ────────────────────
     * শর্ত তিনটা, আর তিনটাই একসাথে লাগে: **একই টাকা, একই দিক, আর সাত
     * দিনের ভিতরে**। ⓘ সাত দিন, কারণ চেক জমা আর পাশ হওয়ার মাঝে কয়েক দিন
     * যায় — একই দিন ধরলে অর্ধেক চেক মিলত না।
     *
     * ⛔ একাধিক প্রার্থী পাওয়া গেলে **কোনোটাই** মেলানো হয় না। একই দিনে
     * একই অঙ্কের দুইটা সারি থাকলে যন্ত্রের পক্ষে বলা অসম্ভব কোনটা কোনটা,
     * আর ভুল জোড়া বসলে সেটা কেউ কোনোদিন খুঁজে পেত না — তার চেয়ে
     * মানুষটাকে দুইটাই দেখানো ভালো।
     *
     * ⭐ লেনদেন নম্বর আগে (TrxID; cb-ma-4, ২৫ অক্টোবর ২০২৬ — নকশার "যা পরের ধাপে": ব্যাংক স্টেটমেন্টের TrxID মেলানো): ব্যাংকের সারির
     * রেফারেন্স কোনো প্রার্থী ভাউচারের লেনদেন নম্বরের হুবহু হলে, আর এমন ঠিক একটা হলে, সেটাই জোড়া — একই দিনে একই অঙ্কের দুই
     * পরিশোধও তাদের নিজের নম্বরে আলাদা হয় (SAP-এর bank statement "interpretation algorithm": reference আগে, অঙ্ক পরে)।
     * ⓘ বাকি তিন শর্ত (টাকা, দিক, সাত দিন) তখনও লাগে — নম্বর মিলল কিন্তু অঙ্ক নয়, মানে কিছু একটা ভুল, তাই মানুষের সামনে।
     * ⓘ নম্বরে না মিললে আগের মতোই অঙ্ক-দিন-দিক দিয়ে, একাধিক হলে কোনোটা নয়।
     *
     * @return int কয়টা সারি মিলল
     */
    public function matchAgainstBooks(Account $account, string $upto): int
    {
        $matched = 0;

        foreach ($this->unmatchedFor($account, $upto) as $line) {
            /*
             * ⚠️ দিকটা উল্টো করে খোঁজা হয়, আর এটাই সবচেয়ে সহজ ভুল:
             * ব্যাংক যখন টাকা **নেয়** (ব্যাংকের ডেবিট), আমাদের খাতায়
             * ব্যাংক খাতটা **ক্রেডিট** হয় — টাকা বেরিয়ে গেছে।
             */
            $free = $this->freeBookLines($line);

            $candidates = (clone $free)
                ->when($line->bankTookMoney(),
                    fn ($q) => $q->where('credit', $line->debit)->where('debit', 0),
                    fn ($q) => $q->where('debit', $line->credit)->where('credit', 0));

            $reference = trim((string) $line->reference);
            $byNumber = $reference === '' ? collect() : (clone $candidates)
                ->whereHas('voucher', fn ($q) => $q->where('instrument_no', $reference))
                ->limit(2)
                ->get();

            $ours = $byNumber->count() === 1 ? $byNumber : $candidates->limit(2)->get();

            /*
             * ⭐ এক ব্যাংক-সারি, কয়েকটা রসিদ — মালিক, ২১ নভেম্বর ২০২৬ (৳১,০০,০০০ = RCV-0537 + RCV-0538, নম্বর 175300556)।
             * ⓘ একটা জোড়া না পেলে: ব্যাংক-সারির রেফারেন্স যে পাকা কাগজগুলোর লেনদেন নম্বরের হুবহু, একই দিকের, সাত দিনের ভিতরে —
             * দুই বা বেশি, আর তাদের মোট সারির অঙ্কের হুবহু হলে, পুরো দলটাই জোড়া। ⛔ মোট না মিললে কিছুই নয় (মানুষের সামনে)।
             */
            if ($ours->count() !== 1 && $reference !== '') {
                $group = $this->groupByReference($line, $free, $reference);

                if ($group !== null) {
                    $this->writeMatch($line, $group);
                    $matched++;
                }

                continue;
            }

            // ⛔ শূন্য বা একাধিক — দুইটাই "জানি না", আর জানি না মানে ছোঁব না
            if ($ours->count() !== 1) {
                continue;
            }

            $this->writeMatch($line, $ours);

            $matched++;
        }

        return $matched;
    }

    /**
     * ⭐ হাতে মেলানো — এক ব্যাংক-সারি, এক বা কয়েকটা খাতার সারি (মালিক, ২১ নভেম্বর ২০২৬; one-to-many reconciliation)।
     *
     *   · ⛔ মোট = ব্যাংক-সারির অঙ্ক, হুবহু — নিজে থেকে মেলানোর একই নিয়ম (সেখানে কোনো ছাড় নেই, তাই এখানেও নেই)।
     *   · ⛔ প্রতিটা খাতার সারি একবারই (টেবিলের unique শেষ পাহারা); ব্যাংক-সারি আগে মেলানো থাকলে আগে মিল খুলুন।
     *   · ⛔ কেবল পাকা কাগজ, একই ব্যাংক খাত, একই দিক (ব্যাংকে জমা = খাতায় ডেবিট)।
     *
     * @param  list<int>  $voucherLineIds
     */
    public function matchBooks(BankStatementLine $line, array $voucherLineIds): BankStatementLine
    {
        $ids = array_values(array_unique(array_map('intval', $voucherLineIds)));

        if ($ids === []) {
            throw ValidationException::withMessages(['lines' => __('accounts::recon.pick_a_paper')]);
        }

        return DB::transaction(function () use ($line, $ids) {
            $row = BankStatementLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            if ($row->matched_line_id !== null || $row->matches()->exists()) {
                throw ValidationException::withMessages(['line' => __('accounts::recon.line_already_matched')]);
            }

            $ours = VoucherLine::query()->whereKey($ids)->lockForUpdate()->with('voucher')->get();

            if ($ours->count() !== count($ids)) {
                throw ValidationException::withMessages(['lines' => __('accounts::recon.paper_not_found')]);
            }

            foreach ($ours as $ourLine) {
                $voucher = $ourLine->voucher;

                if ($voucher === null || ! $voucher->isPosted() || $voucher->isCancelled()) {
                    throw ValidationException::withMessages(['lines' => __('accounts::recon.paper_not_final', ['no' => (string) $voucher?->document_no])]);
                }
                if ((int) $ourLine->account_id !== (int) $row->bank_account_id) {
                    throw ValidationException::withMessages(['lines' => __('accounts::recon.paper_other_bank', ['no' => $voucher->document_no])]);
                }

                $rightWay = $row->bankTookMoney()
                    ? bccomp((string) $ourLine->credit, '0', 4) > 0 && bccomp((string) $ourLine->debit, '0', 4) === 0
                    : bccomp((string) $ourLine->debit, '0', 4) > 0 && bccomp((string) $ourLine->credit, '0', 4) === 0;
                if (! $rightWay) {
                    throw ValidationException::withMessages(['lines' => __('accounts::recon.paper_other_way', ['no' => $voucher->document_no])]);
                }
            }

            if (BankStatementMatch::holdsAnyOf($ids)) {
                throw ValidationException::withMessages(['lines' => __('accounts::recon.paper_already_matched')]);
            }

            $total = $ours->reduce(fn (string $sum, VoucherLine $l) => bcadd($sum, self::sizeOf($l), 4), '0');
            $want = $row->bankTookMoney() ? (string) $row->debit : (string) $row->credit;

            if (bccomp($total, $want, 4) !== 0) {
                throw ValidationException::withMessages(['lines' => __('accounts::recon.total_must_equal', [
                    'total' => \App\Core\Support\Money::format($total),
                    'line' => \App\Core\Support\Money::format($want),
                ])]);
            }

            $this->writeMatch($row, $ours);

            return $line->setRawAttributes($row->refresh()->getAttributes(), true);
        });
    }

    /**
     * ⭐ মিল খোলা — দলের সবগুলো একসাথে ছাড়া পায় (এক-এক মিলও)। ⛔ নিশ্চিত মিলকরণে টিক পড়া সারি থাকলে নয় — আগে সেটা খুলুন।
     */
    public function unmatchBooks(BankStatementLine $line): BankStatementLine
    {
        return DB::transaction(function () use ($line) {
            $row = BankStatementLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            $ids = $row->matches()->pluck('voucher_line_id')->push($row->matched_line_id)->filter()->unique()->values();

            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['line' => __('accounts::recon.line_not_matched')]);
            }

            $confirmed = VoucherLine::query()->whereKey($ids->all())->whereNotNull('reconciliation_id')
                ->whereHas('reconciliation', fn ($q) => $q->where('status', BankReconciliation::CONFIRMED))
                ->exists();
            if ($confirmed) {
                throw ValidationException::withMessages(['line' => __('accounts::recon.line_in_a_confirmed_recon')]);
            }

            $row->matches()->get()->each->delete(); // ⓘ one by one, so the audit trail hears each unmatch ([[IsAudited]])
            $row->forceFill(['matched_line_id' => null, 'matched_at' => null, 'matched_by' => null])->save();

            return $line->setRawAttributes($row->refresh()->getAttributes(), true);
        });
    }

    /**
     * ⓘ হাতে মেলানোর প্রার্থী — এই খাতের, একই দিকের, ব্যাংক-সারির ±সাত দিনের, এখনো কোনো ব্যাংক-সারিতে না-বসা পাকা কাগজের সারি।
     *
     * @return Collection<int, VoucherLine>
     */
    public function candidatesFor(BankStatementLine $line): Collection
    {
        return $this->freeBookLines($line)
            ->when($line->bankTookMoney(),
                fn ($q) => $q->where('credit', '>', 0)->where('debit', 0)->where('credit', '<=', $line->debit),
                fn ($q) => $q->where('debit', '>', 0)->where('credit', 0)->where('debit', '<=', $line->credit))
            ->with('voucher')
            ->orderBy('id')
            ->limit(50)
            ->get();
    }

    /**
     * ⓘ খাতার যে সারি এই ব্যাংক-সারির জন্য খোলা — একই খাত, টিকহীন, কোনো ব্যাংক-সারিতে বসেনি, পাকা কাগজ, ±সাত দিন।
     *
     * @return \Illuminate\Database\Eloquent\Builder<VoucherLine>
     */
    private function freeBookLines(BankStatementLine $line): \Illuminate\Database\Eloquent\Builder
    {
        return VoucherLine::query()
            ->where('account_id', $line->bank_account_id)
            ->whereNull('reconciliation_id')
            /*
             * ⛔ Accounts-Finance অডিট ম৭, ৪ অক্টোবর ২০২৬ — দুইটা শর্ত যা ছিল না:
             *  · বইয়ের যে সারি আগেই আরেকটা ব্যাংক সারির সাথে মেলানো, সেটা আর নয়। ⚠️ আগে একই দিনে একই অঙ্কের দুই
             *    ব্যাংক সারি পরপর দুই চালানোয় (বা একই চালানোয়) বইয়ের একটা সারিতেই বসত — ব্যাংক বলত দুইবার, বই একবার।
             *  · কেবল পাকা ভাউচার। ⚠️ বাতিল বা খসড়া ভাউচারের সারিও মিলত — যে টাকা খাতায় নেই তার সাথে ব্যাংকের টাকা "মিলে গেল"।
             * ⓘ ২১ নভেম্বর ২০২৬ থেকে মিলের টেবিলও ([[BankStatementMatch]]) — এক ব্যাংক-সারির দলের সারিগুলোও আর নয়।
             */
            ->whereNotIn('id', BankStatementLine::query()->whereNotNull('matched_line_id')->select('matched_line_id'))
            ->whereNotIn('id', BankStatementMatch::query()->select('voucher_line_id'))
            ->whereHas('voucher', fn ($q) => $q
                ->posted()
                ->whereBetween('trx_date', [
                    Carbon::parse($line->trx_date)->subDays(7)->toDateString(),
                    Carbon::parse($line->trx_date)->addDays(7)->toDateString(),
                ]));
    }

    /**
     * ⓘ রেফারেন্সের দল — একই লেনদেন নম্বরের দুই বা বেশি সারি, একই দিকের, মোট = ব্যাংক-সারির অঙ্ক; না হলে null।
     *
     * @param  \Illuminate\Database\Eloquent\Builder<VoucherLine>  $free
     * @return Collection<int, VoucherLine>|null
     */
    private function groupByReference(BankStatementLine $line, \Illuminate\Database\Eloquent\Builder $free, string $reference): ?Collection
    {
        $group = (clone $free)
            ->whereHas('voucher', fn ($q) => $q->where('instrument_no', $reference))
            ->when($line->bankTookMoney(),
                fn ($q) => $q->where('credit', '>', 0)->where('debit', 0),
                fn ($q) => $q->where('debit', '>', 0)->where('credit', 0))
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($group->count() < 2) {
            return null;
        }

        $total = $group->reduce(fn (string $sum, VoucherLine $l) => bcadd($sum, self::sizeOf($l), 4), '0');
        $want = $line->bankTookMoney() ? (string) $line->debit : (string) $line->credit;

        return bccomp($total, $want, 4) === 0 ? $group : null;
    }

    /**
     * ⓘ মিল লেখা — প্রতিটা খাতার সারি মিলের টেবিলে; ব্যাংক-সারির চিহ্নে দলের প্রথমটা।
     *
     * @param  Collection<int, VoucherLine>  $ours
     */
    private function writeMatch(BankStatementLine $line, Collection $ours): void
    {
        $now = Carbon::now();

        foreach ($ours as $ourLine) {
            BankStatementMatch::query()->create([
                'company_id' => $line->company_id,
                'statement_line_id' => $line->id,
                'voucher_line_id' => $ourLine->id,
                'amount' => self::sizeOf($ourLine),
                'matched_by' => auth()->id(),
                'matched_at' => $now,
            ]);
        }

        $line->forceFill([
            'matched_line_id' => $ours->first()->id,
            'matched_at' => $now,
            'matched_by' => auth()->id(),
        ])->save();
    }

    private static function sizeOf(VoucherLine $line): string
    {
        return bccomp((string) $line->debit, '0', 4) > 0 ? (string) $line->debit : (string) $line->credit;
    }

    /**
     * একই ফাইল দুইবার তুললে সারি দ্বিগুণ না হওয়ার ছাপ।
     *
     * ── ⚠️ এখানকার আসল ধাঁধাটা ──────────────────────────────────────
     * দুইটা অবস্থা হুবহু একরকম দেখায়, অথচ উত্তর উল্টো:
     *   ১. একই ফাইল আবার তোলা হলো → সারিগুলো **বসবে না**
     *   ২. একই দিনে সত্যিই দুইবার ৫০০ টাকা তোলা → **দুইটাই বসবে**
     *
     * ⛔ ডেটাবেস দেখে এই দুইটা আলাদা করা যায় না — দুই ক্ষেত্রেই সারিটা
     * "আগে থেকে আছে"। ⓘ উত্তরটা ফাইলের ভিতরে: ঐ **এক ফাইলে** এটা কত
     * নম্বর একই রকম সারি। প্রথম ফাইলে দুইটা থাকলে ওরা #০ আর #১ হয়ে
     * দুইটাই বসে; ঐ ফাইলটাই আবার তুললে আবার #০ আর #১ হয়, আর দুইটাই
     * চেনা পড়ে।
     *
     * ⓘ গোনাটা এই অবজেক্টেই রাখা যায়, কারণ [[ImportRunner::run()]]
     * ইমপোর্টারটা একবার বানিয়ে গোটা ফাইলের সব সারিতে ঐ একটাই ব্যবহার
     * করে — অর্থাৎ এক ফাইল, এক গোনা।
     *
     * @var array<string, int>
     */
    private array $seenInThisFile = [];

    private function fingerprint(
        Account $account,
        string $date,
        ?string $description,
        ?string $reference,
        string $debit,
        string $credit,
    ): string {
        $base = implode('|', [
            $account->id, $date, (string) $description, (string) $reference, $debit, $credit,
        ]);

        $nth = $this->seenInThisFile[$base] ?? 0;

        $this->seenInThisFile[$base] = $nth + 1;

        return hash('sha256', $base.'#'.$nth);
    }
}
