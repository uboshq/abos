<?php

declare(strict_types=1);

namespace App\Modules\Sales\Widgets;

use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Executive\Services\Figures;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * ⭐ "আজকের বিক্রি" — ফোনের হোম-স্ক্রিন উইজেটের সার্ভার-অংশ (মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ নিজে কিছু গোনে না:
 *  - আজকের আর গতকালের বিক্রি, আজকের আদায় — মালিকের পাতার একই সংখ্যা ([[Figures::value()]] SALES / COLLECTIONS), যা নিজেই
 *    [[SalesMetrics::invoiceTotal()]] আর [[SalesMetrics::collectionTotal()]] ডাকে — ওয়েবের হোমের "আজকের বিক্রয়"-এর একই সংজ্ঞা;
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
    public function __construct(private readonly Figures $figures) {}

    public function key(): string
    {
        return 'sales_today';
    }

    public function module(): string
    {
        return 'sales';
    }

    /** ওয়েবের "আজকের বিক্রয়" ঘরের চাবি ([[SalesMetrics::salesToday()]], [[Figures::definition()]] SALES) */
    public function permission(): ?string
    {
        return 'sales.invoice.view';
    }

    public function data(User $user): array
    {
        [$from, $to] = Figures::window(Figures::TODAY);
        [$yFrom, $yTo] = Figures::window(Figures::TODAY, Carbon::yesterday());

        $total = $this->figures->value($user, Figures::SALES, $from, $to) ?? '0.0000';
        $yesterday = $this->figures->value($user, Figures::SALES, $yFrom, $yTo) ?? '0.0000';

        return [
            'total' => $total,
            'invoices' => SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to])->count(),
            'collections' => $this->figures->value($user, Figures::COLLECTIONS, $from, $to),
            'yesterday' => $yesterday,
            'change_percent' => self::changePercent($total, $yesterday),
            'link' => route('sales.invoice.index', ['view' => 'today'], false),
        ];
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
