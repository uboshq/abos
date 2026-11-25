<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ টাকা আসা — গ্রাহক ধরে, আর তার রসিদগুলো (মালিক, ২৫ নভেম্বর ২০২৬: "এখনই লাগবে, খুব জরুরি" — বাকি রূপ, দিন/শাখা/এলাকা/বিক্রয়কর্মী,
 *   পরের সংস্করণে এই একই নামে)।
 *
 * ⓘ "আদায়ের তালিকা"র একই টাকা: নগদ, ব্যাংক, মোবাইল ব্যাংকিংয়ের খাতে ডেবিট, কেবল রসিদের কাগজ (RCV, পুরনো COL, পাশ হওয়া চেক); উল্টানো
 *   কাগজ আর নিজের এক খাত থেকে আরেক খাতে নেওয়া টাকা গোনা হয় না। ⛔ শাখার আর ডিলারের দেয়াল ইঞ্জিনের।
 */
final class MoneyInReports
{
    public const PAPERS = 'sales.money_in_papers';

    public const MONEY_IN = ['receipt_voucher', 'collection', 'cheque:cleared'];

    private const KINDS = ['cash', 'bank', 'mfs'];

    private const FILTERS = ['date_range', 'branch', 'customer_id', 'kind'];

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::byCustomer());
        $engine->register(self::papers());
    }

    private static function kinds(): array
    {
        return ['kind' => array_combine(self::KINDS, array_map(fn (string $k) => 'sales::money_in.kind_'.$k, self::KINDS))];
    }

    /** @param  array<string, mixed>  $f */
    private static function rows(array $f): Builder
    {
        $bn = app()->getLocale() === 'bn';
        $named = fn (string $t) => $bn ? "COALESCE(NULLIF({$t}.name_bn, ''), {$t}.name_en)" : "{$t}.name_en";
        // ⭐ গ্রাহকের টেরিটরি — নিজের জায়গা বা উপরের প্রথম যে ধাপটা টেরিটরি (মালিক, ২৫ নভেম্বর ২০২৬: "সব জায়গায় টেরিটরি")
        $at = fn (string $level, \Closure $what): string => "CASE WHEN l1.level = '{$level}' THEN ".$what('l1')." WHEN l2.level = '{$level}' THEN ".$what('l2')." WHEN l3.level = '{$level}' THEN ".$what('l3').' END';
        $up = fn (\Closure $what): string => $at('territory', $what);
        // ⓘ টাকার সারিতে পক্ষ থাকে না — একই কাগজের গ্রাহক
        $customer = '(SELECT MIN(pk.party_id) FROM ledger_entries AS pk WHERE pk.company_id = ledger_entries.company_id'
            .' AND pk.source_type = ledger_entries.source_type AND pk.source_id = ledger_entries.source_id'
            ." AND pk.party_type = 'customer' AND pk.party_id IS NOT NULL)";

        // ⭐ আগে টাকার সারি আর তার গ্রাহক, তারপর নাম জোড়া — জোড়ার শর্তে উপ-প্রশ্ন থাকলে প্রতিটা গ্রাহকে আবার চলত (৩.৮ সেকেন্ড)
        $money = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $f['company_id'])
            ->whereNotNull('accounts.money_kind')
            ->where('accounts.is_group', false)
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
            ->where('ledger_entries.debit', '>', 0)
            ->whereIn('ledger_entries.source_type', self::MONEY_IN)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as rv')
                ->whereColumn('rv.company_id', 'ledger_entries.company_id')
                ->whereColumn('rv.source_id', 'ledger_entries.source_id')
                ->whereRaw("rv.source_type = CONCAT(ledger_entries.source_type, ':reversal')"))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as o')
                ->join('accounts as oa', 'oa.id', '=', 'o.account_id')
                ->whereColumn('o.company_id', 'ledger_entries.company_id')
                ->whereColumn('o.source_type', 'ledger_entries.source_type')
                ->whereColumn('o.source_id', 'ledger_entries.source_id')
                ->where('o.credit', '>', 0)
                ->whereNull('oa.money_kind')
                ->where('oa.code', '<>', StandardChart::CASH_IN_TRANSIT))
            ->when(in_array($f['kind'] ?? null, self::KINDS, true), fn (Builder $q) => $q->where('accounts.money_kind', $f['kind']))
            ->selectRaw('ledger_entries.id as entry_id, ledger_entries.trx_date as trx_date, ledger_entries.document_no as document_no, '
                .'ledger_entries.source_type as source_type, ledger_entries.source_id as source_id, ledger_entries.debit as amount, '
                .'accounts.money_kind as kind, '.$named('accounts').' as channel, '.$customer.' as payer_id');

        $inner = DB::query()->fromSub($money, 'mo')
            ->leftJoin('customers as cu', fn ($join) => $join->on('cu.id', '=', 'mo.payer_id')->whereNull('cu.deleted_at'))
            ->leftJoin('branches as cb', 'cb.id', '=', 'cu.branch_id')
            ->leftJoin('mdm_locations as l1', 'l1.id', '=', 'cu.location_id')
            ->leftJoin('mdm_locations as l2', 'l2.id', '=', 'l1.parent_id')
            ->leftJoin('mdm_locations as l3', 'l3.id', '=', 'l2.parent_id')
            ->tap(ReportEngine::dealerWall($f, 'cu.id'))
            ->when(! empty($f['customer_id']), fn (Builder $q) => $q->where('cu.id', (int) $f['customer_id']))
            ->selectRaw('mo.*, cu.id as customer_id, '.$named('cu').' as customer, '.$named('cb').' as customer_branch, '
                .$at('point', fn (string $l) => $named($l)).' as point, '.$up(fn (string $l) => "{$l}.id").' as territory_id, '
                .$up(fn (string $l) => $named($l)).' as territory, '.$up(fn (string $l) => "{$l}.branch_id").' as territory_branch_id');

        return DB::query()->fromSub($inner, 'm')
            // ⓘ টেরিটরির ভাগ ([[territories()]]) — ০ মানে টেরিটরি বসানো নেই
            ->when(isset($f['territory']), fn (Builder $q) => $f['territory'] === 0 ? $q->whereNull('m.territory_id') : $q->where('m.territory_id', $f['territory']));
    }

    /**
     * ⭐ টেরিটরি ধরে ভাগ, প্রতিটার শেষে সাব টোটাল (মালিকের নমুনা, ২৫ নভেম্বর ২০২৬) — বেশি টাকার টেরিটরি আগে, "বসানো নেই" শেষে।
     * ⓘ একটাই ভাগ হলে ভাগ নয় — সাব টোটাল তখন সর্বমোটের হুবহু একই অঙ্ক, আর তালিকা পাতা ধরে চলে।
     *
     * @param  array<string, mixed>  $f
     * @return list<array{id: ?int, name: string, filters: array<string, mixed>, total_label: string, of_total: bool}>
     */
    private static function territories(array $f): array
    {
        $groups = self::rows($f)
            ->leftJoin('branches as b', 'b.id', '=', 'm.territory_branch_id')
            ->groupBy('m.territory_id', 'm.territory', 'b.name_bn', 'b.name_en')
            ->selectRaw('m.territory_id, m.territory, b.name_bn, b.name_en, COUNT(DISTINCT m.customer_id) as customers, SUM(m.amount) as total')
            ->orderByRaw('m.territory_id IS NULL')->orderByRaw('SUM(m.amount) DESC')->get();

        if ($groups->count() < 2) {
            return [];
        }

        $sum = $groups->reduce(fn (string $s, object $g) => bcadd($s, (string) $g->total, 4), '0');
        $branches = $groups->pluck('name_en')->filter()->unique()->count();
        $bn = app()->getLocale() === 'bn';
        $plan = [];

        foreach ($groups as $g) {
            $name = $g->territory === null ? (string) __('sales::money_in.no_territory') : (string) $g->territory;
            // ⓘ একাধিক শাখা দেখালে কোন শাখার টেরিটরি — একই নাম চার শাখায় আছে
            $named = $branches > 1 && $g->name_en !== null ? (($bn && filled($g->name_bn) ? $g->name_bn : $g->name_en).' › '.$name) : $name;
            $share = bccomp($sum, '0', 4) > 0 ? bcdiv(bcmul((string) $g->total, '100', 6), $sum, 2) : '0.00';

            $plan[] = [
                'id' => $g->territory_id === null ? null : (int) $g->territory_id,
                'name' => $named,
                'filters' => [...$f, 'territory' => (int) ($g->territory_id ?? 0)],
                'total_label' => (string) __('sales::money_in.territory_total', ['territory' => $named, 'customers' => $g->customers, 'share' => $share]),
                'of_total' => true,
            ];
        }

        return $plan;
    }

    private static function byCustomer(): ReportDefinition
    {
        $papers = fn (array $more = []): array => [
            'route' => 'sales.report.show',
            'params' => ['slug' => '=money-in-receipts', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id', 'customer_id' => 'customer_id', ...$more],
            'label' => 'sales::money_in.drill_receipts',
        ];
        $columns = [
            // ⭐ গ্রাহক, পয়েন্ট, এরিয়া, শাখা — প্রতিটা নিজের কলামে, নামের সাথে জোড়া নয় (মালিক, ২৫ নভেম্বর ২০২৬ সন্ধ্যা)
            ['key' => 'customer_name', 'label' => 'sales::money_in.level_customer', 'width' => '17rem'],
            ['key' => 'point', 'label' => 'sales::money_in.level_point', 'width' => '10rem'],
            ['key' => 'territory', 'label' => 'sales::money_in.level_territory', 'width' => '7rem'],
            ['key' => 'customer_branch', 'label' => 'sales::money_in.branch', 'width' => '7rem', 'all_branches_only' => true],
            ['key' => 'receipts', 'label' => 'sales::money_in.receipts', 'type' => ReportColumn::QUANTITY, 'width' => '4rem'],
        ];

        foreach (self::KINDS as $kind) {
            $columns[] = ['key' => $kind, 'label' => 'sales::money_in.kind_'.$kind, 'type' => ReportColumn::MONEY, 'drill' => $papers(['kind' => '='.$kind])];
        }

        $columns[] = ['key' => 'total', 'label' => 'sales::money_in.total', 'type' => ReportColumn::MONEY, 'drill' => $papers()];

        return new ReportDefinition(
            key: 'sales.money_in_customer',
            permission: 'sales.report',
            title: 'sales::money_in.title_customer',
            filters: self::FILTERS,
            choices: self::kinds(),
            groupBy: 'customer_id',
            rankBy: 'total',
            splitByBranch: false,
            sections: fn (array $f): array => self::territories($f),
            summary: function (array $totals): array {
                return [
                    'label' => (string) __('sales::money_in.summary', ['receipts' => Money::quantity((string) ($totals['receipts'] ?? '0'))]),
                    'value' => (string) ($totals['total'] ?? '0'),
                    'good' => true,
                    'lines' => array_map(fn (string $k) => ['label' => (string) __('sales::money_in.kind_'.$k), 'value' => (string) ($totals[$k] ?? '0')], self::KINDS),
                ];
            },
            query: function (array $f): Builder {
                $none = DB::getPdo()->quote((string) __('sales::money_in.no_customer'));
                $kinds = implode(', ', array_map(fn (string $k) => "SUM(CASE WHEN m.kind = '{$k}' THEN m.amount ELSE 0 END) as {$k}", self::KINDS));

                $no = DB::getPdo()->quote((string) __('sales::money_in.no_territory'));

                return self::rows($f)->groupBy('m.customer_id', 'm.customer', 'm.point', 'm.territory', 'm.customer_branch')
                    ->selectRaw("m.customer_id as customer_id, COALESCE(m.customer, {$none}) as customer_name, m.point as point, COALESCE(m.territory, {$no}) as territory, m.customer_branch as customer_branch, "
                        ."COUNT(DISTINCT CONCAT(m.source_type, ':', m.source_id)) as receipts, {$kinds}, SUM(m.amount) as total")
                    ->orderByRaw('SUM(m.amount) DESC')->orderBy('m.customer_id');
            },
            columns: $columns,
        );
    }

    private static function papers(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::PAPERS,
            permission: 'sales.report',
            title: 'sales::money_in.title_papers',
            filters: self::FILTERS,
            choices: self::kinds(),
            splitByBranch: false,
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $cases = implode(' ', array_map(fn (string $k) => "WHEN '{$k}' THEN ".$pdo->quote((string) __('sales::money_in.kind_'.$k)), self::KINDS));
                $none = $pdo->quote((string) __('sales::money_in.no_customer'));

                return self::rows($f)
                    ->selectRaw("m.trx_date, m.document_no, m.source_type, m.source_id, m.customer_id, COALESCE(m.customer, {$none}) as customer_name, "
                        ."m.channel, CASE m.kind {$cases} ELSE m.kind END as kind_label, m.amount")
                    ->orderBy('m.trx_date')->orderBy('m.entry_id');
            },
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'core.table.document', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id', 'width' => '11rem'],
                ['key' => 'customer_name', 'label' => 'sales::money_in.received_from', 'place' => ['customer', 'customer_id']],
                ['key' => 'channel', 'label' => 'sales::money_in.channel'],
                ['key' => 'kind_label', 'label' => 'sales::money_in.kind', 'width' => '9rem'],
                ['key' => 'amount', 'label' => 'sales::money_in.amount', 'type' => ReportColumn::MONEY,
                    'drill' => ['source' => ['type' => 'source_type', 'id' => 'source_id']]],
            ],
        );
    }
}
