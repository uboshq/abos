<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ব্যাংক ঋণের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা ৩, ৬ অক্টোবর ২০২৬ (মালিকের অনুমোদিত ক্রম, সমন্বয়কের মারফত):
 *
 *   ৩.২ / ৩.৫ কিস্তি — সব চালু ঋণের বাকি কিস্তি, দিন পার / আজ / সামনে ([[INSTALMENTS]])
 *   ৩.৪ সীমার ব্যবহার — সীমা, ড্রয়িং পাওয়ার, তোলা, খালি, কত শতাংশ ([[LIMITS]])
 *
 * ── ⭐ নিজের হিসাব নেই ──────────────────────────────────────────────────────
 * কিস্তি আসে [[BankFacilityService::instalmentsDue()]] থেকে (সূচি ক্ষয়িষ্ণু জেরে, "দেওয়া" খাতা থেকে), তোলা আসে
 * [[BankFacilityService::standing()]] থেকে — ঋণের পাতা যেগুলো দেখায় হুবহু সেগুলোই। ⓘ সারিগুলো PHP-তে গোনা (সূচি
 * সংরক্ষিত নয়), তাই রিপোর্টে বসে একটা তৈরি সারির টেবিল হয়ে ([[rowsOf()]]); শাখার দেয়াল তার উপরেই খাটে।
 */
final class BankLoanReports
{
    public const INSTALMENTS = 'finance.bank_loan_instalments';

    public const LIMITS = 'finance.bank_limit_usage';

    // ⛔ ব্যাংক ঋণের পাতা যে চাবি দেখে, সেটাই
    private const KEY = 'finance.bank_facility.view';

