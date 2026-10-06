<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Models\TenancyMove;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ভাড়াটের রিপোর্ট — মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬ ([[TenancyService]]):
 *
 *   ক আদায় — তারিখের মধ্যে প্রতিটা টাকার নড়াচড়া: ভাড়া আদায়, জামানত থেকে কাটা, জামানত নেওয়া, জামানত ফেরত ([[COLLECTIONS]])
 *   খ বকেয়া ও জামানত — শেষের দিনে প্রতিটা ভাড়াটে: দাবি, আদায়, বকেয়া, কত মাসের সমান, হাতে রাখা জামানত ([[ARREARS]])
 *
 * ── ⭐ একটাই উৎস ─────────────────────────────────────────────────────────────
 * কেবল খাতায় বসা ভাউচার (`confirmed`), ভাউচারের তারিখে — তাই শেষের দিনের বকেয়ার যোগ ঐ দিনের ১১২৫-এর জের, জামানতের যোগ
 * ২১৫৫-এর জের (দুই খাতে কেবল ভাড়াটের কাগজই বসে)। সইয়ের অপেক্ষা বা বাতিল কোথাও ঢোকে না।
 */
final class TenancyReports
{
    public const COLLECTIONS = 'finance.tenancy_collections';

    public const ARREARS = 'finance.tenancy_arrears';

