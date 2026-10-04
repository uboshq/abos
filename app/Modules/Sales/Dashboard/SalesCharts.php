<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use Illuminate\Support\Carbon;

/**
 * বিক্রয় ড্যাশবোর্ডের নতুন চার্ট — মালিকের ড্যাশবোর্ড নকশা (৩ অক্টোবর ২০২৬), নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
 *
 * ⓘ আলাদা ফাইল, যাতে [[SalesDashboard]] আর [[SalesOverview]]-এর কাজ ছোঁয়া না লাগে (SR আর এলাকা ধরে বিক্রি
 * abos-69/bb-এর SalesAnalytics-এ, ৪ অক্টোবর ২০২৬ — দুইবার নয়)।
 */
final class SalesCharts
{
    /** @return list<Breakdown> */
    public static function all(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('sales.invoice.view')) {
            return [];
        }

        return [self::funnel()];
    }

    /**
     * ⭐ বিক্রয়ের ফানেল — এ মাসে কয়টা উদ্ধৃতি, অর্ডার, চালান আর বিল (Quotation → Order → Delivery → Invoice)।
     * ⓘ খসড়া আর বাতিল বাদ; উদ্ধৃতি পাঠানো/গৃহীত/অনুমোদিত যেকোনো ধাপে গোনে, বাকি তিনটা পাকা (নিশ্চিত বা বন্ধ) হলে।
     * ⓘ সংখ্যা কাগজের, টাকার নয় — কোন ধাপে এসে বিক্রি আটকে যাচ্ছে সেটাই প্রশ্ন। দেখার শাখা মানে (মডেলের পাহারা)।
     */
    private static function funnel(): Breakdown
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();
        $month = fn ($query) => $query->whereBetween('trx_date', [$from, $to]);

        $quotes = $month(SalesQuotation::query())
            ->whereNotIn('status', [SalesQuotation::DRAFT, SalesQuotation::CANCELLED])->count();
        $orders = $month(SalesOrder::query())->whereIn('status', DocumentStatus::POSTED)->count();
        $challans = $month(DeliveryChallan::query())->whereIn('status', DocumentStatus::POSTED)->count();
        $invoices = $month(SalesInvoice::query())->whereIn('status', DocumentStatus::POSTED)->count();

        return new Breakdown(
            label: __('sales::dashboard.funnel'),
            parts: [
                ['label' => __('sales::dashboard.funnel_quotes'), 'value' => (string) $quotes],
                ['label' => __('sales::dashboard.funnel_orders'), 'value' => (string) $orders],
                ['label' => __('sales::dashboard.funnel_challans'), 'value' => (string) $challans],
                ['label' => __('sales::dashboard.funnel_invoices'), 'value' => (string) $invoices],
            ],
            hint: __('sales::dashboard.funnel_hint'),
        );
    }
}
