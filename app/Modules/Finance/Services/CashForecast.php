<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * নগদের পূর্বাভাস — সামনের ৩০/৬০/৯০ দিনে হাতে কত থাকবে।
 * ফিন্যান্সের মানচিত্র §৮, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ধরা হয় ─────────────────────────────────────────────────────────
 * শুরু: আজকের নগদ + ব্যাংক + MFS ([[AccountsFacts::moneyPositions()]])।
 * আসবে: গ্রাহকের বাকি বিল (মেয়াদ ধরে), আর দেওয়া ঋণের কিস্তি।
 * যাবে: সরবরাহকারীর বাকি বিল (মেয়াদ ধরে), আর নেওয়া ঋণের কিস্তি।
 *
 * ⚠️ মেয়াদ পেরিয়ে যাওয়া বা মেয়াদ-না-লেখা বিল প্রথম ঝুড়িতে ("এখনই
 * পাওনা/দেয়")। ⓘ সেটা আশাবাদী — পুরনো বাকি সব আসবে না — কিন্তু লুকিয়ে
 * রাখলে পূর্বাভাসটা আরও মিথ্যা হত; আলাদা সারিতে থাকায় মানুষ নিজেই ছাড়
 * দিয়ে পড়তে পারেন।
 *
 * ⓘ বেতন, ভাড়ার মতো নিয়মিত খরচ এখানে নেই — খাতায় সেগুলোর কোনো "মেয়াদ"
 * লেখা থাকে না। পরে বাজেট থেকে আনা যায়; আজ যা নিশ্চিত জানা, কেবল তাই।
 */
final class CashForecast
{
    /** ঝুড়ি: এখনই (মেয়াদোত্তীর্ণ সহ), তারপর দিনের সীমা। */
    public const BUCKETS = ['now' => 0, 'd30' => 30, 'd60' => 60, 'd90' => 90];

    public function __construct(private readonly AccountsFacts $facts) {}

    /**
     * @return array{opening: string, rows: list<array{bucket: string, until: string, receivables: string,
     *     loans_in: string, payables: string, loans_out: string, net: string, closing: string}>}
     */
    public function build(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $money = $this->facts->moneyPositions();
        $opening = bcadd(bcadd($money['cash'], $money['bank'], 4), $money['mfs'], 4);

        $sums = array_fill_keys(array_keys(self::BUCKETS), [
            'receivables' => '0', 'loans_in' => '0', 'payables' => '0', 'loans_out' => '0',
        ]);

        foreach (SalesInvoice::query()->posted()->withCollected()->get() as $invoice) {
            $this->add($sums, $this->bucketOf($invoice->due_on, $today), 'receivables', $invoice->dueAmount());
        }

        foreach (PurchaseBill::query()->posted()->withPaid()->get() as $bill) {
            $this->add($sums, $this->bucketOf($bill->due_on, $today), 'payables', $bill->dueAmount());
        }

        $instalments = LoanInstalment::query()
            ->with('loan')
            ->where('status', LoanInstalment::DUE)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED))
            ->get();

        foreach ($instalments as $row) {
            $left = bcsub(bcadd((string) $row->principal, (string) $row->interest, 4), (string) ($row->paid_amount ?? 0), 4);
            $side = $row->loan->direction === Loan::GIVEN ? 'loans_in' : 'loans_out';

            $this->add($sums, $this->bucketOf($row->due_date, $today), $side, $left);
        }

        $rows = [];
        $running = $opening;

        foreach (self::BUCKETS as $bucket => $days) {
            $s = $sums[$bucket];
            $net = bcsub(bcadd($s['receivables'], $s['loans_in'], 4), bcadd($s['payables'], $s['loans_out'], 4), 4);
            $running = bcadd($running, $net, 4);

            $rows[] = [
                'bucket' => $bucket,
                'until' => $today->copy()->addDays($days)->toDateString(),
                ...$s,
                'net' => $net,
                'closing' => $running,
            ];
        }

        return ['opening' => $opening, 'rows' => $rows];
    }

    /**
     * কোন ঝুড়িতে — মেয়াদোত্তীর্ণ বা মেয়াদ-না-লেখা হলে "এখনই"; ৯০ দিনের
     * পরের হলে কোনোটায় নয় (null)।
     */
    private function bucketOf($due, Carbon $today): ?string
    {
        if ($due === null) {
            return 'now';
        }

        $days = $today->diffInDays(Carbon::parse($due)->startOfDay(), false);

        foreach (self::BUCKETS as $bucket => $limit) {
            if ($days <= $limit) {
                return $bucket;
            }
        }

        return null;
    }

    /** @param  array<string, array<string, string>>  $sums */
    private function add(array &$sums, ?string $bucket, string $side, string $amount): void
    {
        if ($bucket === null || bccomp($amount, '0', 4) <= 0) {
            return;
        }

        $sums[$bucket][$side] = bcadd($sums[$bucket][$side], $amount, 4);
    }
}
