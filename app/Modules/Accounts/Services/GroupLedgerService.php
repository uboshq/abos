<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Models\UserDataScope;
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

        $sums = $this->sumsByCompanyAndType($this->branchReach($user, array_keys($mine)), $from, $to);

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
        /*
         * ⛔ প্রতিটা কোম্পানিতে নিজের চাবি — অডিট ⛔১০ (৬ অক্টোবর ২০২৬)। দরজা কেবল চলতি কোম্পানির `accounts.report.group`
         * দেখত, আর তালিকায় আসত সদস্য সব কোম্পানির আয়-ব্যয়-লাভ। যে কোম্পানিতে এই চাবি নেই, তার সংখ্যা এখানে আসে না
         * ([[User::canInCompany()]] — ভাই-কোম্পানির টাকার পাতার একই নিয়ম)।
         */
        return $user->companies()
            ->orderBy('companies.name_en')
            ->pluck('companies.name_en', 'companies.id')
            // ⓘ চলতি কোম্পানির চাবি দরজাই দেখে নিয়েছে ([[GroupReportController::middleware()]]); বাকিগুলো এখানে
            ->filter(fn ($name, $id) => (int) $id === CompanyContext::id() || $user->canInCompany((int) $id, 'accounts.report.group'))
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * ⛔ প্রতিটা কোম্পানিতে মানুষটার নিজের শাখার নাগাল — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৪)।
     *
     * ⚠️ আগে যোগফল গোটা কোম্পানির: শাখা A-তে সীমিত মানুষও দলগত পাতায় কোম্পানির সব শাখার আয়-ব্যয়-লাভ দেখতেন — যা নিজের
     * কোম্পানির স্থিতিপত্রেই তাঁর কাছে ঢাকা ([[BalanceSheetService]], অডিট ⛔১১)।
     * ⓘ নাগাল কোম্পানিভেদে আলাদা ([[DataScope::idsFor()]] চলতি কোম্পানি ধরে), তাই প্রতিটা কোম্পানির প্রসঙ্গে আলাদা করে পড়া।
     * হেডারে বাছা শাখা নয় — সেটার আইডি কেবল চলতি কোম্পানির ([[EveryLedgerReaderSaysWhetherItShowsOrChecksTest]])।
     *
     * @param  list<int>  $companyIds
     * @return array<int, list<int>|null> কোম্পানি => নাগালের শাখা; `null` মানে সীমা নেই
     */
    private function branchReach(User $user, array $companyIds): array
    {
        $reach = [];

        foreach ($companyIds as $id) {
            $reach[$id] = CompanyContext::forCompany($id, fn () => app(DataScope::class)->idsFor($user, UserDataScope::BRANCH));
        }

        return $reach;
    }

    /**
     * একটাই কোয়েরি — কোম্পানি ও হিসাবের ধরন ধরে যোগফল।
     *
     * @param  array<int, list<int>|null>  $reach  কোম্পানি => নাগালের শাখা ([[branchReach()]])
     * @return array<int, array<string, string>>
     */
    private function sumsByCompanyAndType(array $reach, string $from, string $to): array
    {
        /*
         * ⚠️ লাইভের MySQL-এ `ONLY_FULL_GROUP_BY` চালু।
         *
         * ⓘ তাই যে দুইটা কলাম নির্বাচন করা হয় সেই দুইটাই `group by`-তে
         * আছে। ⛔ নাহলে কোয়েরিটা স্থানীয়ভাবে পাস করত আর লাইভে ৫০০ দিত
         * ([[live-mysql-uses-only-full-group-by]])।
         *
         * ── ⭐ উৎসটা `ledger_entries`, `voucher_lines` নয় — নিরীক্ষা §২ ──
         * ⛔ আগে যোগফল আসত `voucher_lines` থেকে। ⓘ কিন্তু বিক্রয়, ক্রয়
         * আর বেতন **ভাউচার দিয়ে যায় না** — ওরা সরাসরি পোস্টিং ইঞ্জিন
         * দিয়ে খতিয়ানে বসে ([[PostingEngine::post()]])।
         *
         * ⚠️ ফল: গ্রুপের পাতায় ঐ তিনটাই **শূন্য** দেখাত, অথচ প্রতিটা
         * কোম্পানির নিজের রিপোর্টে সংখ্যাগুলো ঠিকই ছিল। ⛔ আর শূন্য
         * দেখতে ভাঙা লাগে না — দেখতে লাগে *"এই মাসে কিছু হয়নি"*।
         *
         * ⭐ ভাউচারও খতিয়ানেই বসে (একই ইঞ্জিন), তাই উৎস বদলে কিছু
         * হারায় না — কেবল যা বাদ পড়ছিল তা যোগ হয়। ⓘ আর খতিয়ানে
         * `company_id` নিজেরই কলাম, তাই ভাউচারের টেবিলে জোড়াটাও লাগে না।
         */
        $rows = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            // ⓘ সীমিত কোম্পানিতে নাগালের শাখা আর শাখাহীন সারি — "সব শাখা"-র নিয়ম ([[DataScope::inView()]])
            ->where(function ($q) use ($reach) {
                foreach ($reach as $companyId => $branches) {
                    $q->orWhere(fn ($one) => $one->where('ledger_entries.company_id', $companyId)
                        ->when($branches !== null, fn ($w) => $w->where(fn ($b) => $b
                            ->whereIn('ledger_entries.branch_id', $branches)
                            ->orWhereNull('ledger_entries.branch_id'))));
                }
            })
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            // ⛔ বছর বন্ধের দাখিলা বাদ — নইলে বছরের শেষ দিন পড়লে আয়-খরচ শূন্য দেখাত ([[YearEndService::closingSources()]])
            // ⓘ কেবল আয়-খরচের সারিতে — সঞ্চিত মুনাফার (মূলধন) দিকটা থাকে, নইলে মালিকানার জের বদলাত
            ->where(fn ($q) => $q->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
                ->orWhereNotIn('accounts.type', [Account::INCOME, Account::EXPENSE]))

            /*
             * ⭐ `nature`-ও গোষ্ঠীতে, আর সেটা ইচ্ছাকৃত।
             *
             * ⛔ প্রথমে ধরন থেকে দিক ঠিক করতে যাচ্ছিলাম
             * ([[Account::defaultNatureFor()]] দিয়ে), আর সেটা ভুল হত:
             * ⚠️ ऐ পদ্ধতিটার নিজের মন্তব্য বলে ওটা **নতুন খাতের ডিফল্ট,
             * বাধ্যতামূলক নয়** — "সঞ্চিত অবচয়" সম্পদ হয়েও ক্রেডিট
             * প্রকৃতির, আর সেটা হাতে বদলানো যায়।
             *
             * ⓘ ফলে ধরন ধরে চিহ্ন বসালে অবচয়ের অংকটা **উল্টো দিকে**
             * যোগ হত — সম্পদ বেশি দেখাত, আর সংখ্যাটা দেখতে সঠিকের
             * মতোই লাগত। তাই প্রতিটা খাতের নিজের `nature`-ই ধরা হয়।
             */
            ->groupBy('ledger_entries.company_id', 'accounts.type', 'accounts.nature')
            ->select([
                'ledger_entries.company_id',
                'accounts.type',
                'accounts.nature',
                DB::raw('SUM(ledger_entries.debit) as debit'),
                DB::raw('SUM(ledger_entries.credit) as credit'),
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
