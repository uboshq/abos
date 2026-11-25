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
        // ⓘ টাকার সারিতে পক্ষ থাকে না — একই কাগজের গ্রাহক
        $customer = '(SELECT MIN(pk.party_id) FROM ledger_entries AS pk WHERE pk.company_id = ledger_entries.company_id'
            .' AND pk.source_type = ledger_entries.source_type AND pk.source_id = ledger_entries.source_id'
            ." AND pk.party_type = 'customer' AND pk.party_id IS NOT NULL)";

        $inner = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->leftJoin('customers as cu', fn ($join) => $join->on('cu.id', '=', DB::raw($customer))->whereNull('cu.deleted_at'))
            ->where('ledger_entries.company_id', $f['company_id'])
            ->whereNotNull('accounts.money_kind')
            ->where('accounts.is_group', false)
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'cu.id'))
            ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
            ->where('ledger_entries.debit', '>', 0)
            ->whereIn('ledger_entries.source_type', self::MONEY_IN)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as rv')
                ->whereColumn('rv.company_id', 'ledger_entries.company_id')
                ->whereColumn('rv.source_id', 'ledger_entries.source_id')
                ->whereRaw("rv.source_type LIKE CONCAT(ledger_entries.source_type, ':reversal%')"))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as o')
                ->join('accounts as oa', 'oa.id', '=', 'o.account_id')
                ->whereColumn('o.company_id', 'ledger_entries.company_id')
                ->whereColumn('o.source_type', 'ledger_entries.source_type')
                ->whereColumn('o.source_id', 'ledger_entries.source_id')
                ->where('o.credit', '>', 0)
                ->whereNull('oa.money_kind')
                ->where('oa.code', '<>', StandardChart::CASH_IN_TRANSIT))
            ->when(! empty($f['customer_id']), fn (Builder $q) => $q->where('cu.id', (int) $f['customer_id']))
            ->when(in_array($f['kind'] ?? null, self::KINDS, true), fn (Builder $q) => $q->where('accounts.money_kind', $f['kind']))
            ->selectRaw('ledger_entries.id as entry_id, ledger_entries.trx_date as trx_date, ledger_entries.document_no as document_no, '
                .'ledger_entries.source_type as source_type, ledger_entries.source_id as source_id, ledger_entries.debit as amount, '
                .'accounts.money_kind as kind, '.$named('accounts').' as channel, cu.id as customer_id, '.$named('cu').' as customer');

        return DB::query()->fromSub($inner, 'm');
    }

    private static function byCustomer(): ReportDefinition
    {
        $papers = fn (array $more = []): array => [
            'route' => 'sales.report.show',
            'params' => ['slug' => '=money-in-receipts', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id', 'customer_id' => 'customer_id', ...$more],
            'label' => 'sales::money_in.drill_receipts',
        ];
        $columns = [
            // ⭐ "নাম · পয়েন্ট" — জায়গা ইঞ্জিন জোড়ে (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম)
            ['key' => 'customer_name', 'label' => 'sales::money_in.level_customer', 'place' => ['customer', 'customer_id']],
            ['key' => 'receipts', 'label' => 'sales::money_in.receipts', 'type' => ReportColumn::QUANTITY, 'width' => '6rem'],
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

                return self::rows($f)->groupBy('m.customer_id', 'm.customer')
                    ->selectRaw("m.customer_id as customer_id, COALESCE(m.customer, {$none}) as customer_name, "
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
