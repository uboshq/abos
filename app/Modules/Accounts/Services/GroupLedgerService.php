<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * এক মালিকের একাধিক কোম্পানির হিসাব এক পাতায়।
 *
 * ── ⭐ মালিকের প্রশ্ন, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"এক মালিকের একাধিক কোম্পানি থাকলে কী হবে? সে তো একসাথে হিসাব দেখতে
 * চাইবে।"* ⓘ আজ দেখতে হয় এক কোম্পানি করে, সুইচার দিয়ে বদলে বদলে —
 * তিনটা কোম্পানির ছবি এক পাতায় দেখার কোনো উপায় নেই।
 *
 * ── ⛔ দেয়ালটা এখানে ভাঙা হয় না, আর কেন ─────────────────────────────
 * ⚠️ ABOS বহু-ক্রেতার পণ্য, আর টেন্যান্ট বিচ্ছিন্নতা সুবিধা নয় —
 * **আইনি বাধ্যবাধকতা**। তাই ছাঁকনিটা কখনো *"সব কোম্পানি"* নয়, সবসময়
 * *"এই ব্যবহারকারী **যে কোম্পানিগুলোতে আছেন**"* — `company_user` পিভট ধরে।
 *
 * ⭐ আর কোয়েরিটা ইচ্ছাকৃতভাবে [[DB]] query builder-এ, Eloquent-এ নয়।
 * ⓘ [[BelongsToCompany]]-র দেয়ালটা একটা **Eloquent গ্লোবাল স্কোপ**, তাই
 * query builder ওটা দেখেই না — ফলে দেয়াল খোলার, সুইচ বসানোর বা
 * [[SharedAcrossCompaniesWhenAsked]] ছোঁয়ার কোনো দরকার নেই।
 *
 * ⚠️ এটাই নিরাপদ দিক: দেয়াল চওড়া করলে **অন্য প্রতিটা কোয়েরিও** চওড়া
 * হওয়ার ঝুঁকিতে পড়ত। এখানে কোম্পানিগুলো নাম ধরে বসে, একটাই কোয়েরিতে,
 * আর সেটা কেবল **পড়ে** — একটা সারিও লেখা হয় না।
 *
 * ── ⚠️ কেন অঙ্কগুলো স্ট্রিং, float নয় ───────────────────────────────
 * কলামগুলো `decimal(18,4)`। float-এ যোগ করলে তিন কোম্পানির যোগফলে
 * পয়সার ঘরে ভুল জমত, আর সেটা দেখতে ঠিক সঠিকের মতোই লাগত। ⓘ তাই
 * সবটা bcmath-এ, scale ৪ — পাহারা দেয় [[MoneyIsNeverAFloatTest]]।
 */
final class GroupLedgerService
{
    /** ⓘ `decimal(18,4)` — তাই চারটা ঘর, কম নয়। */
    private const SCALE = 4;

    /**
     * ব্যবহারকারীর প্রতিটা কোম্পানির ছবি, আর নিচে গ্রুপের যোগফল।
     *
     * @return array{
     *     companies: list<array<string, string|int>>,
     *     total: array<string, string>,
     *     from: string,
     *     to: string,
     *     single: bool
     * }
     */
    public function build(User $user, ?string $from = null, ?string $to = null): array
    {
        $from ??= Carbon::today()->startOfMonth()->toDateString();
        $to ??= Carbon::today()->toDateString();

        /*
         * ⚠️ উল্টো তারিখ চুপচাপ খালি পাতা দিত।
         *
         * ⓘ `between` উল্টো সীমায় কোনো সারিই ফেরায় না — কোনো ত্রুটি
         * ছাড়াই। তাই দুইটা তারিখ অদলবদল করে দেওয়া হয়, কারণ মানুষ
         * ছাঁকনিতে ভুল ক্রমে তারিখ বসায় আর সেটা ভুল নয়, অসতর্কতা।
         */
        if (Carbon::parse($from)->gt(Carbon::parse($to))) {
            [$from, $to] = [$to, $from];
        }

        $mine = $this->companiesOf($user);

        if ($mine === []) {
            return [
                'companies' => [],
                'total' => $this->zeroes(),
                'from' => $from,
                'to' => $to,
                'single' => false,
            ];
        }

        $sums = $this->sumsByCompanyAndType(array_keys($mine), $from, $to);

        $companies = [];
        $total = $this->zeroes();

        foreach ($mine as $id => $name) {
            $row = ['id' => $id, 'name' => $name] + $this->zeroes();

            foreach (Account::TYPES as $type) {
                $row[$type] = $sums[$id][$type] ?? '0';
                $total[$type] = bcadd($total[$type], $row[$type], self::SCALE);
            }

            $row['profit'] = bcsub($row[Account::INCOME], $row[Account::EXPENSE], self::SCALE);
            $companies[] = $row;
        }

        $total['profit'] = bcsub($total[Account::INCOME], $total[Account::EXPENSE], self::SCALE);

        return [
            'companies' => $companies,
            'total' => $total,
            'from' => $from,
            'to' => $to,
            /*
             * ⓘ একটাই কোম্পানি হলে পর্দা বলে দেয় — নাহলে "গ্রুপ রিপোর্ট"
             * নামে একটা পাতা একটামাত্র সারি দেখাত আর মনে হত কিছু হারিয়েছে।
             */
            'single' => count($companies) === 1,
        ];
    }

