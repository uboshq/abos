<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\CompanyContext;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Finance\Models\Budget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বাজেট — লেখা, আর খতিয়ানের সাথে মেলানো। ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── কী মেলে কিসের সাথে ────────────────────────────────────────────────
 * বাজেট হাতে লেখা (`fin_budgets`), প্রকৃত খতিয়ান থেকে (`ledger_entries`)।
 * ⓘ প্রকৃতের জন্য আলাদা কোনো সংখ্যা রাখা হয় না — রাখলে একদিন খতিয়ান
 * আর বাজেটের পর্দা দুই রকম বলত।
 *
 * ── চিহ্ন ──────────────────────────────────────────────────────────────
 * খরচের খাতে ডেবিট বাড়ে, আয়ের খাতে ক্রেডিট — [[Account::balanceOn()]]-এর
 * একই নিয়মে প্রকৃতকে খাতের স্বভাব অনুযায়ী ধনাত্মক করা হয়। তাই "ভাড়া:
 * বাজেট ৫০,০০০, প্রকৃত ৫৫,০০০" দুইটাই ধনাত্মক, আর ফারাক +৫,০০০।
 *
 * ⚠️ ফারাকের ভালো-মন্দ খাতের ধরনে: খরচে বেশি মানে খারাপ, আয়ে বেশি মানে
 * ভালো। সেটা `over_is_bad` বলে দেয়, পর্দা রং বাছে।
 */
final class BudgetService
{
    /** যে খাতে বাজেট চলে — আয় আর খরচ। ⓘ সম্পদ-দায়ের "বাজেট" অন্য প্রশ্ন। */
    public const TYPES = [Account::INCOME, Account::EXPENSE];

    /**
     * এক খাত, এক বছর, (ঐচ্ছিক) এক বিভাগ — বারো মাস একসাথে।
     *
     * ⓘ শূন্য বা ফাঁকা মাস = বাজেট নেই, সারিটা থাকে না। তাই "মুছে ফেলা"
     * আলাদা কাজ নয় — ঘরটা খালি করে জমা দিলেই হয়।
     *
     * @param  array<int|string, mixed>  $months  ১..১২ → টাকা
     */
    public function saveYear(int $year, int $accountId, ?int $costCenterId, array $months): void
    {
        $account = Account::query()->find($accountId);

        if ($account === null || $account->is_group || ! in_array($account->type, self::TYPES, true)) {
            throw ValidationException::withMessages([
                'account_id' => __('finance::budget.account_must_be_income_or_expense'),
            ]);
        }

        if ($costCenterId !== null && CostCenter::query()->whereKey($costCenterId)->doesntExist()) {
            throw ValidationException::withMessages(['cost_center_id' => __('finance::budget.unknown_center')]);
        }

        $clean = [];

        foreach (range(1, 12) as $month) {
            $raw = trim((string) ($months[$month] ?? ''));

            if ($raw === '') {
                $clean[$month] = '0';

                continue;
            }

            if (! is_numeric($raw) || bccomp($raw, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    "months.{$month}" => __('finance::budget.amount_not_negative'),
                ]);
            }

            $clean[$month] = bcadd($raw, '0', 4);
        }

