<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * মাসওয়ারি টাকা আসা-যাওয়া — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬ ("এখনি লাগবে")।
 *
 * ── কোন প্রশ্নের উত্তর ──────────────────────────────────────────────────
 * *"প্রতি মাসে টাকা কোথা থেকে এল, কোথায় গেল, আর মাস শেষে হাতে কত রইল"* — নগদ, ব্যাংক
 * আর মোবাইল ব্যাংকিং মিলিয়ে। প্রতিটা মাস এক সারি: শুরুর জের, ঢোকা (ভাগ করে), বেরোনো
 * (ভাগ করে), নিট, শেষের জের। [[CoreReports::cashFlow()]] দিনে দিনে কেবল মোট ঢোকা-বেরোনো
 * বলে; কোন খাতে, সেটা বলে না।
 *
 * ── ⓘ ভাগটা কীভাবে ঠিক হয় ───────────────────────────────────────────────
 * খাতা থেকে, একটা কাগজের (source) উল্টো পাশের সারি দেখে — টাকার খাতের সারিটা নিজে কিছু বলে না:
 *   · ঢোকা: গ্রাহক (পক্ষ গ্রাহক, পাওনা ১১১০ বা হাতে থাকা চেক ১১০৪) → আদায়; আয়ের খাত → অন্যান্য
 *     আয়; মূলধন বা দায় (ঋণ) → মূলধন/ঋণ; উল্টো পাশে কেবল টাকার খাত → স্থানান্তর; বাকি → অন্যান্য।
 *   · বেরোনো: সরবরাহকারী (পক্ষ সরবরাহকারী বা প্রদেয় ২১১১/২১১৫–২১১৮) → সরবরাহকারীকে; বেতন
 *     (২১৩০/২১৩১/৫২০১/১১৩১) → বেতন; খরচের খাত → খরচ; মূলধন বা দায় → উত্তোলন/ঋণ; কেবল টাকার
 *     খাত → স্থানান্তর; বাকি (যেমন সম্পদ কেনা) → খরচ ও অন্যান্য।
 * ⚠️ একটা কাগজে কয়েক রকম উল্টো সারি থাকলে উপরের ক্রমে প্রথমটা জেতে — যোগফল তবু মেলে।
 *
 * ── ⛔ দেখানো খাতগুলোর ভিতরের স্থানান্তর কাটাকাটি ──────────────────────────
 * নগদ থেকে ব্যাংকে জমা দুই পাশেই টাকার খাত — দুইটাই দেখানো হলে ঢোকা আর বেরোনো দুই দিকেই একই অঙ্ক
 * ফুলে উঠত, অথচ ব্যবসায় একটা টাকাও ঢোকেনি। তাই একই কাগজের দেখানো ঢোকা আর বেরোনোর মিলে যাওয়া
 * অংশ বাদ যায়; স্থানান্তরের ঘরে আসে কেবল দেখার বাইরের খাত (অন্য শাখা, বা বাছা খাতের বাইরের খাত)
 * থেকে বা সেখানে যাওয়া টাকা। নিট আর জের এতে বদলায় না।
 *
 * ⓘ শাখার দেয়াল টাকার সারিতেই ([[ReportEngine::branchWall()]]); টাকার খাত বাছলে কেবল সেটা।
 * ⓘ যে মাসে কিছুই নড়েনি সে মাসের সারি আসে না — পরের মাসের শুরুর জের সেটা বহন করে।
 */
final class MonthlyCashReport
{
    public const KEY = 'accounts.monthly_cash';

    /** উল্টো পাশের সারি কোন ভাগের — খাতের কোড দিয়ে চেনা অংশগুলো */
    private const CUSTOMER_CODES = [StandardChart::RECEIVABLE, StandardChart::CHEQUES_IN_HAND];

    private const SUPPLIER_CODES = [
        StandardChart::PAYABLE, StandardChart::CHEQUES_ISSUED, StandardChart::TRANSPORT_PAYABLE,
        StandardChart::LABOUR_PAYABLE, StandardChart::VENDOR_PAYABLE,
    ];

