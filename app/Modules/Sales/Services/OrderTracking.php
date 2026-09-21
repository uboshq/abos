<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * একটা আদেশ এখন কোথায় দাঁড়িয়ে — আদেশ · লোডিং · চালান · বিল।
 *
 * ── ⭐ মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"এখানে Order Tracking-এর ব্যবস্থা করতে হবে… অ্যাপ ১০০% হওয়ার পর সব
 * customer তার নিজের, employee তার অধীনের সকল order ট্রেস করতে পারবে।"*
 *
 * ⓘ আজ পাতাটা কর্মীদের জন্য — সব আদেশ, আর প্রতিটার ধাপ। গ্রাহকের নিজের
 * আদেশ আর কর্মীর অধীনের আদেশ পরের ধাপ, কিন্তু **আজকের পাতাটা সত্যি**:
 * খালি বোতাম বসানো এই রিপোজিটরির নিয়মবিরুদ্ধ ([[SixButtonsThatOnlySaidComingSoon]])।
 *
 * ── ⚠️ কেন সংখ্যাগুলো সাব-কোয়েরিতে ──────────────────────────────────
 * ⛔ সারি ধরে ধরে `deliveredQty()` ডাকলে পঞ্চাশটা আদেশের পাতায় শত শত
 * কোয়েরি হত (প্রতি আদেশের প্রতি লাইনে একটা)। ⓘ তাই আদেশপ্রতি দুইটা
 * যোগফল একবারেই — কতটা চাওয়া হয়েছে, কতটা গেছে — আর বিল হয়েছে কি না।
 */
final class OrderTracking
{
    /** এখনো কিছুই যায়নি। */
    public const PLACED = 'placed';

    /** কিছু গেছে, সবটা নয়। */
    public const PARTIAL = 'partial';

    /** সবটা গেছে, কিন্তু বিল হয়নি। */
    public const DELIVERED = 'delivered';

    /** সবটা গেছে, বিলও হয়েছে। */
    public const BILLED = 'billed';

    public const STAGES = [self::PLACED, self::PARTIAL, self::DELIVERED, self::BILLED];

    /**
     * তালিকার সারি — ছাঁকনি, খোঁজা ও ধাপ ধরে।
     *
     * @return LengthAwarePaginator<int, SalesOrder>
     */
    public function rows(?string $term, string $stage, ?int $customerId = null): LengthAwarePaginator
    {
        $base = SalesOrder::query()
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->when($term, fn ($q, $t) => $q->search($t))
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->select('sal_orders.*')
            ->selectSub($this->orderedQty(), 'ordered_total')
            ->selectSub($this->deliveredQty(), 'delivered_total')
            ->selectSub($this->invoiceCount(), 'invoice_count');

        /*
         * ⚠️ ছাঁকনিটা একটা derived table-এর **উপরে**, `having` দিয়ে নয়।
         *
         * ── ⛔ কেন, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────────
         * `group by` ছাড়া `having` লিখলে MySQL গোটা কোয়েরিটাকে একটাই দল
         * ধরে, আর লাইভের `ONLY_FULL_GROUP_BY`-তে তখন প্রতিটা সাধারণ কলাম
         * নিয়ে অভিযোগ করে — পর্দা ৫০০ দিত **কেবল লাইভে**, এখানে নয়
         * (abos-8b-এর সতর্কবার্তা, একই রাতে আরেকটা কোয়েরিতে ধরা পড়েছে)।
         *
         * ⓘ ভিতরের কোয়েরিটা সারি বানায়, বাইরেরটা কেবল ছাঁকে — সাব-কোয়েরির
         * উপনামগুলো তখন সাধারণ কলাম, আর `where`-এই চলে।
         */
        $orders = SalesOrder::query()
            ->fromSub($base, 'sal_orders')
            ->with(['customer'])
            ->latest('trx_date')
            ->latest('id');

        $filtered = match ($stage) {
            self::PLACED => $orders->where('delivered_total', '<=', 0),
            self::PARTIAL => $orders->whereRaw('delivered_total > 0 AND delivered_total < ordered_total'),
            self::DELIVERED => $orders->whereRaw('delivered_total >= ordered_total AND invoice_count = 0'),
            self::BILLED => $orders->where('invoice_count', '>', 0),
            default => $orders,
        };

        return $filtered->paginate(50)->withQueryString();
    }