    /**
     * ⭐ এই ব্যবহারকারী যে কোম্পানিগুলোতে আছেন — পিভট ধরে, আর কিছু নয়।
     *
     * @return array<int, string>
     */
    private function companiesOf(User $user): array
    {
        /*
         * ⛔ `Company::all()` নয়, কখনো।
         *
         * ⚠️ `companies` টেবিলে [[BelongsToCompany]] নেই (সে নিজেই
         * কোম্পানি), তাই ওখানে কোনো দেয়াল নেই — একটা অসতর্ক
         * `Company::all()` অন্য ক্রেতার নাম এনে ফেলত। ⓘ তাই পিভটই
         * একমাত্র উৎস।
         */
        return $user->companies()
            ->orderBy('companies.name_en')
            ->pluck('companies.name_en', 'companies.id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * একটাই কোয়েরি — কোম্পানি ও হিসাবের ধরন ধরে যোগফল।
     *
     * @param  list<int>  $companyIds
     * @return array<int, array<string, string>>
     */
    private function sumsByCompanyAndType(array $companyIds, string $from, string $to): array
    {
        /*
         * ⚠️ লাইভের MySQL-এ `ONLY_FULL_GROUP_BY` চালু।
         *
         * ⓘ তাই যে দুইটা কলাম নির্বাচন করা হয় সেই দুইটাই `group by`-তে
         * আছে। ⛔ নাহলে কোয়েরিটা স্থানীয়ভাবে পাস করত আর লাইভে ৫০০ দিত
         * ([[live-mysql-uses-only-full-group-by]])।
         *
         * ⚠️ আর `voucher_lines`-এ `company_id` **নেই** — কোম্পানি আসে
         * `vouchers` ধরে, তাই ছাঁকনিটা ঐ টেবিলে বসে।
         */
        $rows = DB::table('voucher_lines')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_lines.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_lines.account_id')
            ->whereIn('vouchers.company_id', $companyIds)
            /*
             * ⓘ খাতায় যা সত্যিই গোনা হয়: খসড়া নয়, বাতিল নয়।
             * ⚠️ তালিকাটা [[DocumentStatus::POSTED]] থেকে নেওয়া, হাতে
             * লেখা নয় — নাহলে একদিন একটা নতুন অবস্থা যোগ হত আর এই
             * রিপোর্ট নীরবে ওটা বাদ দিত।
             */
            ->whereIn('vouchers.status', DocumentStatus::POSTED)
            ->whereBetween('vouchers.trx_date', [$from, $to])
            /*
             * ⭐ `nature`-ও গোষ্ঠীতে, আর সেটা ইচ্ছাকৃত।
             *
             * ⛔ প্রথমে ধরন থেকে দিক ঠিক করতে যাচ্ছিলাম
             * ([[Account::defaultNatureFor()]] দিয়ে), আর সেটা ভুল হত:
             * ⚠️ ঐ পদ্ধতিটার নিজের মন্তব্য বলে ওটা **নতুন খাতের ডিফল্ট,
             * বাধ্যতামূলক নয়** — "সঞ্চিত অবচয়" সম্পদ হয়েও ক্রেডিট
             * প্রকৃতির, আর সেটা হাতে বদলানো যায়।
             *
             * ⓘ ফলে ধরন ধরে চিহ্ন বসালে অবচয়ের অঙ্কটা **উল্টো দিকে**
             * যোগ হত — সম্পদ বেশি দেখাত, আর সংখ্যাটা দেখতে সঠিকের
             * মতোই লাগত। তাই প্রতিটা খাতের নিজের `nature`-ই ধরা হয়।
             */
            ->groupBy('vouchers.company_id', 'accounts.type', 'accounts.nature')
            ->select([
                'vouchers.company_id',
                'accounts.type',
                'accounts.nature',
                DB::raw('SUM(voucher_lines.debit) as debit'),
                DB::raw('SUM(voucher_lines.credit) as credit'),
            ])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $type = (string) $row->type;

            $net = (string) $row->nature === Account::DEBIT
                ? bcsub((string) $row->debit, (string) $row->credit, self::SCALE)
                : bcsub((string) $row->credit, (string) $row->debit, self::SCALE);

            /*
             * ⓘ এক ধরনে এখন একাধিক সারি আসতে পারে (দুই প্রকৃতির খাত),
             * তাই বসানো নয় — যোগ করা।
             */
            $out[(int) $row->company_id][$type] = bcadd(
                $out[(int) $row->company_id][$type] ?? '0',
                $net,
                self::SCALE,
            );
        }

        return $out;
    }

    /** @return array<string, string> */
    private function zeroes(): array
    {
        $out = [];

        foreach (Account::TYPES as $type) {
            $out[$type] = '0';
        }

        $out['profit'] = '0';

        return $out;
    }
}
