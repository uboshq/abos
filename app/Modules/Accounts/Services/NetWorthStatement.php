<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ নিট সম্পদ বিবরণী (Net Worth Statement) — মালিকের এক নজর: "আমি কোথায় আছি" (মালিকের সরাসরি আদেশ, fe, ২৫ অক্টোবর ২০২৬;
 * নমুনা "নিট-সম্পদ-বিবরণী-নমুনা-২৫-অক্টোবর.pdf")।
 *
 * মোট সম্পদ − সরবরাহকারীর পাওনা − বাকি দায় = নিট সম্পদ। নিচে মিল: মূলধন − উত্তোলন + আগের লাভ + এ বছরের লাভ = একই অঙ্ক।
 *
 * ⛔ ভাগ খাতের ধরন আর গাছ ধরে, কোডের প্রথম অক্ষর ধরে নয় (fe): নগদ ও ব্যাংক = `money_kind`-ওয়ালা খাত; গ্রাহকের পাওনা = প্রাপ্যের
 * ([[StandardChart::RECEIVABLE]]) গাছ; মজুদ = মজুদের ([[StandardChart::INVENTORY]]) গাছ; বাকি সম্পদ "অন্যান্য"। দায়ে সরবরাহকারীর
 * পাওনা = প্রদেয়ের ([[StandardChart::PAYABLE]]) গাছ, বাকি সব (ঋণসহ) আরেক লাইনে। মূলধনে মূলধন আর উত্তোলনের গাছ, বাকি মূলধনের খাত
 * আর আগের বছরের বন্ধ-না-হওয়া ফল "আগের লাভ", বছরের শুরু থেকে আয় − ব্যয় "এ বছরের লাভ"।
 * ⛔ গোটা কোম্পানি, সব শাখা — হেডারের শাখা এখানে ছাঁকে না: মালিকের অবস্থান কোনো এক শাখার নয়।
 * ⛔ দেখেন কেবল ঐ কোম্পানির মালিক ([[Ownership::isOwnerIn()]], super admin ভূমিকা) — অন্য কারো কাছে অঙ্কই তৈরি হয় না।
 */
final class NetWorthStatement
{
    /** ঘরের ক্রম — পর্দা, ফোন আর মিল একই নাম পড়ে */
    public const LINES = ['cash', 'receivable', 'stock', 'other_assets', 'assets', 'payable', 'other_liabilities', 'net',
        'capital', 'drawings', 'earlier', 'this_year', 'equity'];

    public function __construct(private readonly Ownership $ownership) {}

    public function mayView(?User $user, int $companyId): bool
    {
        return $user !== null && $this->ownership->isOwnerIn($user, $companyId);
    }

    /**
     * মালিক যেসব কোম্পানির মালিক — প্রতিটা আলাদা, আর সব মিলিয়ে। ⓘ কোনো কোম্পানির মালিক না হলে খালি তালিকা।
     *
     * ⛔ যে কোম্পানিতে তিনি মালিক নন, সেটা "সব মিলিয়ে"-তে যোগ হয় না — আর চুপচাপ বাদও নয়: গ্রুপের মালিকের
     * ([[Ownership::isGroupOwner()]]) জন্য সেগুলো `missing`-এ, পর্দা নিচে লেখে "… দেখা যাচ্ছে না — আপনি সেখানে মালিক হিসেবে
     * নেই" (fe, ২৫ অক্টোবর ২০২৬: লাইভে AVA-তে ১০ অক্টোবর থেকে মালিক নেই, ফেরানো batch-24-এর restore-owner-এ)।
     *
     * @return array{companies: list<array<string, mixed>>, all: array<string, string>|null, missing: list<array{code: string, name: string}>}
     */
    public function forOwner(User $user, ?string $asOf = null): array
    {
        $companies = [];
        $missing = [];
        $groupOwner = $this->ownership->isGroupOwner($user);

        foreach (Company::query()->where('is_active', true)->orderBy('id')->get() as $company) {
            if ($this->mayView($user, (int) $company->id)) {
                $companies[] = ['id' => (int) $company->id, 'code' => (string) $company->code, 'name' => $company->name(),
                    ...$this->forCompany((int) $company->id, $asOf)];
            } elseif ($groupOwner) {
                $missing[] = ['code' => (string) $company->code, 'name' => $company->name()];
            }
        }

        if ($companies === []) {
            return ['companies' => [], 'all' => null, 'missing' => $missing];
        }

        $all = [];
        foreach (self::LINES as $line) {
            $all[$line] = array_reduce($companies, fn (string $sum, array $c) => bcadd($sum, $c['figures'][$line], 4), '0.0000');
        }

        return ['companies' => $companies, 'all' => $all, 'missing' => $missing];
    }

