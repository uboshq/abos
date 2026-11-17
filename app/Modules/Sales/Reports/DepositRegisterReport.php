<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DepositClaim;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ প্রতিদিনের জমার খাতা — জমার খবর ধাপ গ (মালিক, ১৬ নভেম্বর ২০২৬; NEXUS নমুনার "দৈনিক জমা রেজিস্টার")।
 *
 * প্রতিটা খবর এক সারি: খবরের তারিখ, কার টাকা ("নাম · পয়েন্ট · শাখা"), কে দিলেন, ব্যাংক, রেফারেন্স, ব্যাংকের তারিখ, ধাপ, অঙ্ক।
 *   · ধাপ — খবর এসেছে (ব্যাংকে এখনো নেই) · মিলেছে · অনুমোদনের অপেক্ষায় · খাতায় · ফেরত ([[DepositClaim::stage()]]-এর একই নিয়ম, SQL-এ)
 *   · ⛔ "খাতায় উঠেছে" কলামে কেবল পাকা আদায়ের অঙ্ক — খসড়া, সইয়ের অপেক্ষা, ফেরত আর পরে বাতিল হওয়া আদায় কখনো যোগে নয়
 *     (মালিকের নিয়ম: মোটে কেবল চূড়ান্ত কাগজ)। অপেক্ষার টাকা নিজের কলামে, আর উপরে নিজের লাইনে ([[summary]])।
 *   · খবরের অঙ্কের মোট নেই — ওতে ফেরতও আছে।
 * ⓘ শাখার আর ডিলারের দেয়াল ইঞ্জিনের ([[ReportEngine::branchWall()]], [[ReportEngine::dealerWall()]])।
 */
