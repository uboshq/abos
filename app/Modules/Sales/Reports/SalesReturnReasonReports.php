<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * কোন কারণে কত ফেরত আসে — NEXUS §২৪।
 *
 * ── ⭐ প্রশ্নটা ─────────────────────────────────────────────────────────
 * *"ফেরত বাড়ছে কেন?"* — নষ্ট মাল হলে গাড়ি বা গুদামের সমস্যা, মেয়াদ
 * পেরোলে মজুদ ঘোরানোর সমস্যা, ভুল পণ্য বা ভুল পরিমাণ হলে চালান বাঁধার
 * সমস্যা। ⓘ একেকটার সমাধান একেক জায়গায়, তাই কারণ ধরে ভাগ না করলে
 * মোট ফেরতের অঙ্কটা কাউকে কিছু বলে না।
 *
 * ── ⚠️ লাইনের কারণ আগে, তারপর হেডারের ───────────────────────────────
 * একই ফেরতে দুই বস্তা নষ্ট আর একটা ভুল মাল থাকতে পারে। ⛔ কেবল হেডার
 * ধরে গুনলে ভুল মালটাও "নষ্ট"-এ পড়ত।
 *
 * ── ⚠️ "কারণ নেই" সারিটা বাদ দেওয়া হয় না ─────────────────────────────
 * নিয়মটা বসার আগের ফেরতে কারণ নেই। ⛔ বাদ দিলে এই রিপোর্টের যোগফল আর
 * ফেরতের খাতার (৪১১০) অঙ্ক আলাদা হত, আর মেলাতে গিয়ে কেউ ভাবত হিসাব ভুল।
 *
 * ── ⓘ কেবল খাতায় বসা ফেরত ──────────────────────────────────────────
 * খসড়া ফেরতে মাল গুদামে ফেরেনি, পাওনাও কমেনি — ওটা এখনো ঘটেইনি।
 */
final class SalesReturnReasonReports
{
    public const KEY = 'sales.return_by_reason';

    public const PERMISSION = 'sales.return.report';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::byReason());
    }

    public static function byReason(): ReportDefinition
    {
        return new ReportDefinition(
            /*
             * ⚠️ চাবিটা এখানে লেখা অক্ষরে, `self::KEY` নয় — গ্রুপ-বাইয়ের
             * পাহারা ([[EveryGroupedReportGroupsByWhatItSelectsTest]]) রিপোর্ট
             * চেনে `key: '…'` দেখে; ⛔ ধ্রুবক দিলে পাহারাটা এটাকে নীরবে বাদ দিত।
             * ⓘ দুইটা এক কি না, দাবির ফাইল দেখে।
             */
            key: 'sales.return_by_reason',
            title: 'sales::return_reason.report_title',

            /*
             * ⓘ `customer_id` ও `product_id` কোরের চেনা ছাঁকনি নয় —
             * [[ReportDefinition::requestKeys()]] অচেনা নাম হুবহু ঠিকানা
             * থেকে নেয়, তাই পর্দা আর API দুইটাই একই নামে পাঠায়।
             */
            filters: ['date_range', 'branch', 'customer_id', 'product_id'],
            groupBy: 'reason_code_id',

            // "সবচেয়ে বড়" মানে টাকার অঙ্ক — ক্ষতিটা ওখানেই
            rankBy: 'total',

            // নির্ধারিত রিপোর্ট ও API — কে পেতে পারেন, সংজ্ঞাতেই
            permission: self::PERMISSION,

            /*
             * ⓘ কারণের টেবিলের নাম `r` — ⚠️ [[EveryGroupedReportGroupsByWhatItSelectsTest]]
             * `reasonName()` দেখলে `r.name_en`/`r.name_bn` groupBy-তে খোঁজে; অন্য
             * নাম দিলে পাহারাটা এই রিপোর্টকে কোনোদিন দেখতই না।
             */
            query: fn (array $f) => DB::table('sal_return_lines as rl')
                ->join('sal_returns as sr', 'sr.id', '=', 'rl.sales_return_id')
                ->leftJoin('mdm_reason_codes as r', function (JoinClause $join): void {
                    $join->on('r.id', '=', DB::raw('COALESCE(rl.reason_code_id, sr.reason_code_id)'))
                        // অন্য কোম্পানির কারণ কখনো নাম দেবে না — ফেরতের কোম্পানিরটাই
                        ->on('r.company_id', '=', 'sr.company_id');
                })
                ->where('sr.company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'sr.branch_id'))
                ->when($f['customer_id'] ?? null, fn ($q, $c) => $q->where('sr.customer_id', (int) $c))
                ->when($f['product_id'] ?? null, fn ($q, $p) => $q->where('rl.product_id', (int) $p))
                ->whereBetween('sr.trx_date', [$f['from'], $f['to']])
                ->whereNull('sr.deleted_at')
                ->whereIn('sr.status', DocumentStatus::POSTED)
                ->groupBy('r.id', 'r.code', 'r.name_en', 'r.name_bn')
                ->orderByRaw('SUM(rl.amount + rl.tax) desc')
                ->select([
                    'r.id as reason_code_id',
                    self::reasonName(),

                    /*
                     * ⚠️ DISTINCT — একটা ফেরতের তিন লাইন একই কারণে হলে
                     * ফেরত একটাই, তিনটা নয়।
                     */
                    DB::raw('COUNT(DISTINCT sr.id) as return_count'),
                    DB::raw('SUM(rl.qty) as qty'),

                    // ভ্যাট আলাদা — ফেরতের খাতায় (৪১১০) কেবল মূল্যটা বসে
                    DB::raw('SUM(rl.amount) as value'),
                    DB::raw('SUM(rl.tax) as tax'),
                    DB::raw('SUM(rl.amount + rl.tax) as total'),
                ]),
            columns: [
                ['key' => 'reason_name', 'label' => 'sales::field.reason'],

                // ফেরতের সংখ্যা — যোগ করলে একই ফেরত দুই কারণে দুইবার গোনা হত
                ['key' => 'return_count', 'label' => 'sales::return_reason.return_count', 'total' => false],
                ['key' => 'qty', 'label' => 'sales::field.quantity', 'type' => ReportColumn::QUANTITY],
                ['key' => 'value', 'label' => 'sales::return_reason.value', 'type' => ReportColumn::MONEY],
                ['key' => 'tax', 'label' => 'sales::field.tax', 'type' => ReportColumn::MONEY],
                ['key' => 'total', 'label' => 'sales::field.total', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * কারণের নাম — না থাকলে "কারণ লেখা নেই"।
     *
     * ⓘ লেখাটা ড্রাইভারকে দিয়ে উদ্ধৃত — ⛔ SELECT-এ `?` বসালে বাকি
     * বাইন্ডিং এক ঘর সরে যায় ([[SalesReports]]-এর মাথার ভুলটা)।
     */
    private static function reasonName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(r.name_bn, ''), r.name_en)"
            : 'r.name_en';

        $none = DB::getPdo()->quote(__('sales::return_reason.no_reason'));

        return DB::raw("COALESCE({$name}, {$none}) as reason_name");
    }
}