    /**
     * এক কোম্পানির বিবরণী — ঘরের অঙ্ক, প্রতিটা ঘরের খাত (উৎস খোলার জন্য) আর দুই দিক মেলে কি না।
     * ⛔ মালিক কি না, ডাকনেওয়ালা আগে দেখে নেয় ([[mayView()]])।
     *
     * @return array{figures: array<string, string>, accounts: array<string, list<array{id: int, code: string, name: string, amount: string}>>, agrees: bool, as_of: string}
     */
    public function forCompany(int $companyId, ?string $asOf = null): array
    {
        $asOf ??= Carbon::today()->toDateString();

        return CompanyContext::forCompany($companyId, fn () => $this->build($companyId, $asOf));
    }

    /** @return array{figures: array<string, string>, accounts: array<string, list<array{id: int, code: string, name: string, amount: string}>>, agrees: bool, as_of: string} */
    private function build(int $companyId, string $asOf): array
    {
        $net = $this->balances($companyId, $asOf);
        /*
         * ⓘ জের আছে এমন খাত আইডি ধরে, আর গাছের দলগুলো আলাদা করে — কোন খাত কোন ঘরে (পাওনা, মজুদ…) জানতে মা-খাতের সারি লাগে।
         * কোনো খাত বাছাই নয়, টাকা বসানো নয় (MoneyNeverLandsOnAGroupAccountTest — ছাড় না নিয়ে, ৮ নভেম্বর ২০২৬)।
         */
        $accounts = Account::query()->whereKey(array_keys($net))->get()
            ->concat(Account::query()->where('company_id', $companyId)->where('is_group', true)->get())
            ->keyBy('id');

        $roots = [
            'receivable' => $this->idOf($accounts, StandardChart::RECEIVABLE),
            'stock' => $this->idOf($accounts, StandardChart::INVENTORY),
            'payable' => $this->idOf($accounts, StandardChart::PAYABLE),
            'capital' => $this->idOf($accounts, StandardChart::OWNER_CAPITAL),
            'drawings' => $this->idOf($accounts, StandardChart::DRAWINGS),
        ];

        $figures = array_fill_keys(self::LINES, '0.0000');
        $lines = array_fill_keys(self::LINES, []);
        $income = '0.0000';

        foreach ($net as $accountId => $amount) {
            $account = $accounts->get($accountId);

            if ($account === null) {
                continue;
            }

            [$line, $signed] = match ($account->type) {
                Account::ASSET => [match (true) {
                    in_array($account->money_kind, Account::MONEY_KINDS, true) => 'cash',
                    $this->within($accounts, $account, $roots['receivable']) => 'receivable',
                    $this->within($accounts, $account, $roots['stock']) => 'stock',
                    default => 'other_assets',
                }, $amount],
                Account::LIABILITY => [$this->within($accounts, $account, $roots['payable']) ? 'payable' : 'other_liabilities', bcmul($amount, '-1', 4)],
                Account::EQUITY => match (true) {
                    $this->within($accounts, $account, $roots['drawings']) => ['drawings', $amount],
                    $this->within($accounts, $account, $roots['capital']) => ['capital', bcmul($amount, '-1', 4)],
                    default => ['earlier', bcmul($amount, '-1', 4)],
                },
                default => [null, null],
            };

            if ($line === null) {
                // ⓘ আয় − ব্যয়, শুরু থেকে (Cr ধনাত্মক) — বছরে ভাগ নিচে
                $income = bcsub($income, $amount, 4);

                continue;
            }

            $figures[$line] = bcadd($figures[$line], $signed, 4);
            $lines[$line][] = ['id' => (int) $account->id, 'code' => (string) $account->code, 'name' => $account->name(), 'amount' => $signed];
        }

        /*
         * ⭐ এ বছরের লাভ = বছরের শুরু থেকে আয় − ব্যয়; তার আগের বন্ধ-না-হওয়া ফল "আগের লাভ"-এ যোগ — স্থিতিপত্রের একই ভাগ
         * ([[BalanceSheetService::build()]]: profit_this_year / profit_earlier)। বন্ধ বছরের ফল বন্ধের দাখিলায় সঞ্চিত মুনাফায় আছে।
         */
        $yearStart = FinancialYear::forDate($asOf)?->starts_on;
        $beforeYear = $yearStart === null ? '0.0000' : $this->profitUpTo($companyId, Carbon::parse($yearStart)->subDay()->toDateString());
        $figures['this_year'] = bcsub($income, $beforeYear, 4);
        $figures['earlier'] = bcadd($figures['earlier'], $beforeYear, 4);

        $figures['assets'] = bcadd(bcadd($figures['cash'], $figures['receivable'], 4), bcadd($figures['stock'], $figures['other_assets'], 4), 4);
        $figures['net'] = bcsub(bcsub($figures['assets'], $figures['payable'], 4), $figures['other_liabilities'], 4);
        $figures['equity'] = bcadd(bcsub($figures['capital'], $figures['drawings'], 4), bcadd($figures['earlier'], $figures['this_year'], 4), 4);

        return [
            'figures' => $figures,
            'accounts' => array_filter($lines, fn (array $rows) => $rows !== []),
            'agrees' => bccomp($figures['net'], $figures['equity'], 2) === 0,
            'as_of' => $asOf,
        ];
    }