final class DepositRegisterReport
{
    public const KEY = 'sales.deposit_register';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::definition());
    }

    public static function definition(): ReportDefinition
    {
        $stages = [DepositClaim::STAGE_REPORTED, DepositClaim::STAGE_MATCHED, DepositClaim::STAGE_AWAITING, DepositClaim::STAGE_CONFIRMED, DepositClaim::STAGE_REJECTED];

        return new ReportDefinition(
            key: self::KEY,
            permission: 'sales.claim.view',
            title: 'sales::deposit.register_title',
            filters: ['date_range', 'branch', 'account', 'status'],
            sources: ['account' => \App\Modules\Accounts\Reports\Filters\MoneyAccountFilter::class],
            choices: ['status' => array_combine($stages, array_map(fn (string $s) => 'sales::deposit.stage.'.$s, $stages))],
            summary: fn (array $totals): array => [
                'label' => __('sales::deposit.register_summary', [
                    'final' => \App\Core\Support\Money::format($totals['confirmed_amount'] ?? '0'),
                ]),
                'value' => bcadd((string) ($totals['waiting_amount'] ?? '0'), '0', 2),
                'text' => __('sales::deposit.register_pending', ['amount' => \App\Core\Support\Money::format($totals['waiting_amount'] ?? '0')]),
                'good' => bccomp((string) ($totals['waiting_amount'] ?? '0'), '0', 4) === 0,
            ],
            query: fn (array $f) => self::query($f),
            columns: [
                ['key' => 'claimed_on', 'label' => 'sales::portal.claimed_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                // ⭐ "নাম · পয়েন্ট · শাখা" — জায়গা ইঞ্জিন জোড়ে (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম)
                ['key' => 'customer_name', 'label' => 'sales::field.customer', 'place' => ['customer', 'customer_id']],
                ['key' => 'given_by', 'label' => 'sales::deposit.given_by'],
                ['key' => 'bank_name', 'label' => 'sales::portal.bank_account'],
                ['key' => 'reference', 'label' => 'sales::portal.reference'],
                ['key' => 'bank_date', 'label' => 'sales::deposit.bank_date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'stage_label', 'label' => 'sales::portal.status', 'width' => '8rem'],
                ['key' => 'receipt_no', 'label' => 'sales::deposit.receipt_no', 'width' => '8rem'],
                ['key' => 'reported_amount', 'label' => 'sales::deposit.reported_amount', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'confirmed_amount', 'label' => 'sales::deposit.confirmed_amount', 'type' => ReportColumn::MONEY, 'total' => true],
                ['key' => 'waiting_amount', 'label' => 'sales::deposit.waiting_amount', 'type' => ReportColumn::MONEY, 'total' => true],
            ],
        );
    }

    /** ⓘ ধাপের SQL — [[DepositClaim::stage()]]-এর হুবহু */
    public static function stageSql(string $a = 'dc'): string
    {
        return "CASE WHEN {$a}.status = '".DepositClaim::ACCEPTED."' THEN '".DepositClaim::STAGE_CONFIRMED."'"
            ." WHEN {$a}.status = '".DepositClaim::REJECTED."' THEN '".DepositClaim::STAGE_REJECTED."'"
            ." WHEN {$a}.collection_id IS NOT NULL THEN '".DepositClaim::STAGE_AWAITING."'"
            ." WHEN {$a}.statement_line_id IS NOT NULL THEN '".DepositClaim::STAGE_MATCHED."'"
            ." ELSE '".DepositClaim::STAGE_REPORTED."' END";
    }

    /** @param  array<string, mixed>  $f */
    private static function query(array $f): Builder
    {
        $stage = self::stageSql();
        $posted = "'".implode("', '", DocumentStatus::POSTED)."'";
        $open = "'".implode("', '", DepositClaim::OPEN)."'";
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(c.name_bn, ''), c.name_en)" : 'c.name_en';
        $bank = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(a.name_bn, ''), a.name_en)" : 'a.name_en';

        $labels = collect([DepositClaim::STAGE_REPORTED, DepositClaim::STAGE_MATCHED, DepositClaim::STAGE_AWAITING, DepositClaim::STAGE_CONFIRMED, DepositClaim::STAGE_REJECTED])
            ->map(fn (string $s) => "WHEN '{$s}' THEN ".DB::getPdo()->quote((string) __('sales::deposit.stage.'.$s)))
            ->implode(' ');

        $status = $f['status'] ?? null;

        return DB::table('sal_deposit_claims as dc')
            ->join('customers as c', 'c.id', '=', 'dc.customer_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'dc.bank_account_id')
            ->leftJoin('acc_bank_statement_lines as l', 'l.id', '=', 'dc.statement_line_id')
            ->leftJoin('sal_collections as col', 'col.id', '=', 'dc.collection_id')
            ->leftJoin('users as u', 'u.id', '=', 'dc.submitted_by')
            ->where('dc.company_id', $f['company_id'])
            ->whereNull('dc.deleted_at')
            ->tap(ReportEngine::branchWall($f, 'dc.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'dc.customer_id'))
            ->whereBetween('dc.claimed_on', [$f['from'], $f['to']])
            ->when(($f['account'] ?? null) !== null, fn ($q) => $q->where('dc.bank_account_id', (int) $f['account']))
            ->when(is_string($status) && $status !== '', fn ($q) => $q->whereRaw("({$stage}) = ?", [$status]))
            ->orderBy('dc.claimed_on')
            ->orderBy('dc.id')
            ->selectRaw('dc.claimed_on')
            ->selectRaw("{$name} as customer_name")
            ->selectRaw('dc.customer_id')
            ->selectRaw("COALESCE(NULLIF(dc.depositor, ''), u.name) as given_by")
            ->selectRaw("{$bank} as bank_name")
            ->selectRaw('dc.reference')
            ->selectRaw('l.trx_date as bank_date')
            ->selectRaw("CASE ({$stage}) {$labels} END as stage_label")
            ->selectRaw("CASE WHEN dc.status = '".DepositClaim::ACCEPTED."' AND col.status IN ({$posted}) THEN col.document_no END as receipt_no")
            ->selectRaw('dc.amount as reported_amount')
            // ⛔ কেবল পাকা আদায় — খবর গৃহীত আর আদায় এখনো খাতায় (পরে বাতিল হলে নয়)
            ->selectRaw("CASE WHEN dc.status = '".DepositClaim::ACCEPTED."' AND col.status IN ({$posted}) THEN col.amount END as confirmed_amount")
            ->selectRaw("CASE WHEN dc.status IN ({$open}) THEN dc.amount END as waiting_amount");
    }
}
