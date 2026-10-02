<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * শাখা-শাখা দেনা-পাওনা — রিপোর্ট সেন্টার ধাপ ৬ (মালিক, ১ অক্টোবর ২০২৬; সমন্বয়কের ভাগ, ২ অক্টোবর)।
 *
 * ── কীভাবে, আলাদা খাত ছাড়াই ────────────────────────────────────────────
 * প্রতিটা শাখা পুরোপুরি আলাদা (মালিক, ১ অক্টোবর); খাতার প্রতিটা সারি তার শাখা জানে। একটা ভাউচার এক শাখার ভেতরে
 * থাকলে সেই শাখার ডেবিট আর ক্রেডিট মেলে। ⭐ তাই কোনো শাখার ডেবিট − ক্রেডিট শূন্য না হলে বাকিটা অন্য শাখার সাথে
 * লেনদেন — ধনাত্মক মানে অন্য শাখা এই শাখার কাছে **দেনা** (এই শাখা পাবে), ঋণাত্মক মানে এই শাখা অন্যদের কাছে দেনা।
 * ⓘ পুরো কোম্পানির যোগ সবসময় শূন্য — সর্বমোট সারিতেই সেটা দেখা যায়, আর না হলে খাতাটাই ভাঙা।
 * ⓘ তারিখ পর্যন্ত (শেষ তারিখ); হেডারে এক শাখা বাছলে কেবল তার সারি ([[ReportEngine::branchWall()]])।
 */
final class BranchDuesReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::branchDues());
    }

    public static function branchDues(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.branch_dues',
            permission: 'accounts.report',
            title: 'accounts::branch_dues.title',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::query($f),
            columns: [
                ['key' => 'branch_name', 'label' => 'accounts::branch_dues.branch'],
                ['key' => 'debit', 'label' => 'accounts::branch_dues.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'accounts::branch_dues.credit', 'type' => ReportColumn::MONEY],
                ['key' => 'net', 'label' => 'accounts::branch_dues.net', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    private static function query(array $f): Builder
    {
        $byBranch = DB::table('ledger_entries as le')
            ->where('le.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
            ->where('le.trx_date', '<=', $f['to'])
            ->groupBy('le.branch_id')
            ->selectRaw('le.branch_id')
            ->selectRaw('SUM(le.debit) as debit')
            ->selectRaw('SUM(le.credit) as credit');

        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(b.name_bn, ''), b.name_en)" : 'b.name_en';

        return DB::query()->fromSub($byBranch, 's')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->selectRaw('COALESCE('.$name.', '.DB::getPdo()->quote((string) __('accounts::branch_dues.no_branch')).') as branch_name')
            ->selectRaw('s.debit')
            ->selectRaw('s.credit')
            ->selectRaw('s.debit - s.credit as net')
            ->orderByRaw('ABS(s.debit - s.credit) DESC')
            ->orderBy('branch_name');
    }
}
