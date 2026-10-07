<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * শাখা পাশাপাশি — রিপোর্ট সেন্টার ধাপ ২ ("নির্বাহী পাতা" আর "শাখাভিত্তিক লাভ-ক্ষতি"), ২ অক্টোবর ২০২৬।
 *
 * *"কোন শাখা কত বেচল, কত লাভ করল, হাতে কত টাকা, বাজারে কত পাওনা, কাকে কত দেনা, গুদামে কত মাল"* — প্রতি শাখা
 * এক সারি, শেষে শাখাহীন (প্রধান অফিসের) সারি, নিচে সর্বমোট।
 *
 * ── ⭐ সব সংখ্যা খাতা থেকে ─────────────────────────────────────────────
 * হিসাব কোনো মডিউলের ওপর নির্ভর করে না ([[accounts-depends-on-nothing-by-design]]), আর খাতাই সত্যের এক জায়গা:
 *   · বিক্রি = বিক্রির খাত (৪১০০), আয় = সব আয়ের খাত, খরচ = সব খরচের খাত (বিক্রিত মালের দামসহ), লাভ = আয় − খরচ —
 *     কেবল বাছা সময়ে, বছর বন্ধের দাখিলা বাদ ([[YearEndService::closingSources()]])।
 *   · টাকা (নগদ + ব্যাংক + মোবাইল), পাওনা (১১১০), দেনা (২১১১), মজুদের মূল্য (১১২০) — শেষ দিন পর্যন্ত জমা অঙ্ক।
 *   · প্রতিটা কোড মানে খাতটা **আর তার নিচের সব উপ-খাত** ([[Account::selfAndDescendants()]])।
 *
 * ⛔ লাভ আছে, তাই অনুমতি `accounts.report` আর `accounts.report.final` দুটোই — দরজা যা চায় হুবহু তাই, লাভ-ক্ষতির পাতার সমান। নিজেই শাখা ধরে ভাগ করে, তাই ইঞ্জিনের
 * শাখা-ভাগ বন্ধ (`splitByBranch: false`); শাখার দেয়াল তবু মানে।
 */
final class BranchesSideBySideReport
{
    public const KEY = 'accounts.branches_side_by_side';

    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            permission: ['accounts.report', 'accounts.report.final'],
            title: 'accounts::branches.title',
            filters: ['date_range'],
            splitByBranch: false,
            query: fn (array $f) => self::query($f),
            columns: [
                ['key' => 'branch_name', 'label' => 'accounts::branches.branch', 'type' => ReportColumn::TEXT],
                ['key' => 'sales', 'label' => 'accounts::branches.sales', 'type' => ReportColumn::MONEY],
                ['key' => 'income', 'label' => 'accounts::branches.income', 'type' => ReportColumn::MONEY],
                ['key' => 'expense', 'label' => 'accounts::branches.expense', 'type' => ReportColumn::MONEY],
                ['key' => 'profit', 'label' => 'accounts::branches.profit', 'type' => ReportColumn::MONEY],
                ['key' => 'money', 'label' => 'accounts::branches.money', 'type' => ReportColumn::MONEY],
                ['key' => 'receivable', 'label' => 'accounts::branches.receivable', 'type' => ReportColumn::MONEY],
                ['key' => 'payable', 'label' => 'accounts::branches.payable', 'type' => ReportColumn::MONEY],
                ['key' => 'stock', 'label' => 'accounts::branches.stock', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** @param  array<string, mixed>  $f */
    private static function query(array $f): Builder
    {
        $q = fn (string $v) => DB::getPdo()->quote($v);
        $inPeriod = "le.trx_date >= {$q((string) $f['from'])}";
        $dr = '(le.debit - le.credit)';
        $cr = '(le.credit - le.debit)';
        // ⓘ খাতের নিচে কোম্পানি উপ-খাত খুলতে পারে — তাই কোড নয়, খাতটা আর তার নিচের সবাই
        $code = fn (string $c) => 'a.id IN ('.implode(', ', self::family($c)).')';
        $kinds = implode(', ', array_map($q, [Account::CASH, Account::BANK, Account::MFS]));
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(b.name_bn, ''), b.name_en)" : 'b.name_en';

        return DB::table('ledger_entries as le')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->leftJoin('branches as b', 'b.id', '=', 'le.branch_id')
            ->where('le.company_id', $f['company_id'])
            ->where('le.trx_date', '<=', $f['to'])
            ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
            // ⛔ বছর বন্ধের দাখিলা কেবল আয়-খরচ আর সঞ্চিত মুনাফা ছোঁয় — বাদ দিলে নিচের কোনো জমা অঙ্ক বদলায় না
            ->whereNotIn('le.source_type', YearEndService::closingSources())
            ->groupBy('le.branch_id', 'b.code', 'b.name_en', 'b.name_bn')
            // ⓘ শাখাহীন সারি শেষে
            ->orderByRaw('le.branch_id IS NULL')
            ->orderBy('b.code')
            ->select([
                'le.branch_id',
                DB::raw("COALESCE({$name}, ".$q((string) __('core.report.no_branch')).') as branch_name'),
                DB::raw("SUM(CASE WHEN {$inPeriod} AND {$code(StandardChart::SALES)} THEN {$cr} ELSE 0 END) as sales"),
                DB::raw("SUM(CASE WHEN {$inPeriod} AND a.type = {$q(Account::INCOME)} THEN {$cr} ELSE 0 END) as income"),
                DB::raw("SUM(CASE WHEN {$inPeriod} AND a.type = {$q(Account::EXPENSE)} THEN {$dr} ELSE 0 END) as expense"),
                DB::raw("SUM(CASE WHEN {$inPeriod} AND a.type = {$q(Account::INCOME)} THEN {$cr} WHEN {$inPeriod} AND a.type = {$q(Account::EXPENSE)} THEN -{$dr} ELSE 0 END) as profit"),
                DB::raw("SUM(CASE WHEN a.money_kind IN ({$kinds}) THEN {$dr} ELSE 0 END) as money"),
                DB::raw("SUM(CASE WHEN {$code(StandardChart::RECEIVABLE)} THEN {$dr} ELSE 0 END) as receivable"),
                DB::raw("SUM(CASE WHEN {$code(StandardChart::PAYABLE)} THEN {$cr} ELSE 0 END) as payable"),
                DB::raw("SUM(CASE WHEN {$code(StandardChart::INVENTORY)} THEN {$dr} ELSE 0 END) as stock"),
            ]);
    }

    /**
     * একটা খাত আর তার নিচের সব খাতের id — না থাকলে `[0]`, যাতে `IN ()` ভেঙে না পড়ে।
     *
     * @return list<int>
     */
    private static function family(string $code): array
    {
        $root = StandardChart::find($code);
        $ids = $root === null ? [] : $root->selfAndDescendants()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $ids === [] ? [0] : array_values($ids);
    }
}
