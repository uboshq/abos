<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;

/**
 * CFO ড্যাশবোর্ডের সংখ্যা — এক পাতায় টাকার স্বাস্থ্য।
 * ফিন্যান্সের মানচিত্র §১, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ নতুন কোনো হিসাব নেই — সবই খতিয়ান থেকে, Accounts-এর চেনা সাহায্যকারী
 * দিয়ে ([[AccountsFacts]])। ⚠️ একই সংখ্যা দুই জায়গায় দুইভাবে গোনা হলে
 * একদিন ড্যাশবোর্ড আর স্থিতিপত্র দুই রকম বলত।
 *
 * ── তারল্য ─────────────────────────────────────────────────────────────
 * চলতি অনুপাত = চলতি সম্পদ (১১০০) ÷ চলতি দায় (২১০০)। নগদ অনুপাত = নগদ +
 * ব্যাংক + MFS ÷ চলতি দায়। ⓘ স্কোর সোজা নিয়মে: চলতি অনুপাত ২-এর বেশি
 * ভালো, ১–২ সাবধান, ১-এর কম বিপদ — ব্যাংকের ঋণ-বিবেচনার চেনা সীমা।
 * চলতি দায় শূন্য হলে অনুপাত অর্থহীন, তাই null ("দায় নেই")।
 */
final class CfoFigures
{
    public function __construct(private readonly AccountsFacts $facts) {}

    /**
     * @return array{cash: string, bank: string, mfs: string, money: string, receivable: string,
     *     payable: string, current_assets: string, current_liabilities: string,
     *     current_ratio: ?string, cash_ratio: ?string, liquidity: string, loans: string}
     */
    public function figures(): array
    {
        $money = $this->facts->moneyPositions();
        $allMoney = bcadd(bcadd($money['cash'], $money['bank'], 4), $money['mfs'], 4);

        $assets = StandardChart::find('1100')?->balanceOn() ?? '0';
        $liabilities = StandardChart::find('2100')?->balanceOn() ?? '0';

        $hasLiabilities = bccomp($liabilities, '0', 4) > 0;
        $current = $hasLiabilities ? bcdiv($assets, $liabilities, 2) : null;

        return [
            'cash' => $money['cash'],
            'bank' => $money['bank'],
            'mfs' => $money['mfs'],
            'money' => $allMoney,
            'receivable' => $this->facts->receivable(),
            'payable' => $this->facts->payable(),
            'current_assets' => $assets,
            'current_liabilities' => $liabilities,
            'current_ratio' => $current,
            'cash_ratio' => $hasLiabilities ? bcdiv($allMoney, $liabilities, 2) : null,
            'liquidity' => match (true) {
                $current === null => 'good',
                bccomp($current, '2', 2) >= 0 => 'good',
                bccomp($current, '1', 2) >= 0 => 'warn',
                default => 'bad',
            },
            'loans' => $this->facts->outstandingLoan(),
        ];
    }
}
