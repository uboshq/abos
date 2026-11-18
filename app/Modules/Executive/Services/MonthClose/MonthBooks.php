<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services\MonthClose;

use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\YearEndService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ মাস শেষের হিসাবের খাতা-পাঠ — সব অঙ্ক খাতা (`ledger_entries`) থেকে, হেডারে বাছা শাখা ধরে ([[ViewedBranch::narrow()]]; লাভ-ক্ষতি,
 * স্থিতিপত্র আর হিসাবের বোর্ডের একই নিয়ম: এক শাখা বাছা → কেবল সেই শাখা, "সব শাখা" → নাগালের শাখা + শাখাহীন)।
 *
 * ⓘ কেবল **দেখায়** — কিছু আটকায় না, কিছু লেখে না ([[EveryLedgerReaderSaysWhetherItShowsOrChecksTest]]-এর SHOWS)।
 * ⓘ সব যোগ bcmath-এ, চার ঘর; চিহ্ন: `net = debit − credit` (সম্পদ-খরচ ধনাত্মক), আয়-দায়ের বেলায় ডাকা পক্ষ উল্টে নেয়।
 */
final class MonthBooks
{
    /** @var array<int, object{id: int, parent_id: ?int, code: string, type: string, money_kind: ?string, is_group: bool, name_en: string, name_bn: ?string}>|null */
    private ?array $accounts = null;

    /** @var array<string, list<int>> */
    private array $subtrees = [];

    /** কোম্পানির সব খাত — একবার, স্মৃতিতে */
    public function accounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $this->accounts = [];

        foreach (DB::table('accounts')->where('company_id', CompanyContext::id())->whereNull('deleted_at')
            ->get(['id', 'parent_id', 'code', 'type', 'money_kind', 'is_group', 'name_en', 'name_bn']) as $row) {
            $this->accounts[(int) $row->id] = $row;
        }

