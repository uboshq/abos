<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountsFacts;
use Illuminate\Support\Carbon;

/**
 * বিনিয়োগের রিটার্ন — কার টাকা কত আয় করল। মানচিত্র §১২, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── কী মাপা হয় ────────────────────────────────────────────────────────
 * একটা সময়ের লাভ (আয় − খরচ, সরাসরি খতিয়ান থেকে), তারপর প্রতিজনের অংশ
 * ধরে ভাগ ([[ProfitSplit]] — এক পয়সাও হারায় না)। পাশে তাঁর নিট মূলধন
 * (দেওয়া − তোলা), আর রিটার্ন % = ভাগের টাকা ÷ নিট মূলধন।
 *
 * ⚠️ এই পর্দা কিছুই পোস্ট করে না — কেবল পড়ে। ⓘ টাকা সরানো আলাদা কাজ
 * (লাভ ভাগাভাগি), আর সেটা মালিকের সিদ্ধান্তের অপেক্ষায়।
 *
 * ⛔ লোকসানের সময় "রিটার্ন" বলে কিছু দেখানো হয় না: ভাগ শূন্য, আর পর্দা
 * সোজা বলে লোকসান কত। ⓘ ঋণাত্মক শতাংশ দেখালে কেউ ভাবতেন তাঁর মূলধন
 * কমে গেছে, অথচ লোকসান মূলধনে বসে কেবল বছর শেষে।
 */
final class InvestmentReturns
{
    public function __construct(
        private readonly AccountsFacts $facts,
        private readonly CapitalService $capital,
        private readonly ProfitSplit $split,
    ) {}

    /**
     * @return array{from: string, to: string, income: string, expense: string, profit: string,
     *     rows: list<array{person_id: int, name: string, type: string, contributed: string,
     *         withdrawn: string, net: string, share: ?string, earned: string, return_pct: ?string}>,
     *     unallocated: string, shares_total: string}
     */
    public function forPeriod(Carbon $from, Carbon $to): array
    {
        $income = $this->facts->netOfType(Account::INCOME, $from, $to);
        $expense = $this->facts->netOfType(Account::EXPENSE, $from, $to);
        $profit = bcsub($income, $expense, 4);

        $positions = $this->capital->positions();

        $shares = [];

        foreach ($positions as $position) {
            if ($position['share'] !== null) {
                $shares[$position['person_id']] = (string) $position['share'];
            }
        }

        $split = $this->split->byShares($profit, $shares);

        $rows = [];

        foreach ($positions as $position) {
            $earned = $split['amounts'][$position['person_id']] ?? '0.0000';

            $rows[] = [
                'person_id' => $position['person_id'],
                'name' => $position['name'],
                'type' => $position['type'],
                'contributed' => $position['contributed'],
                'withdrawn' => $position['withdrawn'],
                'net' => $position['net'],
                'share' => $position['share'],
                'earned' => $earned,

                /*
                 * ⚠️ নিট মূলধন শূন্য বা ঋণাত্মক হলে শতাংশ বলা যায় না —
                 * ⓘ যিনি সব তুলে নিয়েছেন তাঁর "রিটার্ন" অসীম হত, আর সেটা
                 * সংখ্যা নয়, একটা ভুল।
                 */
                'return_pct' => bccomp($position['net'], '0', 4) > 0
                    ? bcmul(bcdiv($earned, $position['net'], 6), '100', 2)
                    : null,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'income' => $income,
            'expense' => $expense,
            'profit' => $profit,
            'rows' => $rows,
            'unallocated' => $split['unallocated'],
            'shares_total' => array_reduce($shares, fn (string $sum, string $s) => bcadd($sum, $s, 4), '0'),
        ];
    }
}
