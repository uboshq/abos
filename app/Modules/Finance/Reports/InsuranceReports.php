<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Finance\Models\InsurancePremium;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বীমার রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা ৬, ৬ অক্টোবর ২০২৬।
 *
 *   ৬.২ প্রিমিয়ামের সূচি — কোন প্রিমিয়াম কবে, দেওয়া / দিন পার / আজ / সামনে ([[PREMIUMS]])
 *
 * ⓘ সারি = প্রিমিয়ামের কিস্তি (`fin_insurance_premiums`), পলিসির পাতা যা দেখায়; দিন = সময়কালের শুরু, "দেওয়া" = ভাউচারে
 * মিটেছে ([[InsurancePremium::dueState()]]-এর একই নিয়ম, SQL-এ)। ⓘ চালু পলিসির কিস্তি, তারিখ-পরিসরের ভিতরের; শাখার দেয়াল
 * পলিসির শাখায়।
 */
final class InsuranceReports
{
    public const PREMIUMS = 'finance.insurance_premiums';

    /** ⛔ বীমার পাতা যে চাবি দেখে, সেটাই */
    private const KEY = 'finance.insurance.view';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::premiums());
    }

    private static function premiums(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::PREMIUMS,
            permission: self::KEY,
            title: 'finance::insurance.premiums_title',
            filters: ['date_range', 'branch', 'state'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $today = $pdo->quote(now()->toDateString());
                $paid = "(p.status <> '".InsurancePremium::DRAFT."' OR p.voucher_id IS NOT NULL)";
                $state = "CASE WHEN {$paid} THEN 'paid' WHEN p.period_from < {$today} THEN 'overdue' "
                    ."WHEN p.period_from = {$today} THEN 'today' ELSE 'upcoming' END";
                $label = 'CASE '.$state;
                foreach (['paid', 'overdue', 'today', 'upcoming'] as $s) {
                    $label .= ' WHEN '.$pdo->quote($s).' THEN '.$pdo->quote((string) __('finance::insurance.premium_state_'.$s));
                }
                $label .= ' END';
                $insurer = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(i.name_bn, ''), i.name_en)" : 'i.name_en';

                return DB::table('fin_insurance_premiums as p')
                    ->join('fin_insurance_policies as pol', 'pol.id', '=', 'p.policy_id')
                    ->leftJoin('fin_institutions as i', 'i.id', '=', 'pol.institution_id')
                    ->where('p.company_id', $f['company_id'])
                    ->where('pol.is_active', true)
                    ->whereBetween('p.period_from', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'pol.branch_id'))
                    ->when(in_array($f['state'] ?? null, ['paid', 'overdue', 'today', 'upcoming'], true),
                        fn ($q) => $q->whereRaw("{$state} = ?", [$f['state']]))
                    ->selectRaw('p.period_from as due_on, p.period_to as period_to, pol.policy_no as policy_no, '
                        ."{$insurer} as insurer, pol.subject as subject, p.amount as amount, "
                        ."CASE WHEN {$paid} THEN 0 ELSE p.amount END as unpaid, {$label} as state_label, "
                        ."'insurance_premium' as source_type, p.id as source_id")
                    ->orderBy('p.period_from')->orderBy('pol.policy_no');
            },
            columns: [
                ['key' => 'due_on', 'label' => 'finance::insurance.premium_due_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'policy_no', 'label' => 'finance::insurance.policy_no', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'insurer', 'label' => 'finance::insurance.insurer'],
                ['key' => 'subject', 'label' => 'finance::insurance.subject'],
                ['key' => 'amount', 'label' => 'finance::insurance.premium', 'type' => ReportColumn::MONEY],
                ['key' => 'unpaid', 'label' => 'finance::insurance.premium_unpaid', 'type' => ReportColumn::MONEY],
                ['key' => 'state_label', 'label' => 'finance::insurance.premium_state', 'width' => '8rem'],
            ],
        );
    }
}