    /** সামনের কত দিনের কিস্তি — পরিকল্পনার "সতর্কতা"-র মাপ */
    public const WINDOW_DAYS = 30;

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::instalments());
        $engine->register(self::limits());
    }

    private static function instalments(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::INSTALMENTS,
            permission: self::KEY,
            title: 'finance::bank_loan_report.instalments_title',
            filters: ['date_range', 'branch', 'state'],
            asOfDate: true,
            query: function (array $f): Builder {
                $rows = [];

                foreach (app(BankFacilityService::class)->instalmentsDue(self::WINDOW_DAYS, Carbon::parse((string) $f['to'])) as $due) {
                    if (filled($f['state'] ?? null) && $due['state'] !== $f['state']) {
                        continue;
                    }

                    $facility = $due['facility'];
                    $rows[] = [
                        'branch_id' => $facility->branch_id,
                        'due_on' => $due['due_on'],
                        'facility' => self::name($facility),
                        'month' => $due['month'],
                        'principal' => $due['principal'],
                        'interest' => $due['interest'],
                        'amount' => $due['amount'],
                        'state_label' => (string) __('finance::bank_loan_report.state_'.$due['state']),
                        'source_type' => BankFacility::drillSourceType(),
                        'source_id' => (int) $facility->id,
                    ];
                }

                return self::rowsOf($rows, $f, ['branch_id', 'due_on', 'facility', 'month', 'principal', 'interest', 'amount', 'state_label', 'source_type', 'source_id'])
                    ->orderBy('x.due_on')->orderBy('x.facility');
            },
            columns: [
                ['key' => 'due_on', 'label' => 'finance::bank_loan_report.due_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'facility', 'label' => 'finance::bank_loan_report.facility', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'month', 'label' => 'finance::bank_loan_report.instalment_no', 'width' => '5rem'],
                ['key' => 'principal', 'label' => 'finance::field.principal_part', 'type' => ReportColumn::MONEY],
                ['key' => 'interest', 'label' => 'finance::field.interest_part', 'type' => ReportColumn::MONEY],
                ['key' => 'amount', 'label' => 'finance::bank_loan_report.amount', 'type' => ReportColumn::MONEY],
                ['key' => 'state_label', 'label' => 'finance::bank_loan_report.state', 'width' => '8rem'],
            ],
        );
    }

    /**
     * ⭐ ৩.৪ — সীমার ব্যবহার: প্রতিটা চালু সুবিধার সীমা, আজকের ড্রয়িং পাওয়ার (CC — মজুদ বাদ মার্জিন, সীমার বেশি নয়),
     * তোলা, খালি আর ব্যবহারের শতাংশ। ⓘ খালি = (ড্রয়িং পাওয়ার, না থাকলে সীমা) − তোলা, শূন্যের নিচে নয়।
     */
    private static function limits(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::LIMITS,
            permission: self::KEY,
            title: 'finance::bank_loan_report.limits_title',
            filters: ['branch'],
            rankBy: 'used',
            query: function (array $f): Builder {
                $service = app(BankFacilityService::class);
                $facilities = BankFacility::query()->live()->inViewedBranch()
                    ->where('kind', '!=', BankFacility::GUARANTEE)
                    ->orderBy('bank')->get();
                $standing = $service->standing($facilities);
                $rows = [];

                foreach ($facilities as $facility) {
                    $used = (string) ($standing[(int) $facility->id]['used'] ?? '0');
                    $power = $facility->drawingPower() ?? (string) $facility->limit_amount;
                    $free = bcsub($power, $used, 4);
                    $rows[] = [
                        'branch_id' => $facility->branch_id,
                        'facility' => self::name($facility),
                        'kind_label' => (string) __('finance::field.facility_'.$facility->kind),
                        'limit_amount' => bcadd((string) $facility->limit_amount, '0', 4),
                        'drawing_power' => $facility->drawingPower() === null ? null : bcadd($facility->drawingPower(), '0', 4),
                        'used' => bcadd($used, '0', 4),
                        'free' => bccomp($free, '0', 4) > 0 ? $free : '0.0000',
                        'used_percent' => bccomp($power, '0', 4) > 0 ? bcmul(bcdiv($used, $power, 6), '100', 2) : null,
                        'source_type' => BankFacility::drillSourceType(),
                        'source_id' => (int) $facility->id,
                    ];
                }

                return self::rowsOf($rows, $f, ['branch_id', 'facility', 'kind_label', 'limit_amount', 'drawing_power', 'used', 'free', 'used_percent', 'source_type', 'source_id'])
                    ->orderByDesc('x.used_percent');
            },
            columns: [
                ['key' => 'facility', 'label' => 'finance::bank_loan_report.facility', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'kind_label', 'label' => 'finance::field.facility_kind', 'width' => '9rem'],
                ['key' => 'limit_amount', 'label' => 'finance::bank_loan_report.limit', 'type' => ReportColumn::MONEY],
                ['key' => 'drawing_power', 'label' => 'finance::bank_loan_report.drawing_power', 'type' => ReportColumn::MONEY],
                ['key' => 'used', 'label' => 'finance::bank_loan_report.used', 'type' => ReportColumn::MONEY],
                ['key' => 'free', 'label' => 'finance::bank_loan_report.free', 'type' => ReportColumn::MONEY],
                ['key' => 'used_percent', 'label' => 'finance::bank_loan_report.used_percent', 'type' => ReportColumn::PERCENT, 'width' => '7rem'],
            ],
        );
    }

    /** ঋণের নাম — ব্যাংক আর কাগজের নম্বর, ঋণের তালিকার একই ছাঁদে */
    private static function name(BankFacility $facility): string
    {
        return trim($facility->bank.' · '.$facility->document_no, ' ·');
    }

    /**
     * PHP-তে গোনা সারি → রিপোর্টের টেবিল: প্রতিটা সারি বাঁধা মানসহ এক `SELECT`, সব মিলে `UNION ALL`; তার উপর শাখার দেয়াল
     * ([[ReportEngine::branchWall()]]) — যাতে এক শাখায় আটকানো মানুষ অন্য শাখার ঋণ না দেখেন। খালি হলে একই কলামের
     * একটা খালি টেবিল।
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $f
     * @param  list<string>  $columns
     */
    private static function rowsOf(array $rows, array $f, array $columns): Builder
    {
        $select = implode(', ', array_map(fn (string $c) => '? as '.$c, $columns));
        $parts = array_map(fn (array $row) => DB::query()->fromRaw('(select 1 as one) as d')
            ->selectRaw($select, array_map(fn (string $c) => $row[$c] ?? null, $columns)), $rows);

        if ($parts === []) {
            $parts = [DB::query()->fromRaw('(select 1 as one) as d')
                ->selectRaw(implode(', ', array_map(fn (string $c) => 'NULL as '.$c, $columns)))->whereRaw('1 = 0')];
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DB::query()->fromSub($union, 'x')->tap(ReportEngine::branchWall($f, 'x.branch_id'));
    }
}
