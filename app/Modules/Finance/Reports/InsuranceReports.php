<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePremium;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বীমার রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা ৬, ৬ অক্টোবর ২০২৬।
 *
 *   ৬.২ প্রিমিয়ামের সূচি — কোন প্রিমিয়াম কবে, দেওয়া / দিন পার / আজ / সামনে ([[PREMIUMS]])
 *   ৬.৪ দাবির খাতা — প্রতিটা দাবি, চাওয়া / অনুমোদিত / পাওয়া / বাকি, অবস্থা ধরে ([[CLAIMS]])
 *
 * ⓘ সারি = প্রিমিয়ামের কিস্তি (`fin_insurance_premiums`), পলিসির পাতা যা দেখায়; দিন = সময়কালের শুরু, "দেওয়া" = ভাউচারে
 * মিটেছে ([[InsurancePremium::dueState()]]-এর একই নিয়ম, SQL-এ)। ⓘ চালু পলিসির কিস্তি, তারিখ-পরিসরের ভিতরের; শাখার দেয়াল
 * পলিসির শাখায়।
 */
final class InsuranceReports
{
    public const PREMIUMS = 'finance.insurance_premiums';

    public const CLAIMS = 'finance.insurance_claims';

    /** ⛔ বীমার পাতা যে চাবি দেখে, সেটাই */
    private const KEY = 'finance.insurance.view';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::premiums());
        $engine->register(self::claims());
    }

    /**
     * ⭐ দাবির খাতা — জমা থাকা দাবিও (খাতায় নেই, তবু এখানে আছে: সমন্বয়কের উত্তর প্র৩)। দিন = দাবির দিন; বাকি =
     * (অনুমোদিত, না থাকলে চাওয়া) − পাওয়া, কেবল খোলা দাবিতে ([[InsuranceClaim::outstanding()]]-এর একই নিয়ম, SQL-এ)।
     */
    private static function claims(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::CLAIMS,
            permission: self::KEY,
            title: 'finance::insurance_claim.register_title',
            filters: ['date_range', 'branch', 'state'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $label = 'CASE c.status';
                foreach (InsuranceClaim::STATES as $s) {
                    $label .= ' WHEN '.$pdo->quote($s).' THEN '.$pdo->quote((string) __('finance::insurance_claim.state_'.$s));
                }
                $label .= ' END';
                $open = implode(', ', array_map(fn (string $s) => $pdo->quote($s), InsuranceClaim::OPEN));
                $insurer = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(i.name_bn, ''), i.name_en)" : 'i.name_en';

                return DB::table('fin_insurance_claims as c')
                    ->join('fin_insurance_policies as pol', 'pol.id', '=', 'c.policy_id')
                    ->leftJoin('fin_institutions as i', 'i.id', '=', 'pol.institution_id')
                    ->where('c.company_id', $f['company_id'])
                    ->whereBetween('c.claimed_on', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'c.branch_id'))
                    ->when(in_array($f['state'] ?? null, InsuranceClaim::STATES, true), fn ($q) => $q->where('c.status', $f['state']))
                    ->selectRaw('c.claimed_on as claimed_on, pol.policy_no as policy_no, '
                        ."{$insurer} as insurer, c.incident as incident, c.claimed_amount as claimed_amount, "
                        .'c.approved_amount as approved_amount, c.received_amount as received_amount, '
                        ."CASE WHEN c.status IN ({$open}) AND COALESCE(c.approved_amount, c.claimed_amount) > c.received_amount "
                        .'THEN COALESCE(c.approved_amount, c.claimed_amount) - c.received_amount ELSE 0 END as outstanding, '
                        ."{$label} as state_label, 'insurance_claim' as source_type, c.id as source_id")
                    ->orderBy('c.claimed_on')->orderBy('c.id');
            },
            columns: [
                ['key' => 'claimed_on', 'label' => 'finance::insurance_claim.claimed_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'policy_no', 'label' => 'finance::insurance.policy_no', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'insurer', 'label' => 'finance::insurance.insurer'],
                ['key' => 'incident', 'label' => 'finance::insurance_claim.incident'],
                ['key' => 'claimed_amount', 'label' => 'finance::insurance_claim.claimed_amount', 'type' => ReportColumn::MONEY],
                ['key' => 'approved_amount', 'label' => 'finance::insurance_claim.approved_amount', 'type' => ReportColumn::MONEY],
                ['key' => 'received_amount', 'label' => 'finance::insurance_claim.received_amount', 'type' => ReportColumn::MONEY],
                ['key' => 'outstanding', 'label' => 'finance::insurance_claim.outstanding', 'type' => ReportColumn::MONEY],
                ['key' => 'state_label', 'label' => 'finance::insurance_claim.state', 'width' => '8rem'],
            ],
        );
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
