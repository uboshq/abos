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

    /**
     * শীর্ষ কয়টা খাত, **একটাই কোয়েরিতে** — ড্যাশবোর্ডের জন্য।
     *
     * ── কেন `forParent()` এখানে খাটে না ──────────────────────────────
     * সে প্রতিটা খাতে **দুইবার** ডাটাবেসে যায় (চলতি ও আগের সময়কাল),
     * কারণ খরচের পাতায় তুলনার তীরটা দরকার। ⚠️ মেপে দেখা: ২১টা খাতে
     * **৪৪টা কোয়েরি**।
     *
     * খরচের পাতায় ওটা ঠিক আছে — কেউ দিনে একবার খোলেন। কিন্তু
     * ড্যাশবোর্ড রোজ, বারবার খোলা হয়, আর সেখানে এটাই সেই ধীরতা যার
     * **একটাও ধীর কোয়েরি নেই**, তবু পাতাটা ধীর।
     *
     * ⓘ `forParent()` অপরিবর্তিত রাখা হয়েছে — খরচের পাতার আচরণ বদলানোর
     * কোনো কারণ নেই, আর তুলনার তীরটাও ওখানেই দরকার।
     *
     * @return list<array{label: string, amount: string}>
     */
    public function topUnder(string $parentCode, string $from, string $to, int $limit = 6): array
    {
        $parent = Account::query()->where('code', $parentCode)->first();

        if ($parent === null) {
            return [];
        }

        /*
         * ⚠️ **পুরো বংশ, এক ধাপ নয়।**
         *
         * আজ ৫২০০-এর নিচে সবগুলোই পাতা। কিন্তু ছকটা ক্রেতা নিজে
         * বাড়াতে পারেন — যেদিন কেউ "গাড়ির ভাড়া → ট্রাক ভাড়া · পিকআপ
         * ভাড়া" বানাবেন, সেদিন দাখিলা বসবে নাতির ঘরে, আর এক ধাপ দেখা
         * কোয়েরিতে **ওই টাকাটা তালিকা থেকে পুরোপুরি হারিয়ে যেত** —
         * কম দেখাত না, একেবারেই দেখাত না।
         *
         * ⓘ কিন্তু দেখানো হয় **খাতের নামে**, নাতির নামে নয়: তালিকাটার
         * শিরোনাম "খরচের খাত", আর খাত মানে ৫২০০-এর সরাসরি সন্তান।
         * নাতির টাকা তার নিজের খাতে উঠে আসে — তাই তালিকাটা স্থির থাকে,
         * আর কোনো টাকা হারায় না।
         */
        $family = $parent->selfAndDescendants()->keyBy('id');

        // নাতি → তার খাত (৫২০০-এর সরাসরি সন্তান)
        $headOf = [];

        foreach ($family as $node) {
            if ($node->id === $parent->id) {
                continue;
            }

            $walk = $node;

            // ৩২ ধাপের সীমা — চক্র থাকলে থেমে যেতে হবে, `Account`-এর মতোই
            for ($depth = 0; $depth < 32 && $walk->parent_id !== $parent->id; $depth++) {
                $up = $family->get($walk->parent_id);

                if ($up === null) {
                    break;
                }

                $walk = $up;
            }

            $headOf[$node->id] = $walk->id;
        }

        $rows = DB::table('ledger_entries as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.id', array_keys($headOf))
            /*
             * ⚠️ `DB::table()` মডেলের গ্লোবাল স্কোপ **এড়িয়ে যায়**, তাই
             * কোম্পানিটা হাতে বসানো — ঠিক যেভাবে `sum()`-এও বসানো আছে।
             *
             * বাবার খাতটা কোম্পানি-স্কোপড বলে সন্তানেরাও এই কোম্পানিরই,
             * কিন্তু খতিয়ানের সারিতে ভরসা করা যায় না: **বহু-টেন্যান্টে
             * বিচ্ছিন্নতা সুবিধা নয়, আইনি বাধ্যবাধকতা।**
             */
            ->where('l.company_id', CompanyContext::id())
            ->whereBetween('l.trx_date', [$from, $to])
            ->groupBy('a.id')
            ->selectRaw('a.id, COALESCE(SUM(l.debit) - SUM(l.credit), 0) as net')
            ->get();

        // নাতির টাকা তার খাতে তোলা — তাই যোগটা এখানে, SQL-এ নয়
        $byHead = [];

        foreach ($rows as $row) {
            $head = $headOf[$row->id] ?? null;

            if ($head === null) {
                continue;
            }

            $byHead[$head] = bcadd($byHead[$head] ?? '0', (string) $row->net, 4);
        }

        // শূন্য বা ঋণাত্মক খাত তালিকায় আসে না — "কোথায় গেল" প্রশ্নের উত্তর নয়
        $byHead = array_filter($byHead, fn (string $net) => bccomp($net, '0', 4) > 0);

        arsort($byHead);

        $top = array_slice($byHead, 0, $limit, true);

        /*
         * নামগুলো আলাদা করে, **একবারেই**।
         *
         * ⓘ `selfAndDescendants()` কেবল `id` ও `parent_id` আনে (সে
         * গাছটা বানানোর জন্যই ডাকা), তাই ওখানে নাম নেই। শীর্ষ ছয়টার
         * নাম একটা কোয়েরিতে আনা হয় — প্রতি সারিতে ডাকলে ছয়টা কোয়েরি হত।
         */
        $names = Account::query()->whereIn('id', array_keys($top))->get()->keyBy('id');

        $out = [];

        foreach ($top as $id => $net) {
            $out[] = [
                'label' => $names->get($id)?->name() ?? '—',
                'amount' => $net,
            ];
        }

        return $out;
    }
}