    // ⛔ ভাড়ার পাতা যে চাবি দেখে, সেটাই
    private const KEY = 'finance.rental.view';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::collections());
        $engine->register(self::arrears());
    }

    private static function collections(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::COLLECTIONS,
            permission: self::KEY,
            title: 'finance::tenancy.collections_title',
            filters: ['date_range'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $kind = 'CASE m.kind '.implode(' ', array_map(
                    fn (string $k) => 'WHEN '.$pdo->quote($k).' THEN '.$pdo->quote((string) __('finance::tenancy.kind_'.$k)),
                    TenancyMove::KINDS,
                )).' END';
                $rent = $pdo->quote(TenancyMove::RENT);
                $cut = $pdo->quote(TenancyMove::FROM_DEPOSIT);
                $in = $pdo->quote(TenancyMove::DEPOSIT_IN);
                $out = $pdo->quote(TenancyMove::REFUND);
                // ⓘ ভাউচারের লিংক ধরন ধরে ([[Voucher::SOURCE_TYPES]])
                $source = 'CASE v.type '.implode(' ', array_map(
                    fn (string $type, string $src) => 'WHEN '.$pdo->quote($type).' THEN '.$pdo->quote($src),
                    array_keys(Voucher::SOURCE_TYPES), Voucher::SOURCE_TYPES,
                )).' END';

                return DB::table('fin_tenancy_moves as m')
                    ->join('fin_tenancies as t', 't.id', '=', 'm.tenancy_id')
                    ->join('vouchers as v', 'v.id', '=', 'm.voucher_id')
                    ->where('m.company_id', $f['company_id'])
                    ->where('v.status', DocumentStatus::CONFIRMED)
                    ->whereBetween('v.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 't.branch_id'))
                    ->selectRaw("v.trx_date as trx_date, v.document_no as document_no, {$source} as source_type, v.id as source_id, "
                        .'t.document_no as tenancy_no, '.$pdo->quote(Tenancy::drillSourceType()).' as tenancy_type, t.id as tenancy_id, '
                        ."t.tenant as tenant, {$kind} as kind, "
                        ."CASE WHEN m.kind = {$rent} THEN m.amount ELSE 0 END as rent_cash, "
                        ."CASE WHEN m.kind = {$cut} THEN m.amount ELSE 0 END as from_deposit, "
                        ."CASE WHEN m.kind = {$in} THEN m.amount ELSE 0 END as deposit_in, "
                        ."CASE WHEN m.kind = {$out} THEN m.amount ELSE 0 END as refunded")
                    ->orderBy('v.trx_date')
                    ->orderBy('m.id');
            },
            columns: [
                ['key' => 'trx_date', 'label' => 'finance::tenancy.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'finance::tenancy.voucher', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'tenancy_no', 'label' => 'finance::tenancy.contract', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'tenancy_type', 'source_id' => 'tenancy_id'],
                ['key' => 'tenant', 'label' => 'finance::tenancy.tenant'],
                ['key' => 'kind', 'label' => 'finance::tenancy.kind'],
                ['key' => 'rent_cash', 'label' => 'finance::tenancy.rent_cash', 'type' => ReportColumn::MONEY],
                ['key' => 'from_deposit', 'label' => 'finance::tenancy.from_deposit', 'type' => ReportColumn::MONEY],
                ['key' => 'deposit_in', 'label' => 'finance::tenancy.deposit_in', 'type' => ReportColumn::MONEY],
                ['key' => 'refunded', 'label' => 'finance::tenancy.refunded', 'type' => ReportColumn::MONEY],
            ],
            summary: fn (array $totals): array => [
                'label' => __('finance::tenancy.collections_summary'),
                'value' => bcadd((string) ($totals['rent_cash'] ?? '0'), (string) ($totals['from_deposit'] ?? '0'), 4),
                'text' => Money::format(bcadd((string) ($totals['rent_cash'] ?? '0'), (string) ($totals['from_deposit'] ?? '0'), 4)),
                'good' => true,
            ],
        );
    }

    private static function arrears(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::ARREARS,
            permission: self::KEY,
            title: 'finance::tenancy.arrears_title',
            filters: ['date_range'],
            asOfDate: true,
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $to = (string) $f['to'];
                $posted = fn (string $table, string $alias) => DB::table($table.' as '.$alias)
                    ->join('vouchers as v', 'v.id', '=', $alias.'.voucher_id')
                    ->where('v.status', DocumentStatus::CONFIRMED)
                    ->where('v.trx_date', '<=', $to)
                    ->where($alias.'.company_id', $f['company_id']);

                $charged = $posted('fin_tenancy_charges', 'c')->groupBy('c.tenancy_id')
                    ->selectRaw('c.tenancy_id, SUM(c.amount) as charged');

                $settles = implode(', ', array_map(fn ($k) => $pdo->quote($k), TenancyMove::SETTLES));
                $in = $pdo->quote(TenancyMove::DEPOSIT_IN);
                $out = $pdo->quote(TenancyMove::FROM_DEPOSIT).', '.$pdo->quote(TenancyMove::REFUND);
                $moves = $posted('fin_tenancy_moves', 'm')->groupBy('m.tenancy_id')
                    ->selectRaw("m.tenancy_id, SUM(CASE WHEN m.kind IN ({$settles}) THEN m.amount ELSE 0 END) as collected, "
                        ."SUM(CASE WHEN m.kind = {$in} THEN m.amount WHEN m.kind IN ({$out}) THEN -m.amount ELSE 0 END) as deposit_held");

                $outstanding = 'COALESCE(ch.charged, 0) - COALESCE(mv.collected, 0)';

                return DB::table('fin_tenancies as t')
                    ->leftJoinSub($charged, 'ch', 'ch.tenancy_id', '=', 't.id')
                    ->leftJoinSub($moves, 'mv', 'mv.tenancy_id', '=', 't.id')
                    ->where('t.company_id', $f['company_id'])
                    ->whereNull('t.deleted_at')
                    ->whereIn('t.status', [Tenancy::ACTIVE, Tenancy::CLOSED])
                    ->where('t.starts_on', '<=', $to)
                    ->tap(ReportEngine::branchWall($f, 't.branch_id'))
                    ->selectRaw('t.document_no as document_no, '.$pdo->quote(Tenancy::drillSourceType()).' as source_type, t.id as source_id, '
                        .'t.tenant as tenant, t.premises as premises, t.monthly_rent as monthly_rent, '
                        .'COALESCE(ch.charged, 0) as charged, COALESCE(mv.collected, 0) as collected, '
                        ."{$outstanding} as outstanding, "
                        ."CASE WHEN t.monthly_rent > 0 AND {$outstanding} > 0 THEN ROUND(({$outstanding}) / t.monthly_rent, 1) ELSE 0 END as months_behind, "
                        .'COALESCE(mv.deposit_held, 0) as deposit_held')
                    ->orderByRaw("{$outstanding} DESC")
                    ->orderBy('t.id');
            },
            columns: [
                ['key' => 'document_no', 'label' => 'finance::tenancy.contract', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'tenant', 'label' => 'finance::tenancy.tenant'],
                ['key' => 'premises', 'label' => 'finance::tenancy.premises'],
                ['key' => 'monthly_rent', 'label' => 'finance::tenancy.monthly_rent', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'charged', 'label' => 'finance::tenancy.charged', 'type' => ReportColumn::MONEY],
                ['key' => 'collected', 'label' => 'finance::tenancy.collected', 'type' => ReportColumn::MONEY],
                ['key' => 'outstanding', 'label' => 'finance::tenancy.outstanding', 'type' => ReportColumn::MONEY],
                ['key' => 'months_behind', 'label' => 'finance::tenancy.months_behind', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'deposit_held', 'label' => 'finance::tenancy.deposit_held', 'type' => ReportColumn::MONEY],
            ],
            summary: fn (array $totals): array => [
                'label' => __('finance::tenancy.arrears_summary'),
                'value' => (string) ($totals['outstanding'] ?? '0'),
                'text' => __('finance::tenancy.arrears_text', [
                    'outstanding' => Money::format((string) ($totals['outstanding'] ?? '0')),
                    'deposit' => Money::format((string) ($totals['deposit_held'] ?? '0')),
                ]),
                // ⓘ কোনো বকেয়া না থাকলেই ভালো খবর
                'good' => bccomp((string) ($totals['outstanding'] ?? '0'), '0', 2) <= 0,
            ],
        );
    }
}
