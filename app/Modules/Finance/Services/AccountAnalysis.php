<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Services\PartyRegistry;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * খাত বিশ্লেষণ — একটা খাত মাসে মাসে কীভাবে নড়ল (মানচিত্র §৪)।
 *
 * ── খতিয়ান থেকে কী আলাদা ────────────────────────────────────────────
 * খতিয়ান দেয় প্রতিটা দাখিলা — সারির পর সারি। ⓘ এখানে একই দাখিলাগুলো
 * তিনভাবে গোছানো: **মাস ধরে** (শুরুর জের · ডেবিট · ক্রেডিট · শেষ জের),
 * **কাগজের ধরন ধরে** (বিক্রয়, ক্রয়, ভাউচার…), আর **পক্ষ ধরে** (কার
 * সাথে সবচেয়ে বেশি)। "ভাড়া খাতে মার্চে হঠাৎ দ্বিগুণ কেন" — এই প্রশ্নের
 * উত্তর খতিয়ানের দুশো সারি পড়ে নয়, এক নজরে।
 *
 * ⓘ গ্রুপ খাতে তার নিচের সব খাত একসাথে — [[Account::balanceOn()]]-এর
 * নিয়মেই। জের স্বাভাবিক দিকে ধনাত্মক (ক্রেডিট প্রকৃতিতে ক্রেডিট বেশি = +)।
 */
final class AccountAnalysis
{
    public function __construct(private readonly PartyRegistry $parties) {}

    /**
     * @return array{opening: string, closing: string, debit: string, credit: string,
     *               months: list<array{month: string, from: string, to: string, opening: string, debit: string, credit: string, closing: string, count: int}>,
     *               sources: list<array{type: string, debit: string, credit: string, count: int}>,
     *               parties: list<array{label: string, debit: string, credit: string, count: int}>}
     */
    public function of(Account $account, string $from, string $to): array
    {
        $ids = $account->is_group
            ? $account->selfAndDescendants()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [(int) $account->id];

        $sign = $account->nature === Account::CREDIT ? '-1' : '1';

        $opening = $account->balanceOn(Carbon::parse($from)->subDay()->toDateString());

        $base = fn () => LedgerEntry::query()->whereIn('account_id', $ids)->whereBetween('trx_date', [$from, $to]);

        $byMonth = $base()
            ->selectRaw("DATE_FORMAT(trx_date, '%Y-%m') as ym, SUM(debit) as d, SUM(credit) as c, COUNT(*) as n")
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $months = [];
        $running = $opening;
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to);

        while ($cursor->lte($end)) {
            $ym = $cursor->format('Y-m');
            $row = $byMonth[$ym] ?? null;
            $d = (string) ($row->d ?? '0');
            $c = (string) ($row->c ?? '0');
            $move = bcmul(bcsub($d, $c, 4), $sign, 4);

            $months[] = [
                'month' => $ym,
                'from' => max($from, $cursor->toDateString()),
                'to' => min($to, $cursor->copy()->endOfMonth()->toDateString()),
                'opening' => $running,
                'debit' => $d,
                'credit' => $c,
                'closing' => $running = bcadd($running, $move, 4),
                'count' => (int) ($row->n ?? 0),
            ];

            $cursor->addMonthNoOverflow();
        }

        $sources = $base()
            ->selectRaw('source_type, SUM(debit) as d, SUM(credit) as c, COUNT(*) as n')
            ->groupBy('source_type')
            ->orderByRaw('SUM(debit) + SUM(credit) DESC')
            ->get()
            ->map(fn ($r) => ['type' => (string) $r->source_type, 'debit' => (string) $r->d, 'credit' => (string) $r->c, 'count' => (int) $r->n])
            ->all();

        $partyRows = $base()
            ->whereNotNull('party_type')
            ->selectRaw('party_type, party_id, SUM(debit) as d, SUM(credit) as c, COUNT(*) as n')
            ->groupBy('party_type', 'party_id')
            ->orderByRaw('SUM(debit) + SUM(credit) DESC')
            ->limit(15)
            ->get();

        $labels = $this->parties->labelsOf($partyRows->map(fn ($r) => [(string) $r->party_type, (int) $r->party_id]));

        $parties = $partyRows->map(fn ($r) => [
            'label' => $labels[$r->party_type.':'.$r->party_id] ?? $r->party_type.' #'.$r->party_id,
            'debit' => (string) $r->d,
            'credit' => (string) $r->c,
            'count' => (int) $r->n,
        ])->all();

        $totals = collect($months);

        return [
            'opening' => $opening,
            'closing' => $running,
            'debit' => $this->sum($totals, 'debit'),
            'credit' => $this->sum($totals, 'credit'),
            'months' => $months,
            'sources' => $sources,
            'parties' => $parties,
        ];
    }

    private function sum(Collection $rows, string $key): string
    {
        return $rows->reduce(fn (string $c, array $r) => bcadd($c, $r[$key], 4), '0');
    }
}
