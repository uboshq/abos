<?php

declare(strict_types=1);

namespace App\Modules\Sales\Widgets;

use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * ⭐ "আজকের বিক্রি" — ফোনের হোম-স্ক্রিন উইজেটের সার্ভার-অংশ (মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ নিজে কিছু গোনে না:
 *  - আজকের আর গতকালের বিক্রি, আজকের আদায় — [[SalesMetrics::invoiceTotal()]] আর [[SalesMetrics::collectionTotal()]], মালিকের
 *    পাতার ([[Figures::value()]] SALES / COLLECTIONS) একই উৎস আর একই দেখার নিয়ম (বিক্রির ড্যাশবোর্ডের দরজা, তারপর নিজের চাবি);
 *    ⓘ Sales নিজের মেট্রিক সরাসরি পড়ে — Executive-এর উপর নির্ভর করে না ([[BoundariesTest]], a6-এর Mac দৌড়, ২০ অক্টোবর ২০২৬);
 *  - বিলের সংখ্যা — খাতায় বসা বিল (`posted()`), ফোনের "আজ কেমন গেল" দরজার হুবহু একই গোনা ([[DashboardTodayController]])।
 *
 * ⭐ দেয়াল মডেলের নিজের: বিল আর আদায়ের কাগজে কোম্পানি, হেডারের শাখা ([[ScopedToUserBranch]]) আর বিক্রয়কর্মীর ডিলার-দেয়াল
 * ([[ScopedToUserDealers]]); রসিদ ভাউচারে [[DealerScope::restrict()]] — ওয়েবের একই জায়গা, তাই উইজেট ওয়েবের চেয়ে বেশি দেখে না।
 *
 * ⓘ আদায় দেখার চাবি (`sales.collection.view`) না থাকলে `collections` null — শূন্য নয়, কারণ শূন্য একটা মিথ্যা সংখ্যা।
 * ⛔ ভিতরে সময়ের ছাপ (`as_of`) নেই — দরজা উত্তরের হ্যাশে ETag বানায়, ছাপ থাকলে কখনো 304 হত না; দরজা নিজেই `asOf` পাঠায়।
 * ⓘ পরিবর্তন গতকালের তুলনায় শতাংশে, এক দশমিকে; গতকাল শূন্য হলে null (শূন্য দিয়ে ভাগ নেই, "অসীম" কোনো খবর নয়)।
 */
final class SalesTodaySummary implements WidgetSummary
{
    public function __construct(private readonly DashboardEngine $dashboards) {}

    public function key(): string
    {
        return 'sales_today';
    }

    public function module(): string
    {
        return 'sales';
    }

    /** ওয়েবের "আজকের বিক্রয়" ঘরের চাবি ([[SalesMetrics::salesToday()]]; মালিকের পাতায় SALES-এর একই চাবি) */
    public function permission(): ?string
    {
        return 'sales.invoice.view';
    }

    public function data(User $user): array
    {
        $from = $to = Carbon::today()->toDateString();
        $yFrom = $yTo = Carbon::yesterday()->toDateString();
        $sales = $this->sees($user, 'sales.invoice.view');

        $total = $sales ? bcadd(SalesMetrics::invoiceTotal($from, $to), '0', 4) : '0.0000';
        $yesterday = $sales ? bcadd(SalesMetrics::invoiceTotal($yFrom, $yTo), '0', 4) : '0.0000';

        return [
            'total' => $total,
            'invoices' => SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to])->count(),
            'collections' => $this->sees($user, 'sales.collection.view') ? bcadd(SalesMetrics::collectionTotal($from, $to), '0', 4) : null,
            'yesterday' => $yesterday,
            'change_percent' => self::changePercent($total, $yesterday),
            'link' => route('sales.invoice.index', ['view' => 'today'], false),
        ];
    }

    /** মালিকের পাতার একই দেখার নিয়ম ([[Figures::visible()]]): বিক্রির ড্যাশবোর্ডের দরজা থাকলে আগে সেটা, তারপর ঘরের নিজের চাবি */
    private function sees(User $user, string $key): bool
    {
        $gate = $this->dashboards->has('sales') ? $this->dashboards->permissionFor('sales') : null;

        return ($gate === null || $user->can($gate)) && $user->can($key);
    }

    /** গতকালের তুলনায় শতাংশ, এক দশমিকে, অর্ধেক উপরে (শূন্য থেকে দূরে); গতকাল শূন্য হলে null */
    public static function changePercent(string $today, string $yesterday): ?string
    {
        if (bccomp($yesterday, '0', 4) === 0) {
            return null;
        }

        $percent = bcdiv(bcmul(bcsub($today, $yesterday, 4), '100', 4), $yesterday, 6);
        $half = bccomp($percent, '0', 6) < 0 ? '-0.05' : '0.05';

        return bcadd($percent, $half, 1);
    }
}
