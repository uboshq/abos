<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Services\DataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Support\Carbon;

/**
 * ⭐ চলতি মূলধন (Working Capital) — যেকোনো তারিখে (মালিক, ৯ নভেম্বর ২০২৬: অর্থের ড্যাশবোর্ডের সবার নিচে, মালিকের নিজের ছবির হুবহু;
 * "চলতি মূলধন-এর পাশে একটা তারিখের বক্স … কোন তারিখে কত ছিল"; "মাসে বিক্রয় … last 6 মাস বা ১ বছরের average … সাথে %")।
 *
 * মালিকের ছবির সূত্র: পাওনা (১১১০) + মজুদ (১১২০) + হাতে টাকা (নগদ ১১০১ · ব্যাংক ১১০২ · MFS ১১০৫) − প্রিন্সিপাল/সরবরাহকারীর
 * দেনা (২১১০) − অন্যান্য চলতি দায় (২১০০ বাদে ২১১০)। চলতি অনুপাত = (প্রথম তিন) ÷ (শেষ দুই)।
 * গড় মাসিক বিক্রি = শেষ ৬ বা ১২ মাসের নিট বিক্রি (৪১০০ বিক্রি · ৪১১০ ফেরত · ৪১২০ আদায়ের ছাড় — তিনটাই আয়ের প্রকৃতিতে, তাই যোগেই
 * নিট) ÷ মাসের সংখ্যা; মালিকের উদাহরণ: "মাসিক বিক্রয় ১০০, invest ৩০ — working capital ৩০%"।
 *
 * ⓘ জের দেখার নাগালে ([[DataScope]]): এক শাখা বাছা থাকলে সেই শাখা, সীমাহীন মানুষের "সব শাখা"-তে গোটা কোম্পানি, সীমিত মানুষের
 * "সব শাখা"-তে তাঁর শাখাগুলোর যোগ। ⛔ কেবল দেখানোর অঙ্ক — কোনো যাচাই এটা পড়ে না।
 */
final class WorkingCapital
{
    public const SPANS = [6, 12];

    public function __construct(private readonly DataScope $scope) {}

    /**
     * @return array{date: string, receivable: string, stock: string, money: string, payable: string, other: string,
     *     capital: string, ratio: ?string, previous: ?array{date: string, capital: string}, change_pct: ?string,
     *     span: int, avg_sales: string, sales_pct: ?string}
     */
    public function on(Carbon $date, int $span = 6): array
    {
        $span = in_array($span, self::SPANS, true) ? $span : self::SPANS[0];
        $now = $this->parts($date);
        $previousDate = $date->copy()->startOfMonth()->subDay();
        $previous = $this->parts($previousDate);

        $from = $date->copy()->startOfMonth()->subMonthsNoOverflow($span - 1)->startOfMonth();
        $sales = bcsub($this->sum(StandardChart::NET_SALES, $date), $this->sum(StandardChart::NET_SALES, $from->copy()->subDay()), 4);
        $avg = bcdiv($sales, (string) $span, 4);

        $change = bccomp($previous['capital'], '0', 4) === 0 ? null
            : bcmul(bcdiv(bcsub($now['capital'], $previous['capital'], 4), $this->abs($previous['capital']), 6), '100', 1);

        return $now + [
            'date' => $date->toDateString(),
            'previous' => ['date' => $previousDate->toDateString(), 'capital' => $previous['capital']],
            'change_pct' => $change,
            'span' => $span,
            'avg_sales' => $avg,
            'sales_pct' => bccomp($avg, '0', 4) > 0 ? bcmul(bcdiv($now['capital'], $avg, 6), '100', 0) : null,
        ];
    }

    /** @return array{receivable: string, stock: string, money: string, payable: string, other: string, capital: string, ratio: ?string} */
    private function parts(Carbon $date): array
    {
        $receivable = $this->sum([StandardChart::RECEIVABLE], $date);
        $stock = $this->sum([StandardChart::INVENTORY], $date);
        $money = $this->sum(StandardChart::MONEY_PARENTS, $date);
        $payable = $this->sum([StandardChart::PAYABLE_GROUP], $date);
        $other = bcsub($this->sum(['2100'], $date), $payable, 4);

        $has = bcadd(bcadd($receivable, $stock, 4), $money, 4);
        $owes = bcadd($payable, $other, 4);

        return [
            'receivable' => $receivable,
            'stock' => $stock,
            'money' => $money,
            'payable' => $payable,
            'other' => $other,
            'capital' => bcsub($has, $owes, 4),
            'ratio' => bccomp($owes, '0', 4) > 0 ? bcdiv($has, $owes, 2) : null,
        ];
    }

    /** @param  list<string>  $codes  খাতের কোড — প্রতিটার জের তারিখ পর্যন্ত, দেখার নাগালে, নিজের প্রকৃতিতে */
    private function sum(array $codes, Carbon $upto): string
    {
        $user = auth()->user();
        $ids = $this->scope->viewBranchIds($user);
        $branches = $ids === null ? [null] : $ids;
        $total = '0';

        foreach ($codes as $code) {
            $account = StandardChart::find($code);

            if ($account === null) {
                continue;
            }

            foreach ($branches as $branch) {
                $total = bcadd($total, $account->balanceOn($upto->toDateString(), $branch), 4);
            }
        }

        return $total;
    }

    private function abs(string $n): string
    {
        return bccomp($n, '0', 4) < 0 ? bcmul($n, '-1', 4) : $n;
    }
}
