<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
            if ($date !== false && $date->format($format) === $value) {
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
            ->whereDate('trx_date', '<=', $upto)
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
            $ours = VoucherLine::query()
                ->where('account_id', $account->id)
                ->whereNull('reconciliation_id')
                ->whereHas('voucher', fn ($q) => $q
                    ->whereBetween('trx_date', [
                        Carbon::parse($line->trx_date)->subDays(7)->toDateString(),
                        Carbon::parse($line->trx_date)->addDays(7)->toDateString(),
                    ]))
                ->when($line->bankTookMoney(),
                    fn ($q) => $q->where('credit', $line->debit)->where('debit', 0),
                    fn ($q) => $q->where('debit', $line->credit)->where('credit', 0))
                ->limit(2)
                ->get();

            // ⛔ শূন্য বা একাধিক — দুইটাই "জানি না", আর জানি না মানে ছোঁব না
            if ($ours->count() !== 1) {
                continue;
            }

            $line->forceFill([
                'matched_line_id' => $ours->first()->id,
                'matched_at' => Carbon::now(),
                'matched_by' => auth()->id(),
            ])->save();

            $matched++;
        }

        return $matched;
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
