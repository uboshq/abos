<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * কোন খাতে কত — আর আগের সমান সময়ের চেয়ে বেশি না কম।
 *
 * ── কেন এটা আলাদা সেবা, ৩০ আগস্ট ২০২৬ ────────────────────────────────
 * খরচের পর্দাটা এই হিসাবটা করত। আয়ের পর্দা বানাতে গিয়ে দেখা গেল
 * প্রশ্নটা **হুবহু এক** — কেবল মাথাটা আলাদা (৫২০০ বনাম ৪০০০) আর চিহ্নটা
 * উল্টো (খরচ ডেবিট, আয় ক্রেডিট)।
 *
 * কপি করে বসালে দুইটা পর্দা একই কাজ দুইভাবে করত, আর একদিন একটায়
 * সংশোধন হত অন্যটায় নয় — তখন একই ব্যবসার আয় আর খরচ দুই নিয়মে গোনা হত।
 *
 * ── কেন খতিয়ান থেকে, ভাউচার থেকে নয় ─────────────────────────────────
 * একই খাতে টাকা আসে অন্য পথেও: বিক্রয় বিলের আয়, ক্রয় বিলের হাম্মালি।
 * ভাউচার গুনলে ওগুলো বাদ পড়ত, আর ব্যবহারকারী এমন একটা সংখ্যা দেখতেন
 * যা খাতার সাথে মেলে না — আর দুইটার কোনটা সত্যি তা কেউ বলতে পারত না।
 */
final class HeadTotals
{
    /**
     * এই মাথার নিচের খাতগুলো, দুই সময়ের অঙ্কসহ।
     *
     * @param  string  $parentCode  ছকের মাথা — ৫২০০ পরিচালন ব্যয়, ৪০০০ আয়
     * @param  bool  $debitPositive  খরচে সত্যি, আয়ে মিথ্যা
     * @return list<array{account: Account, now: string, before: string}>
     */
    public function forParent(string $parentCode, string $from, string $to, bool $debitPositive): array
    {
        $parent = Account::query()->where('code', $parentCode)->first();

        if ($parent === null) {
            return [];
        }

        $accounts = Account::query()
            ->where('parent_id', $parent->id)
            ->orderBy('code')
            ->get();

        /*
         * আগের সমান দৈর্ঘ্যের সময়টা।
         *
         * "গত মাস" নয়, **সমান দৈর্ঘ্য** — কেউ দশ দিনের পরিসর দেখলে
         * তুলনাটাও আগের দশ দিনের হওয়া উচিত। গত মাসের সাথে মেলালে
         * সংখ্যাটা তিনগুণ দেখাত আর তুলনাটা অর্থহীন হত।
         */
        $span = max(1, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $prevFrom = Carbon::parse($from)->subDays($span)->toDateString();
        $prevTo = Carbon::parse($from)->subDay()->toDateString();

        $rows = [];

        foreach ($accounts as $account) {
            $now = $this->sum($account, $from, $to, $debitPositive);
            $before = $this->sum($account, $prevFrom, $prevTo, $debitPositive);

            /*
             * যে খাতে দুই সময়েই কিছু হয়নি সেটা দেখানো হয় না।
             *
             * ছকের বেশিরভাগ খাত একটা ডিপোতে সারা বছরেও ছোঁয়া হয় না।
             * সবগুলো দেখালে পর্দাটা শূন্যে ভরে যেত, আর যেটা সত্যিই
             * বেড়েছে সেটা তার মধ্যে হারাত।
             */
            if (bccomp($now, '0', 4) === 0 && bccomp($before, '0', 4) === 0) {
                continue;
            }

            $rows[] = ['account' => $account, 'now' => $now, 'before' => $before];
        }

        /* সবচেয়ে বড়টা আগে — এক নজরে যেটা দেখার, সেটাই উপরে */
        usort($rows, fn (array $a, array $b) => bccomp($b['now'], $a['now'], 4));

        return $rows;
    }

    private function sum(Account $account, string $from, string $to, bool $debitPositive): string
    {
        $expression = $debitPositive
            ? 'COALESCE(SUM(debit) - SUM(credit), 0) as net'
            : 'COALESCE(SUM(credit) - SUM(debit), 0) as net';

        $net = DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $account->id)
            ->whereBetween('trx_date', [$from, $to])
            ->selectRaw($expression)
            ->value('net');

        return (string) ($net ?: '0');
    }
}
