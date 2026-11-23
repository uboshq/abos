<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ কে কত বিল করেছেন — কাজের হিসাব, বিক্রি নয় (মালিক, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ বিল যিনি লিখেছেন (`created_by`) ধরে: কয়টা পাকা বিল, কয়জন ডিলার, বিলের অঙ্ক (ভ্যাটসহ, কাগজে যা লেখা)। অফিসের অপারেটর বা
 * কাউন্টারের লোক কত কাজ করেছেন — এটুকুই।
 * ⛔ এই অঙ্ক কখনো বিক্রি বা লক্ষ্যের অর্জন নয়: বিক্রি দায়িত্বপ্রাপ্ত জনের ([[SalespersonReports]], `sales_person_id`), আর
 * [[SalesTargetService::achievedByUser()]] এই রিপোর্ট পড়ে না। ⓘ খসড়া, বাতিল আর মুছে ফেলা বিল গোনা হয় না।
 */
final class BillerReports
{
    public const KEY = 'sales.by_biller';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::byBiller());
    }

    private static function byBiller(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            // ⓘ বিক্রয়ের নিজের রিপোর্টের চাবি — ওয়েবের দরজা যেটা দেখে
            permission: 'sales.report',
            title: 'sales::biller_report.title',
            filters: ['date_range', 'branch'],
            rankBy: 'bill_count',
            query: function (array $f): Builder {
                $unknown = DB::getPdo()->quote((string) __('sales::biller_report.unknown'));

                $bills = DB::table('sal_invoices as i')
                    ->where('i.company_id', $f['company_id'])
                    ->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)
                    ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->selectRaw('i.created_by as biller_id, i.customer_id as customer_id, i.id as invoice_id, i.total as amount');

                // ⓘ ভিতরে বিল-প্রতি লেখক, বাইরে যোগ — ONLY_FULL_GROUP_BY-তে নিরাপদ
                return DB::query()->fromSub($bills, 'x')
                    ->leftJoin('users as u', 'u.id', '=', 'x.biller_id')
                    ->groupBy('x.biller_id', 'u.name')
                    ->selectRaw("x.biller_id as biller_id, COALESCE(u.name, {$unknown}) as biller, "
                        .'COUNT(DISTINCT x.customer_id) as dealer_count, COUNT(*) as bill_count, SUM(x.amount) as amount')
                    ->orderByRaw('COUNT(*) DESC')
                    ->orderBy('x.biller_id');
            },
            columns: [
                ['key' => 'biller', 'label' => 'sales::biller_report.biller'],
                ['key' => 'dealer_count', 'label' => 'sales::biller_report.dealers', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'bill_count', 'label' => 'sales::biller_report.bills', 'type' => ReportColumn::QUANTITY],
                ['key' => 'amount', 'label' => 'sales::biller_report.amount', 'type' => ReportColumn::MONEY],
            ],
        );
    }
}
