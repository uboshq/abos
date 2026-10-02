<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * সরবরাহকারীর খাতা বনাম দেনার খাত — রিপোর্ট সেন্টার ধাপ ৬ (মালিক, ১ অক্টোবর ২০২৬; সমন্বয়কের ভাগ, ২ অক্টোবর)।
 *
 * *"পার্টির খাতা বনাম মূল খাতা"* — প্রতিটা সরবরাহকারী এক সারি (দেনা, তাই ক্রেডিট − ডেবিট):
 *   সরবরাহকারীর খাতায়  তাঁর নামের সব সারি, যে খাতেই থাক — [[Supplier::scopeWithPayableInView()]]-এর একই হিসাব
 *   দেনার খাতে      তার মধ্যে কেবল দেনার খাতের পরিবার (২১১০) আর তার নিচের খাতে বসা অংশ
 *   পার্থক্য         এই দুইয়ের ফারাক — সরবরাহকারীর নামে অন্য খাতে বসা টাকা (অগ্রিম, ভুল খাত)
 * ⓘ শেষে একটা সারি "পক্ষ ছাড়া": দেনার খাতে বসা অথচ কারও নামে নয় — খাতা আর সরবরাহকারীর যোগ না মেলার আরেক কারণ।
 * ⭐ তাই "দেনার খাতে" কলামের সর্বমোট = দেনার খাতের নিজের স্থিতি, আর "সরবরাহকারীর খাতায়"-র সর্বমোট = সব সরবরাহকারীর প্রদেয়ের যোগ।
 * ⓘ তারিখ পর্যন্ত (শেষ তারিখ), কোম্পানি আর শাখার দেয়াল ([[ReportEngine::branchWall()]])।
 */
final class LedgerCheckReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::ledgerCheck());
    }

    public static function ledgerCheck(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'supplier.ledger_check',
            permission: 'supplier.report',
            title: 'supplier::ledger_check.title',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::query($f),
            columns: [
                [
                    'key' => 'party_name',
                    'label' => 'supplier::ledger_check.party',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'party_id',
                ],
                ['key' => 'party_total', 'label' => 'supplier::ledger_check.party_total', 'type' => ReportColumn::MONEY],
                ['key' => 'control_total', 'label' => 'supplier::ledger_check.control_total', 'type' => ReportColumn::MONEY],
                ['key' => 'difference', 'label' => 'supplier::ledger_check.difference', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    private static function query(array $f): Builder
    {
        $control = self::controlIds();
        $in = $control === [] ? '0' : implode(',', $control);
        $type = Supplier::drillSourceType();

        $entries = fn () => DB::table('ledger_entries as le')
            ->where('le.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
            ->where('le.trx_date', '<=', $f['to']);

        // ⓘ সরবরাহকারী ধরে — ভেতরে যোগ, বাইরে নাম (ONLY_FULL_GROUP_BY)
        $byParty = $entries()
            ->where('le.party_type', $type)
            ->groupBy('le.party_id')
            ->selectRaw('le.party_id')
            ->selectRaw('SUM(le.credit - le.debit) as party_total')
            ->selectRaw("SUM(CASE WHEN le.account_id IN ({$in}) THEN le.credit - le.debit ELSE 0 END) as control_total");

        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(c.name_bn, ''), c.name_en)" : 'c.name_en';

        $parties = DB::query()->fromSub($byParty, 'p')
            ->join('suppliers as c', 'c.id', '=', 'p.party_id')
            ->where(fn (Builder $w) => $w->where('p.party_total', '<>', 0)->orWhere('p.control_total', '<>', 0))
            ->selectRaw('0 as sort_last')
            ->selectRaw("{$name} as party_name")
            ->selectRaw(DB::getPdo()->quote($type).' as source_type_literal')
            ->selectRaw('p.party_id')
            ->selectRaw('p.party_total')
            ->selectRaw('p.control_total')
            ->selectRaw('p.party_total - p.control_total as difference');

        // ⓘ দেনার খাতে বসা, অথচ কোনো সরবরাহকারীর নামে নয় — একটা সারিতে
        $nobody = DB::query()->fromSub($entries()
            ->whereIn('le.account_id', $control === [] ? [0] : $control)
            ->where(fn (Builder $w) => $w->whereNull('le.party_type')->orWhere('le.party_type', '<>', $type))
            ->selectRaw('COALESCE(SUM(le.credit - le.debit), 0) as amount'), 'n')
            ->where('n.amount', '<>', 0)
            ->selectRaw('1 as sort_last')
            ->selectRaw(DB::getPdo()->quote((string) __('supplier::ledger_check.nobody')).' as party_name')
            ->selectRaw('NULL as source_type_literal')
            ->selectRaw('NULL as party_id')
            ->selectRaw('0 as party_total')
            ->selectRaw('n.amount as control_total')
            ->selectRaw('0 - n.amount as difference');

        return DB::query()->fromSub($parties->unionAll($nobody), 'rows')
            ->orderBy('sort_last')
            ->orderByRaw('ABS(difference) DESC')
            ->orderBy('party_name');
    }

    /** @return list<int> দেনার খাতের পরিবার (২১১০) আর তার নিচের সব খাত */
    private static function controlIds(): array
    {
        return Account::query()
            ->where('code', StandardChart::PAYABLE_GROUP)
            ->get()
            ->flatMap(fn (Account $a) => $a->selfAndDescendants()->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