        return $this->accounts;
    }

    /**
     * ছকের কয়েকটা খাত আর তাদের নিচের সব খাত — কোড ধরে ([[CoreReports::subtreeIds()]]-এর একই হাঁটা)।
     *
     * @param  list<string>  $codes
     * @return list<int>
     */
    public function subtree(array $codes): array
    {
        $key = implode(',', $codes);

        if (isset($this->subtrees[$key])) {
            return $this->subtrees[$key];
        }

        $children = [];

        foreach ($this->accounts() as $a) {
            $children[(int) $a->parent_id][] = (int) $a->id;
        }

        $queue = [];

        foreach ($this->accounts() as $a) {
            if (in_array((string) $a->code, $codes, true)) {
                $queue[] = (int) $a->id;
            }
        }

        $ids = [];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($ids[$id])) {
                continue;
            }

            $ids[$id] = $id;
            array_push($queue, ...($children[$id] ?? []));
        }

        return $this->subtrees[$key] = array_values($ids);
    }

    /** @return list<int> টাকার খাত — নগদ, ব্যাংক, মোবাইল ব্যাংকিং (দলের খাত নয়) */
    public function moneyAccounts(): array
    {
        return array_values(array_map(fn ($a) => (int) $a->id, array_filter($this->accounts(),
            fn ($a) => in_array((string) $a->money_kind, Account::MONEY_KINDS, true) && ! (bool) $a->is_group)));
    }

    /**
     * আয়-খরচের প্রতিটা খাতের নিট (`credit − debit`) — সময়ের ভেতরে, বছর বন্ধের দাখিলা বাদে (লাভ-ক্ষতির পাতার হুবহু নিয়ম,
     * [[CoreReports::profitAndLoss()]])।
     *
     * @return array<int, string>
     */
    public function profitAndLossByAccount(string $from, string $to): array
    {
        $out = [];

        foreach ($this->base()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
            ->groupBy('ledger_entries.account_id')
            ->selectRaw('ledger_entries.account_id as id, SUM(ledger_entries.credit) - SUM(ledger_entries.debit) as net')
            ->get() as $row) {
            $out[(int) $row->id] = bcadd((string) $row->net, '0', 4);
        }

        return $out;
    }

    /**
     * খাতগুলোর জের — একটা দিনের **আগে** পর্যন্ত (`debit − credit`)।
     *
     * @param  list<int>  $ids
     */
    public function balanceBefore(array $ids, string $date): string
    {
        if ($ids === []) {
            return '0.0000';
        }

        return bcadd((string) $this->base()->whereIn('ledger_entries.account_id', $ids)
            ->where('ledger_entries.trx_date', '<', $date)
            ->selectRaw('COALESCE(SUM(ledger_entries.debit) - SUM(ledger_entries.credit), 0) as n')->value('n'), '0', 4);
    }

    /**
     * সময়ের ভেতরে ডেবিট আর ক্রেডিটের যোগ।
     *
     * @param  list<int>  $ids
     * @return array{debit: string, credit: string}
     */
    public function movement(array $ids, string $from, string $to): array
    {
        if ($ids === []) {
            return ['debit' => '0.0000', 'credit' => '0.0000'];
        }

        $row = $this->base()->whereIn('ledger_entries.account_id', $ids)
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as d, COALESCE(SUM(ledger_entries.credit), 0) as c')->first();

        return ['debit' => bcadd((string) ($row->d ?? '0'), '0', 4), 'credit' => bcadd((string) ($row->c ?? '0'), '0', 4)];
    }

    /**
     * খাত ধরে — শুরুর জের, এল (ডেবিট), গেল (ক্রেডিট)।
     *
     * @param  list<int>  $ids
     * @return array<int, array{opening: string, in: string, out: string}>
     */
    public function perAccount(array $ids, string $from, string $to): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->base()->whereIn('ledger_entries.account_id', $ids)
            ->where('ledger_entries.trx_date', '<=', $to)
            ->groupBy('ledger_entries.account_id')
            ->selectRaw('ledger_entries.account_id as id')
            ->selectRaw('COALESCE(SUM(CASE WHEN ledger_entries.trx_date < ? THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END), 0) as opening', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN ledger_entries.trx_date >= ? THEN ledger_entries.debit ELSE 0 END), 0) as d', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN ledger_entries.trx_date >= ? THEN ledger_entries.credit ELSE 0 END), 0) as c', [$from])
            ->get() as $row) {
            $out[(int) $row->id] = [
                'opening' => bcadd((string) $row->opening, '0', 4),
                'in' => bcadd((string) $row->d, '0', 4),
                'out' => bcadd((string) $row->c, '0', 4),
            ];
        }

        return $out;
    }

    /**
     * ⭐ একটা খাতের মাসের নড়াচড়া, কাগজ ধরে ভাগ — প্রতিটা কাগজের (উৎস) উল্টো পাশে কোন খাত আছে তা দেখে ([[MonthlyCashReport]]-এর
     * একই কৌশল)। ⓘ `$classes`-এর ক্রমে প্রথম মিলটা জেতে; কোনোটা না মিললে `other`। ⇒ ভাগগুলোর যোগ = খাতের মোট নড়াচড়া, পয়সায়।
     *
     * @param  list<int>  $ids  যে খাতের নড়াচড়া
     * @param  array<string, list<int>>  $classes  ভাগের নাম → উল্টো পাশের খাত
     * @return array<string, string> ভাগ → নিট (`debit − credit`)
     */
    public function rollBySource(array $ids, string $from, string $to, array $classes): array
    {
        $out = array_fill_keys([...array_keys($classes), 'other'], '0.0000');

        if ($ids === []) {
            return $out;
        }

        $query = $this->base()->whereIn('ledger_entries.account_id', $ids)
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->groupBy('ledger_entries.source_type', 'ledger_entries.source_id')
            ->selectRaw('COALESCE(SUM(ledger_entries.debit) - SUM(ledger_entries.credit), 0) as net');

        foreach ($classes as $name => $other) {
            $list = implode(',', array_map('intval', $other)) ?: '0';
            $query->selectRaw('MAX(EXISTS(SELECT 1 FROM ledger_entries o WHERE o.company_id = ledger_entries.company_id'
                .' AND o.source_type = ledger_entries.source_type AND o.source_id = ledger_entries.source_id'
                .' AND o.account_id IN ('.$list.'))) as has_'.$name);
        }

        foreach ($query->get() as $row) {
            $bucket = 'other';

            foreach (array_keys($classes) as $name) {
                if ((int) ($row->{'has_'.$name} ?? 0) === 1) {
                    $bucket = $name;
                    break;
                }
            }

            $out[$bucket] = bcadd($out[$bucket], (string) $row->net, 4);
        }

        return $out;
    }

    /**
     * পক্ষ ধরে জের — একটা দিন পর্যন্ত (`debit − credit`), শূন্য বাদে।
     *
     * @param  list<int>  $ids
     * @return array<int, string> পক্ষের আইডি → জের
     */
    public function byParty(array $ids, string $partyType, string $asOf): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->base()->whereIn('ledger_entries.account_id', $ids)
            ->where('ledger_entries.party_type', $partyType)
            ->whereNotNull('ledger_entries.party_id')
            ->where('ledger_entries.trx_date', '<=', $asOf)
            ->groupBy('ledger_entries.party_id')
            ->havingRaw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) <> 0')
            ->selectRaw('ledger_entries.party_id as id, SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as net')
            ->get() as $row) {
            $out[(int) $row->id] = bcadd((string) $row->net, '0', 4);
        }

        return $out;
    }

    /**
     * ⓘ একজন পক্ষের সবচেয়ে পুরনো না-মেটা ডেবিটের তারিখ — আগের আদায় আগের বিল মেটায় (FIFO; গ্রাহকের বয়সের রিপোর্টের একই নিয়ম)।
     *
     * ⓘ `$ids` null = পক্ষের সব খাত — গ্রাহকের বয়সের রিপোর্ট যেমন পক্ষের সব সারি পড়ে ([[PartyReports::ageing()]])।
     *
     * @param  list<int>|null  $ids
     */
    public function oldestUnpaidDate(?array $ids, string $partyType, int $partyId, string $asOf): ?string
    {
        $rows = $this->base()->when($ids !== null, fn ($q) => $q->whereIn('ledger_entries.account_id', $ids))
            ->where('ledger_entries.party_type', $partyType)
            ->where('ledger_entries.party_id', $partyId)
            ->where('ledger_entries.trx_date', '<=', $asOf)
            ->orderBy('ledger_entries.trx_date')->orderBy('ledger_entries.id')
            ->get(['ledger_entries.trx_date', 'ledger_entries.debit', 'ledger_entries.credit']);

        $paid = '0';

        foreach ($rows as $row) {
            $paid = bcadd($paid, (string) $row->credit, 4);
        }

        foreach ($rows as $row) {
            $debit = (string) $row->debit;

            if (bccomp($debit, '0', 4) <= 0) {
                continue;
            }

            if (bccomp($paid, $debit, 4) >= 0) {
                $paid = bcsub($paid, $debit, 4);

                continue;
            }

            return substr((string) $row->trx_date, 0, 10);
        }

        return null;
    }

    /** খাতার ভিত — চলতি কোম্পানি, হেডারে বাছা শাখা */
    private function base(): Builder
    {
        return ViewedBranch::narrow(
            DB::table('ledger_entries')->where('ledger_entries.company_id', CompanyContext::id()),
            'ledger_entries.branch_id',
        );
    }
}
