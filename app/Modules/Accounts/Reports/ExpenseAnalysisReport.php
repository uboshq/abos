<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\YearEndService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * খরচের বিশ্লেষণ — রিপোর্ট সেন্টার ধাপ ৪ ("খাত/শাখা/মাস, অস্বাভাবিক বড় খরচ"), ২ অক্টোবর ২০২৬।
 *
 * *"কোন খাতে খরচ হঠাৎ বাড়ল, আর সবচেয়ে বড় একটা খরচ কত ছিল"*। প্রতি খাত এক সারি: এই সময়ে কত, ঠিক আগের সমান
 * লম্বা সময়ে কত, কত শতাংশ বদল, সবচেয়ে বড় একক দাখিলা আর কয়টা দাখিলা। শাখা ধরে দেখতে শাখার ছাঁকনি।
 *
 * ⓘ শুধু কোন খাতে কত, সেটা [[CoreReports::expenseByHead()]]-এ আছে; এটা তার পাশে তুলনা। একই নিয়ম — কেবল পোস্ট
 * হওয়া খরচ, বছর বন্ধের দাখিলা বাদ, অনুমতি `accounts.report` (লাভের কোনো সংখ্যা এখানে নেই)।
 * ⓘ বদল % কেবল তখন, যখন আগের সময়ে কিছু খরচ ছিল — শূন্য থেকে বাড়াকে শতাংশে বলা যায় না, ঘরটা ফাঁকা থাকে।
 */
final class ExpenseAnalysisReport
{
    public const KEY = 'accounts.expense_analysis';

    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            permission: 'accounts.report',
            title: 'accounts::expense.title',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::query($f),
            columns: [
                ['key' => 'account_name', 'label' => 'accounts::expense.head', 'type' => ReportColumn::TEXT],
                ['key' => 'spent', 'label' => 'accounts::expense.spent', 'type' => ReportColumn::MONEY],
                ['key' => 'previous', 'label' => 'accounts::expense.previous', 'type' => ReportColumn::MONEY],
                ['key' => 'change', 'label' => 'accounts::expense.change', 'type' => ReportColumn::PERCENT, 'total' => false],
                ['key' => 'biggest', 'label' => 'accounts::expense.biggest', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'entries', 'label' => 'accounts::expense.entries', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /** @param  array<string, mixed>  $f */
    private static function query(array $f): Builder
    {
        $from = Carbon::parse($f['from'])->startOfDay();
        $to = Carbon::parse($f['to'])->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $prevFrom = $from->copy()->subDays($days)->toDateString();
        $prevTo = $from->copy()->subDay()->toDateString();

        $q = fn (string $d) => DB::getPdo()->quote($d);
        $inNow = "le.trx_date BETWEEN {$q($from->toDateString())} AND {$q($to->toDateString())}";
        $inPrev = "le.trx_date BETWEEN {$q($prevFrom)} AND {$q($prevTo)}";
        $amount = '(le.debit - le.credit)';
        $now = "SUM(CASE WHEN {$inNow} THEN {$amount} ELSE 0 END)";
        $prev = "SUM(CASE WHEN {$inPrev} THEN {$amount} ELSE 0 END)";
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(a.name_bn, ''), a.name_en)" : 'a.name_en';

        return DB::table('ledger_entries as le')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('le.company_id', $f['company_id'])
            ->whereBetween('le.trx_date', [$prevFrom, $to->toDateString()])
            ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
            ->where('a.type', Account::EXPENSE)
            ->where('a.is_group', false)
            // ⛔ বছর বন্ধের দাখিলা বাদ — নইলে বছরের শেষ দিন পড়লে খরচ শূন্য দেখাত ([[YearEndService::closingSources()]])
            ->whereNotIn('le.source_type', YearEndService::closingSources())
            ->groupBy('le.account_id', 'a.code', 'a.name_en', 'a.name_bn')
            ->havingRaw("{$now} <> 0 OR {$prev} <> 0")
            ->orderByRaw("{$now} DESC")
            ->select([
                'le.account_id',
                DB::raw("CONCAT(a.code, ' — ', {$name}) as account_name"),
                DB::raw("{$now} as spent"),
                DB::raw("{$prev} as previous"),
                DB::raw("CASE WHEN {$prev} > 0 THEN ROUND(({$now} - {$prev}) * 100 / {$prev}, 1) END as `change`"),
                DB::raw("MAX(CASE WHEN {$inNow} THEN le.debit ELSE 0 END) as biggest"),
                DB::raw("SUM(CASE WHEN {$inNow} AND le.debit > 0 THEN 1 ELSE 0 END) as entries"),
            ]);
    }
}