    /**
     * প্রতিটা ধাপে কয়টা — ট্যাবের পাশের সংখ্যা।
     *
     * ⓘ একটাই কোয়েরি, তারপর PHP-তে ভাগ: পাঁচটা আলাদা `count()` মানে
     * পাঁচবার একই যোগফল গোনা।
     *
     * @return array<string, int>
     */
    public function counts(?string $term, ?int $customerId = null): array
    {
        $rows = DB::query()
            ->fromSub(
                SalesOrder::query()
                    ->where('status', '<>', DocumentStatus::CANCELLED)
                    ->when($term, fn ($q, $t) => $q->search($t))
                    ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
                    ->select('sal_orders.id')
                    ->selectSub($this->orderedQty(), 'ordered_total')
                    ->selectSub($this->deliveredQty(), 'delivered_total')
                    ->selectSub($this->invoiceCount(), 'invoice_count'),
                'o',
            )
            ->get();

        $counts = ['all' => $rows->count(), self::PLACED => 0, self::PARTIAL => 0,
            self::DELIVERED => 0, self::BILLED => 0];

        foreach ($rows as $row) {
            $counts[$this->stageOf($row)]++;
        }

        return $counts;
    }

    /**
     * একটা সারির ধাপ — উপরের ছাঁকনিগুলোর হুবহু নিয়ম।
     *
     * ⚠️ দুই জায়গায় দুই নিয়ম হলে ট্যাবের সংখ্যা আর তালিকার সারি আলাদা
     * কথা বলত, আর কোনটা সত্যি তা কেউ বুঝত না।
     */
    public function stageOf(object $row): string
    {
        $ordered = (string) ($row->ordered_total ?? '0');
        $delivered = (string) ($row->delivered_total ?? '0');

        return match (true) {
            (int) ($row->invoice_count ?? 0) > 0 => self::BILLED,
            bccomp($delivered, '0', 4) <= 0 => self::PLACED,
            bccomp($delivered, $ordered, 4) >= 0 => self::DELIVERED,
            default => self::PARTIAL,
        };
    }

    /** কতটা চাওয়া হয়েছিল। */
    private function orderedQty(): Builder
    {
        return DB::table('sal_order_lines')
            ->selectRaw('COALESCE(SUM(ordered_qty), 0)')
            ->whereColumn('sal_order_lines.sales_order_id', 'sal_orders.id');
    }

    /**
     * কতটা সত্যিই গেছে — বাতিল চালান বাদ।
     *
     * ⓘ খসড়া চালানও গোনা হয় না: কাগজ লেখা হয়েছে মানে মাল বেরোয়নি।
     */
    private function deliveredQty(): Builder
    {
        return DB::table('sal_challan_lines')
            ->join('sal_challans', 'sal_challans.id', '=', 'sal_challan_lines.delivery_challan_id')
            ->join('sal_order_lines', 'sal_order_lines.id', '=', 'sal_challan_lines.sales_order_line_id')
            ->selectRaw('COALESCE(SUM(sal_challan_lines.delivered_qty), 0)')
            ->whereColumn('sal_order_lines.sales_order_id', 'sal_orders.id')
            ->where('sal_challans.status', DocumentStatus::CONFIRMED);
    }

    /** এই আদেশের মালের বিল হয়েছে কয়টা। */
    private function invoiceCount(): Builder
    {
        return DB::table('sal_invoice_lines')
            ->join('sal_challan_lines', 'sal_challan_lines.id', '=', 'sal_invoice_lines.delivery_challan_line_id')
            ->join('sal_order_lines', 'sal_order_lines.id', '=', 'sal_challan_lines.sales_order_line_id')
            ->join('sal_invoices', 'sal_invoices.id', '=', 'sal_invoice_lines.sales_invoice_id')
            ->selectRaw('COUNT(DISTINCT sal_invoices.id)')
            ->whereColumn('sal_order_lines.sales_order_id', 'sal_orders.id')
            ->where('sal_invoices.status', '<>', DocumentStatus::CANCELLED);
    }
}