    /** @return array<int, string> খাত → Dr − Cr, শুরু থেকে তারিখ পর্যন্ত, গোটা কোম্পানি */
    private function balances(int $companyId, string $asOf): array
    {
        return DB::table('ledger_entries')
            ->where('company_id', $companyId)
            ->where('trx_date', '<=', $asOf)
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) - SUM(credit) as net')
            ->pluck('net', 'account_id')
            ->map(fn ($n) => bcadd((string) $n, '0', 4))
            ->all();
    }

    /** শুরু থেকে তারিখ পর্যন্ত আয় − ব্যয় (Cr ধনাত্মক) */
    private function profitUpTo(int $companyId, string $date): string
    {
        $row = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $companyId)
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            ->where('ledger_entries.trx_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(ledger_entries.credit) - SUM(ledger_entries.debit), 0) as profit')
            ->first();

        return bcadd((string) ($row->profit ?? '0'), '0', 4);
    }

    /** @param Collection<int, Account> $accounts */
    private function idOf(Collection $accounts, string $code): ?int
    {
        $id = $accounts->firstWhere('code', $code)?->id;

        return $id === null ? null : (int) $id;
    }

    /**
     * খাতটা কি এই মাথার গাছে (মাথা নিজেও) — বাবা ধরে উপরে ওঠা, সর্বোচ্চ বারো ধাপ (ছক চার স্তরের, ঘুরে যাওয়া বাবার পাহারা)।
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function within(Collection $accounts, Account $account, ?int $rootId): bool
    {
        if ($rootId === null) {
            return false;
        }

        $current = $account;

        for ($step = 0; $current !== null && $step < 12; $step++) {
            if ((int) $current->id === $rootId) {
                return true;
            }

            $current = $current->parent_id === null ? null : $accounts->get((int) $current->parent_id);
        }

        return false;
    }
}
