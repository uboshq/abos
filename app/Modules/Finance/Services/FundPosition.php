<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Finance\Models\BankFacility;
use Illuminate\Support\Carbon;

/**
 * ⭐ অর্থের ড্যাশবোর্ডের মাথার তহবিলের ঘর — মালিকের অনুমোদিত নমুনা (৯ নভেম্বর ২০২৬, "ok kaj koro ebar")।
 *
 * মোট তহবিল = নগদ + ব্যাংক + MFS ([[AccountsFacts::moneyPositions()]]), আগের মাসের শেষের সাথে তুলনা; পাশে দুই ঘর —
 * "খরচ চলবে" (তহবিল ÷ শেষ ৯০ দিনের দিনে গড় টাকা বেরোনো) আর "৩০ দিনে আসবে − যাবে" ([[CashForecast::daily()]]);
 * নিচে চার ঘর — মুক্ত টাকা, সবচেয়ে টান পড়ার দিন, ৭ দিনের দেনা ঢাকা, খালি ঋণসীমা (CC)।
 *
 * ⓘ সব অঙ্ক bcmath-এ, ৪ ঘর; শতাংশ ১ ঘর। কোনো সংখ্যার নতুন সংজ্ঞা নেই — প্রতিটা আগের সেবা থেকে।
 * ⓘ "গড় খরচ" মানে খাতার খরচ নয়, **টাকা বেরোনো** (নিজের মধ্যে স্থানান্তর বাদ, [[AccountsFacts::moneyFlowBetween()]]) — cash
 * runway-র আন্তর্জাতিক মাপ; বাকিতে কেনা খরচ এখনো টাকা টানেনি।
 */
final class FundPosition
{
    /** দিনে গড় বেরোনো মাপার দিন */
    public const BURN_DAYS = 90;

    public function __construct(
        private readonly AccountsFacts $facts,
        private readonly CashForecast $forecast,
    ) {}

    /**
     * @return array{total: string, previous_total: string, change: string, change_pct: ?string,
     *     parts: list<array{key: string, amount: string, share: string, change_pct: ?string}>, bank_accounts: int,
     *     daily_out: string, runway_days: ?int, in30: string, out30: string, net30: string,
     *     free: string, held: string, lowest: array{date: string, closing: string, who: string},
     *     due7: string, cover7: ?string, od_limit: string, od_used: string, od_left: string}
     */
    public function on(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $previousDate = $today->copy()->startOfMonth()->subDay()->toDateString();

        $now = $this->facts->moneyPositions();
        $before = $this->facts->moneyPositions($previousDate);
        $total = $this->sum($now);
        $previousTotal = $this->sum($before);

        $parts = [];

        foreach (['cash', 'bank', 'mfs'] as $key) {
            $parts[] = [
                'key' => $key,
                'amount' => bcadd($now[$key], '0', 4),
                'share' => bccomp($total, '0', 4) > 0 ? bcdiv(bcmul($now[$key], '100', 4), $total, 1) : '0.0',
                'change_pct' => $this->pct($now[$key], $before[$key]),
            ];
        }

        $from = $today->copy()->subDays(self::BURN_DAYS - 1)->toDateString();
        $out = $this->facts->moneyFlowBetween($from, $today->toDateString())['out'];
        $dailyOut = bcdiv($out, (string) self::BURN_DAYS, 4);

        $month = $this->forecast->daily(30, $today);
        $week = $this->forecast->daily(7, $today);

        // ⓘ মুক্ত টাকা = তহবিল − সইয়ের অপেক্ষার পরিশোধ − দেওয়া চেক যা এখনো ব্যাংক থেকে কাটেনি (চেক কাটে ভাঙানোর দিন, [[ChequeService]])
        $issued = bcadd((string) (Cheque::query()->open()->where('direction', Cheque::ISSUED)->sum('amount') ?? '0'), '0', 4);
        $held = bcadd($this->facts->paymentsAwaitingSignature(), $issued, 4);

        [$limit, $used, $left] = $this->overdraft();

        return [
            'total' => $total,
            'previous_total' => $previousTotal,
            'change' => bcsub($total, $previousTotal, 4),
            'change_pct' => $this->pct($total, $previousTotal),
            'parts' => $parts,
            'bank_accounts' => Account::query()->ofMoneyKind(Account::BANK)->where('is_active', true)->count(),
            'daily_out' => $dailyOut,
            'runway_days' => bccomp($dailyOut, '0', 4) > 0 && bccomp($total, '0', 4) > 0 ? (int) bcdiv($total, $dailyOut, 0) : null,
            'in30' => $month['in'],
            'out30' => $month['out'],
            'net30' => bcsub($month['in'], $month['out'], 4),
            'free' => bcsub($total, $held, 4),
            'held' => $held,
            'lowest' => $month['lowest'],
            'due7' => $week['out'],
            'cover7' => bccomp($week['out'], '0', 4) > 0 ? bcdiv($total, $week['out'], 1) : null,
            'od_limit' => $limit,
            'od_used' => $used,
            'od_left' => $left,
        ];
    }

    /** @return array{0: string, 1: string, 2: string} CC সীমা, ব্যবহার, খালি — [[BankFacilityService::standing()]]-এর একই হিসাব */
    private function overdraft(): array
    {
        $facilities = BankFacility::query()->live()->where('kind', BankFacility::CC)->get();
        $limit = '0';
        $used = '0';
        $left = '0';

        foreach (app(BankFacilityService::class)->standing($facilities) as $id => $row) {
            $limit = bcadd($limit, (string) $facilities->firstWhere('id', $id)?->limit_amount, 4);
            $used = bcadd($used, $row['used'], 4);
            $left = bcadd($left, $row['left'], 4);
        }

        return [$limit, $used, $left];
    }

    /** @param  array{cash: string, bank: string, mfs: string}  $m */
    private function sum(array $m): string
    {
        return bcadd(bcadd($m['cash'], $m['bank'], 4), $m['mfs'], 4);
    }

    private function pct(string $now, string $before): ?string
    {
        if (bccomp($before, '0', 4) <= 0) {
            return null;
        }

        return bcdiv(bcmul(bcsub($now, $before, 4), '100', 4), $before, 1);
    }
}