    private const SALARY_CODES = [
        StandardChart::SALARY_PAYABLE, StandardChart::PROVIDENT_FUND_PAYABLE,
        StandardChart::SALARY_EXPENSE, StandardChart::EMPLOYEE_ADVANCE,
    ];

    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — আদায়ের তালিকা আর নগদ বইয়ের একই চাবি
            permission: 'accounts.report',
            title: 'accounts::menu.monthly_cash',
            filters: ['date_range', 'branch', 'account'],
            summary: fn (array $totals): array => [
                'label' => __('accounts::message.monthly_cash_net'),
                'value' => bcadd((string) ($totals['net'] ?? '0'), '0', 2),
                'good' => bccomp((string) ($totals['net'] ?? '0'), '0', 4) >= 0,
            ],
            query: fn (array $f) => self::query($f),
            columns: [
                ['key' => 'month_name', 'label' => 'accounts::field.month', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'opening', 'label' => 'accounts::field.cash_opening', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'in_collections', 'label' => 'accounts::field.in_collections', 'type' => ReportColumn::MONEY],
                ['key' => 'in_other', 'label' => 'accounts::field.in_other', 'type' => ReportColumn::MONEY],
                ['key' => 'in_finance', 'label' => 'accounts::field.in_finance', 'type' => ReportColumn::MONEY],
                ['key' => 'in_transfer', 'label' => 'accounts::field.in_transfer', 'type' => ReportColumn::MONEY],
                ['key' => 'total_in', 'label' => 'accounts::field.total_in', 'type' => ReportColumn::MONEY],
                ['key' => 'out_suppliers', 'label' => 'accounts::field.out_suppliers', 'type' => ReportColumn::MONEY],
                ['key' => 'out_expenses', 'label' => 'accounts::field.out_expenses', 'type' => ReportColumn::MONEY],
                ['key' => 'out_salaries', 'label' => 'accounts::field.out_salaries', 'type' => ReportColumn::MONEY],
                ['key' => 'out_finance', 'label' => 'accounts::field.out_finance', 'type' => ReportColumn::MONEY],
                ['key' => 'out_transfer', 'label' => 'accounts::field.out_transfer', 'type' => ReportColumn::MONEY],
                ['key' => 'total_out', 'label' => 'accounts::field.total_out', 'type' => ReportColumn::MONEY],
                ['key' => 'net', 'label' => 'accounts::field.net', 'type' => ReportColumn::MONEY],
                ['key' => 'closing', 'label' => 'accounts::field.cash_closing', 'type' => ReportColumn::MONEY, 'total' => false],
            ],
        );
    }

    /**
     * চলতি অর্থবছরের শুরু — পর্দার ডিফল্ট "থেকে" ([[BalanceSheetService]]-এর একই নিয়ম: বছর বসানো না
     * থাকলে ১ জুলাই, বাংলাদেশের অর্থবছর)।
     */
    public static function yearStart(?string $on = null): string
    {
        $on ??= Carbon::today()->toDateString();

        $start = DB::table('financial_years')
            ->where('company_id', CompanyContext::id())
            ->where('starts_on', '<=', $on)
            ->where('ends_on', '>=', $on)
            ->value('starts_on');

        if ($start !== null) {
            return Carbon::parse((string) $start)->toDateString();
        }

        $date = Carbon::parse($on);

        return Carbon::create($date->month >= 7 ? $date->year : $date->year - 1, 7, 1)->toDateString();
    }

    /** @param  array<string, mixed>  $f */
    private static function query(array $f): Builder
    {
        $perSource = self::money($f)
            ->whereBetween('m.trx_date', [$f['from'], $f['to']])
            ->groupBy('m.source_type', 'm.source_id', 'month')
            ->select([
                'm.source_type',
                'm.source_id',
                DB::raw("DATE_FORMAT(m.trx_date, '%Y-%m-01') as month"),
                DB::raw('SUM(m.debit) as d'),
                DB::raw('SUM(m.credit) as c'),
            ]);

        $sides = DB::table('ledger_entries as o')
            ->join('accounts as oa', 'oa.id', '=', 'o.account_id')
            ->where('o.company_id', $f['company_id'])
            ->whereNull('oa.money_kind')
            ->whereBetween('o.trx_date', [$f['from'], $f['to']])
            ->groupBy('o.source_type', 'o.source_id')
            ->select('o.source_type', 'o.source_id')
            ->selectRaw(
                'MAX(CASE WHEN o.party_type = ? OR oa.code IN ('.self::marks(self::CUSTOMER_CODES).') THEN 1 ELSE 0 END) as customer',
                ['customer', ...self::CUSTOMER_CODES],
            )
            ->selectRaw(
                'MAX(CASE WHEN o.party_type = ? OR oa.code IN ('.self::marks(self::SUPPLIER_CODES).') THEN 1 ELSE 0 END) as supplier',
                ['supplier', ...self::SUPPLIER_CODES],
            )
            ->selectRaw(
                'MAX(CASE WHEN oa.code IN ('.self::marks(self::SALARY_CODES).') THEN 1 ELSE 0 END) as salary',
                self::SALARY_CODES,
            )
            ->selectRaw('MAX(CASE WHEN oa.type = ? THEN 1 ELSE 0 END) as income', [Account::INCOME])
            ->selectRaw('MAX(CASE WHEN oa.type = ? THEN 1 ELSE 0 END) as expense', [Account::EXPENSE])
            ->selectRaw('MAX(CASE WHEN oa.type IN (?, ?) THEN 1 ELSE 0 END) as finance', [Account::EQUITY, Account::LIABILITY]);

        /*
         * ⛔ দেখানো খাতগুলোর ভিতরের স্থানান্তর কাটাকাটি — একই কাগজের ঢোকা আর বেরোনোর মিলে যাওয়া অংশ বাদ।
         *
         * ⛔ বাতিলের উল্টো দাখিলা (`…:reversal`) নিজের ভাগেই **বিয়োগ** — বাতিল আদায়ের টাকা বেরোনো
         * নয়, আদায় কমা। ⓘ না করলে বাতিল আদায় "খরচ ও অন্যান্য"-এ বসত, আর আদায় ফুলে থাকত।
         */
        $rev = "s.source_type LIKE '%:reversal'";
        $in = "(CASE WHEN {$rev} THEN -(s.c - LEAST(s.d, s.c)) ELSE (s.d - LEAST(s.d, s.c)) END)";
        $out = "(CASE WHEN {$rev} THEN -(s.d - LEAST(s.d, s.c)) ELSE (s.c - LEAST(s.d, s.c)) END)";

        $inKind = 'CASE WHEN g.source_id IS NULL THEN \'transfer\''
            .' WHEN g.customer = 1 THEN \'collections\''
            .' WHEN g.income = 1 THEN \'other\''
            .' WHEN g.finance = 1 THEN \'finance\''
            .' ELSE \'other\' END';

        $outKind = 'CASE WHEN g.source_id IS NULL THEN \'transfer\''
            .' WHEN g.supplier = 1 THEN \'suppliers\''
            .' WHEN g.salary = 1 THEN \'salaries\''
            .' WHEN g.expense = 1 THEN \'expenses\''
            .' WHEN g.finance = 1 THEN \'finance\''
            .' ELSE \'expenses\' END';

        $sumIf = fn (string $amount, string $kind, string $want, string $as) => DB::raw(
            "SUM(CASE WHEN {$kind} = '{$want}' THEN {$amount} ELSE 0 END) as {$as}"
        );

        $months = DB::query()
            ->fromSub($perSource, 's')
            ->leftJoinSub($sides, 'g', fn ($join) => $join
                ->on('g.source_type', '=', 's.source_type')
                ->on('g.source_id', '=', 's.source_id'))
            ->groupBy('s.month')
            ->select([
                's.month',
                $sumIf($in, $inKind, 'collections', 'in_collections'),
                $sumIf($in, $inKind, 'other', 'in_other'),
                $sumIf($in, $inKind, 'finance', 'in_finance'),
                $sumIf($in, $inKind, 'transfer', 'in_transfer'),
                DB::raw("SUM({$in}) as total_in"),
                $sumIf($out, $outKind, 'suppliers', 'out_suppliers'),
                $sumIf($out, $outKind, 'expenses', 'out_expenses'),
                $sumIf($out, $outKind, 'salaries', 'out_salaries'),
                $sumIf($out, $outKind, 'finance', 'out_finance'),
                $sumIf($out, $outKind, 'transfer', 'out_transfer'),
                DB::raw("SUM({$out}) as total_out"),
                DB::raw('SUM(s.d) - SUM(s.c) as net'),
            ]);

        // শুরুর জের — মাসের প্রথম দিনের (পরিসরের প্রথম মাসে "থেকে"-র) আগের সব দেখানো টাকার সারি
        $opening = self::money($f)
            ->whereRaw('m.trx_date < GREATEST(mm.month, ?)', [$f['from']])
            ->selectRaw('COALESCE(SUM(m.debit) - SUM(m.credit), 0)');

        $withOpening = DB::query()
            ->fromSub($months, 'mm')
            ->select('mm.*')
            ->selectSub($opening, 'opening');

        return DB::query()
            ->fromSub($withOpening, 'r')
            ->select('r.*')
            ->selectRaw('r.opening + r.net as closing')
            ->selectRaw(self::monthName().' as month_name')
            ->orderBy('r.month');
    }

    /**
     * দেখানো টাকার সারি — নগদ, ব্যাংক, MFS; দল বাদ; শাখার দেয়াল আর বাছা খাত।
     *
     * @param  array<string, mixed>  $f
     */
    private static function money(array $f): Builder
    {
        return DB::table('ledger_entries as m')
            ->join('accounts as ma', 'ma.id', '=', 'm.account_id')
            ->where('m.company_id', $f['company_id'])
            ->whereNotNull('ma.money_kind')
            ->where('ma.is_group', false)
            ->when($f['account_id'] ?? null, fn ($q, $account) => $q->where('m.account_id', $account))
            ->tap(ReportEngine::branchWall($f, 'm.branch_id'));
    }

    /** মাসের নাম ব্যবহারকারীর ভাষায় — SQL-এ, কারণ ইঞ্জিন সারিগুলো সাধারণ অ্যারে হিসেবেই দেখে */
    private static function monthName(): string
    {
        $cases = '';

        foreach (range(1, 12) as $m) {
            $name = Carbon::create(2000, $m, 1)->locale(app()->getLocale())->translatedFormat('F');
            $cases .= ' WHEN '.$m.' THEN '.DB::getPdo()->quote($name);
        }

        return "CONCAT(CASE MONTH(r.month){$cases} END, ' ', YEAR(r.month))";
    }

    /** @param  list<string>  $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