        DB::transaction(function () use ($year, $accountId, $costCenterId, $clean) {
            foreach ($clean as $month => $amount) {
                $existing = $this->slot($year, $month, $accountId, $costCenterId);

                if (bccomp($amount, '0', 4) === 0) {
                    $existing?->delete();

                    continue;
                }

                if ($existing !== null) {
                    $existing->update(['amount' => $amount]);

                    continue;
                }

                Budget::query()->create([
                    'company_id' => CompanyContext::id(),
                    'year' => $year,
                    'month' => $month,
                    'account_id' => $accountId,
                    'cost_center_id' => $costCenterId,
                    'amount' => $amount,
                    'created_by' => auth()->id(),
                ]);
            }
        });
    }

    /**
     * এক বছরের পরিকল্পনা — খাত (আর বিভাগ) ধরে এক সারি, বারো মাস পাশাপাশি।
     *
     * ── ⚠️ পাতা ভাগ সারিতে নয়, জোড়ায় ───────────────────────────────
     * এক "সারি" মানে বারোটা মাসের বারোটা ডাটাবেস-সারি। ⛔ সরাসরি
     * `Budget::paginate(50)` বসালে পঞ্চাশে কাট পড়ত **একটা খাতের মাঝখানে**,
     * আর পাতা ১-এ জানুয়ারি–এপ্রিল, পাতা ২-এ বাকিটা দেখাত। ⓘ তাই আগে
     * (খাত, বিভাগ) জোড়াগুলো পাতা ভাগ হয়, তারপর ঐ পাতার জোড়াগুলোর বারো
     * মাস একবারে তোলা হয় — দুইটা কোয়েরি, পাতা যত বড়ই হোক।
     */
    public function plan(int $year, ?int $costCenterId = null): LengthAwarePaginator
    {
        $pairs = Budget::query()
            ->where('year', $year)
            ->when($costCenterId, fn ($q, $id) => $q->where('cost_center_id', $id))
            ->selectRaw('account_id, cost_center_id')
            ->groupBy('account_id', 'cost_center_id')
            ->orderBy('account_id')
            ->orderBy('cost_center_id')
            ->paginate(50)
            ->withQueryString();

        if ($pairs->isEmpty()) {
            return $pairs;
        }

        $rows = Budget::query()
            ->with(['account', 'costCenter'])
            ->where('year', $year)
            ->whereIn('account_id', $pairs->pluck('account_id')->unique()->all())
            ->when($costCenterId, fn ($q, $id) => $q->where('cost_center_id', $id))
            ->get()
            ->groupBy(fn (Budget $b) => $b->account_id.':'.($b->cost_center_id ?? 0));

        $wanted = $pairs->map(fn ($p) => $p->account_id.':'.($p->cost_center_id ?? 0))->all();

        return $pairs->setCollection(
            collect($wanted)
                ->map(fn (string $key) => $rows->get($key))
                ->filter()
                ->map(function (Collection $rows) {
                    $months = array_fill(1, 12, '0');

                    foreach ($rows as $row) {
                        $months[$row->month] = (string) $row->amount;
                    }

                    return [
                        'account' => $rows->first()->account,
                        'center' => $rows->first()->costCenter,
                        'months' => $months,
                        'total' => array_reduce($months, fn (string $sum, string $m) => bcadd($sum, $m, 4), '0'),
                    ];
                })
                ->sortBy(fn (array $row) => $row['account']->code.' '.($row['center']?->code ?? ''))
                ->values()
        );
    }

    /**
     * বাজেট বনাম প্রকৃত — একটা সময়ের, খাত ধরে (আর চাইলে বিভাগ ধরে)।
     *
     * ⓘ সময় মাস ধরে: `$fromMonth`..`$toMonth` একই বছরে। প্রকৃত সেই মাসগুলোর
     * প্রথম দিন থেকে শেষ দিন পর্যন্ত খতিয়ানে।
     *
     * ⚠️ বাজেট আছে এমন খাতই আসে — বাজেট ছাড়া খরচ এখানে নয়, কারণ "বনাম"
     * কিছুর সাথে মেলাতে হয়। (সব খরচ দেখতে লাভ-ক্ষতির হিসাব আছে।)
     *
     * @return Collection<int, array{account: Account, center: ?CostCenter, budget: string, actual: string,
     *     variance: string, used_pct: ?string, over_is_bad: bool, over: bool}>
     */
    public function vsActual(int $year, int $fromMonth, int $toMonth, ?int $costCenterId = null, bool $byCenter = false): Collection
    {
        [$from, $to] = $this->period($year, $fromMonth, $toMonth);

        $budgets = Budget::query()
            ->with(['account', 'costCenter'])
            ->where('year', $year)
            ->whereBetween('month', [$fromMonth, $toMonth])
            ->when($costCenterId, fn ($q, $id) => $q->where('cost_center_id', $id))
            ->get()
            ->groupBy(fn (Budget $b) => $b->account_id.':'.($byCenter ? ($b->cost_center_id ?? 0) : 0));

        return $budgets->map(function (Collection $rows) use ($from, $to, $costCenterId, $byCenter) {
            $account = $rows->first()->account;
            $center = $byCenter ? $rows->first()->costCenter : null;

            $budget = $rows->reduce(fn (string $sum, Budget $b) => bcadd($sum, (string) $b->amount, 4), '0');

            // বিভাগ ধরে দেখালে সারির নিজের বিভাগ; নইলে ছাঁকনির বিভাগ (বা সব)
            $scopeCenter = $byCenter ? ($rows->first()->cost_center_id) : $costCenterId;
            $actual = $this->actual($account, $from, $to, $scopeCenter, $byCenter);

            $variance = bcsub($actual, $budget, 4);
            $overIsBad = $account->type === Account::EXPENSE;

            return [
                'account' => $account,
                'center' => $center,
                'budget' => $budget,
                'actual' => $actual,
                'variance' => $variance,
                'used_pct' => bccomp($budget, '0', 4) === 0 ? null : bcmul(bcdiv($actual, $budget, 6), '100', 1),
                'over_is_bad' => $overIsBad,
                'over' => bccomp($variance, '0', 4) > 0,
            ];
        })
            ->sortBy(fn (array $row) => $row['account']->code.' '.($row['center']?->code ?? ''))
            ->values();
    }

    /**
     * একটা ছোট সারাংশ — ড্যাশবোর্ডের কার্ডের জন্য: এই মাসে খরচের বাজেট কতটা খরচ হলো।
     *
     * @return array{budget: string, actual: string, used_pct: ?string}|null বাজেটই না থাকলে null
     */
    public function monthStatus(?Carbon $on = null): ?array
    {
        $on ??= now();

        $rows = $this->vsActual((int) $on->year, (int) $on->month, (int) $on->month)
            ->filter(fn (array $r) => $r['over_is_bad']);

        if ($rows->isEmpty()) {
            return null;
        }

        $budget = $rows->reduce(fn (string $s, array $r) => bcadd($s, $r['budget'], 4), '0');
        $actual = $rows->reduce(fn (string $s, array $r) => bcadd($s, $r['actual'], 4), '0');

        return [
            'budget' => $budget,
            'actual' => $actual,
            'used_pct' => bccomp($budget, '0', 4) === 0 ? null : bcmul(bcdiv($actual, $budget, 6), '100', 1),
        ];
    }

    /**
     * খতিয়ান থেকে প্রকৃত — খাতের স্বভাব অনুযায়ী ধনাত্মক।
     *
     * ⓘ `$exactCenter` = বিভাগ ধরে দেখানো হচ্ছে: তখন বিভাগ-ছাড়া বাজেটের
     * সারি মানে "বিভাগ ছাড়া খরচ" (`cost_center_id` null), গোটা খাত নয়।
     */
    private function actual(Account $account, string $from, string $to, ?int $centerId, bool $exactCenter): string
    {
        $row = LedgerEntry::query()
            ->forAccount($account->id)
            ->whereBetween('trx_date', [$from, $to])
            ->when($centerId !== null, fn ($q) => $q->where('cost_center_id', $centerId))
            ->when($exactCenter && $centerId === null, fn ($q) => $q->whereNull('cost_center_id'))
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $net = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);

        return $account->nature === Account::CREDIT ? bcmul($net, '-1', 4) : $net;
    }

    /** @return array{0: string, 1: string} মাসগুলোর প্রথম আর শেষ দিন */
    private function period(int $year, int $fromMonth, int $toMonth): array
    {
        return [
            Carbon::create($year, $fromMonth, 1)->toDateString(),
            Carbon::create($year, $toMonth, 1)->endOfMonth()->toDateString(),
        ];
    }

    private function slot(int $year, int $month, int $accountId, ?int $costCenterId): ?Budget
    {
        return Budget::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where('account_id', $accountId)
            ->when(
                $costCenterId === null,
                fn ($q) => $q->whereNull('cost_center_id'),
                fn ($q) => $q->where('cost_center_id', $costCenterId),
            )
            ->first();
    }
}
