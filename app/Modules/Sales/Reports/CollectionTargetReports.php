<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use App\Modules\Sales\Services\CustomerTargetService;
use App\Modules\Sales\Services\DealerOwnership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ আদায়ের লক্ষ্য বনাম অর্জন — বিক্রয় পরিকল্পনা সংস্করণ ২ §৯ (ঙ), ৬ অক্টোবর ২০২৬ (মালিকের আদেশ "Sales মডিউলের কাজ শেষ দাও")।
 *
 * ⓘ লক্ষ্য ডিলার ধরে, মাস ধরে (`sal_customer_targets`, মালিক ৩ অক্টোবর)। "শুরু" যে মাসে পড়ে সেই মাসের লক্ষ্য থাকা প্রতিটা ডিলার
 * এক সারি; অর্জন মাসের ১ তারিখ থেকে মাসের শেষ, আজ বা "শেষ"-এর যেটা আগে। ⭐ অর্জনের নিয়ম লক্ষ্যের পাতা আর বিলের বাক্সের হুবহু
 * ([[CustomerTargetService::achievedQuery()]]) — আসা টাকা, ফেরত চেক বাদ, পাশ না হওয়া চেক বাদ। বিক্রয়কর্মী: লক্ষ্যের শেষ তারিখে
 * ডিলারটা যাঁর নামে বাঁধা ([[DealerOwnership::boundOn()]])।
 *
 * ⛔ গোটা কোম্পানির — ডিলারের লক্ষ্য একটাই, শাখা ধরে ভাগ হয় না; তাই শাখায় সীমিত কেউ এটা পান না (`WHOLE_COMPANY`)।
 */
final class CollectionTargetReports
{
    public const KEY = 'sales.collection_target';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::collectionTarget());
    }

    private static function collectionTarget(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            permission: 'sales.report',
            title: 'sales::collection_target.title',
            filters: ['date_range'],
            branchless: ReportDefinition::WHOLE_COMPANY,
            query: function (array $f): Builder {
                $month = Carbon::parse((string) $f['from'])->startOfMonth();
                $end = collect([$month->copy()->endOfMonth(), Carbon::parse((string) $f['to']), Carbon::today()])->min();
                $achieved = CustomerTargetService::achievedQuery((int) $f['company_id'], $month->toDateString(), $end->toDateString());
                $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(cu.name_bn, ''), cu.name_en)" : 'cu.name_en';

                $rows = DB::table('sal_customer_targets as t')
                    ->join('customers as cu', 'cu.id', '=', 't.customer_id')
                    ->leftJoinSub($achieved, 'a', 'a.customer_id', '=', 't.customer_id')
                    ->where('t.company_id', $f['company_id'])
                    ->whereDate('t.month', $month->toDateString())
                    ->tap(ReportEngine::dealerWall($f, 't.customer_id'))
                    ->selectSub(DealerOwnership::boundOn('t.customer_id', 't.closes_on', 't.company_id'), 'seller_id')
                    ->selectRaw("cu.code as code, {$name} as dealer, cu.id as source_id, t.amount as target, "
                        .'COALESCE(a.achieved, 0) as achieved, t.closes_on as closes_on');

                // ⓘ ভিতরে সারি-প্রতি বিক্রয়কর্মী, বাইরে নাম — ONLY_FULL_GROUP_BY-তে নিরাপদ
                return DB::query()->fromSub($rows, 'r')
                    ->leftJoin('users as u', 'u.id', '=', 'r.seller_id')
                    ->selectRaw('r.code, r.dealer, '.DB::getPdo()->quote('customer').' as source_type, r.source_id, u.name as salesperson, '
                        .'r.target, r.achieved, GREATEST(r.target - r.achieved, 0) as remaining, '
                        .'CASE WHEN r.target > 0 THEN ROUND(r.achieved * 100 / r.target, 1) ELSE NULL END as percent, r.closes_on')
                    ->orderByRaw('GREATEST(r.target - r.achieved, 0) DESC')
                    ->orderBy('r.code');
            },
            summary: function (array $totals): array {
                $target = (string) ($totals['target'] ?? '0');
                $achieved = (string) ($totals['achieved'] ?? '0');
                $rate = bccomp($target, '0', 4) > 0 ? bcdiv(bcmul($achieved, '100', 4), $target, 1) : null;

                return [
                    'label' => __('sales::collection_target.summary'),
                    'value' => $rate ?? '0',
                    'text' => $rate === null
                        ? __('sales::collection_target.no_target')
                        : __('sales::collection_target.summary_text', ['rate' => $rate, 'achieved' => Money::format($achieved), 'target' => Money::format($target)]),
                    'good' => $rate === null || bccomp($rate, '100', 1) >= 0,
                ];
            },
            columns: [
                ['key' => 'code', 'label' => 'sales::collection_target.code', 'width' => '6rem'],
                ['key' => 'dealer', 'label' => 'sales::collection_target.dealer', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'salesperson', 'label' => 'sales::collection_target.salesperson'],
                ['key' => 'target', 'label' => 'sales::collection_target.target', 'type' => ReportColumn::MONEY],
                ['key' => 'achieved', 'label' => 'sales::collection_target.achieved', 'type' => ReportColumn::MONEY],
                ['key' => 'remaining', 'label' => 'sales::collection_target.remaining', 'type' => ReportColumn::MONEY],
                ['key' => 'percent', 'label' => 'sales::collection_target.percent', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'closes_on', 'label' => 'sales::collection_target.closes_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
            ],
        );
    }
}
