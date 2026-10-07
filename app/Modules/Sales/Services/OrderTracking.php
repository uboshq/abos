<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
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

    /*
     * ⭐ অর্ডার তালিকার ট্যাব — নকশার পর্যালোচনা, ধাপ ৭-এর ২ (মালিক, ১ অক্টোবর ২০২৬: *"ok kore daw"*)।
     *
     * ⓘ আগে মেনুতে পাঁচটা আলাদা দরজা ছিল — অর্ডার তালিকা, অপেক্ষমাণ, আংশিক, ব্যাক অর্ডার, ইতিহাস —
     * অথচ জিনিসটা একটাই তালিকা, কেবল ছাঁকনি আলাদা। এখন মেনুতে একটা সারি, আর ছাঁকনিগুলো পাতার ওপরের
     * ট্যাব (`?tab=`), সার্ভারে ছাঁকা, বুকমার্ক করা যায়, পাশে গোনা।
     *
     * ⚠️ এগুলো ধাপ নয়, দৃশ্য: "ব্যাক অর্ডার" অপেক্ষমাণ বা আংশিক — দুইটার যেকোনোটার সাথেই থাকে।
     * বাকি চারটা পরস্পরকে বাদ দেয় (বাতিল ছাড়া প্রতিটা আদেশ ঠিক একটায়), ইতিহাসে বাতিলও।
     *
     * ⓘ প্রতিটা ট্যাবের চাবি পুরনো মেনু-সারির চাবিই — চারটাই `sales.order.view` চাইত। যে ট্যাব খোলার
     * চাবি নেই, সে ট্যাব আঁকা হয় না ([[SalesOrderController::listTabs()]])।
     */
    public const LIST_ALL = 'all';

    public const LIST_PENDING = 'pending';

    public const LIST_PARTIAL = 'partial';

    public const LIST_BACK = 'back';

    public const LIST_HISTORY = 'history';

    /**
     * ⭐ পুরনো খসড়া — খসড়া অবস্থায় তিন দিন বা তার বেশি (পরিকল্পনার §৪.৩: *"৩ দিন পড়ে থাকলে তালিকায় লাল"*;
     * মালিক, ৪ অক্টোবর ২০২৬)। ⓘ নিয়ম আর কাটা-সময় [[OrderProgress::isStale()]]-এর — দুই জায়গায় এক।
     */
    public const LIST_STALE = 'stale';

    /*
     * ⭐ নতুন ধারার ধাপগুলোর ট্যাব — নকশা "DO বিক্রয় আদেশে মেশানো" §৪, ধাপ ৮ (৫ অক্টোবর ২০২৬)। ⓘ অবস্থা ধরে, একটাই নিয়ম
     * [[applyListTab()]] আর [[listTabsOf()]]-এ; "ডিপো যাচাইয়ে" চিহ্ন ধরে (`depot_check_at`), অবস্থা নয়।
     */
    public const LIST_DRAFT = 'draft';

    public const LIST_AWAITING = 'awaiting';

    public const LIST_CREDIT_HELD = 'credit_held';

    public const LIST_DEPOT = 'depot';

    /**
     * ট্যাবের ক্রম — চাবি → নাম আর খোলার চাবি।
     *
     * @var array<string, array{label: string, hint: string, permission: string}>
     */
    public const LIST_TABS = [
        self::LIST_ALL => ['label' => 'sales::order_tabs.all', 'hint' => 'sales::order_tabs.hint_all', 'permission' => 'sales.order.view'],
        self::LIST_DRAFT => ['label' => 'sales::order_status.tab_draft', 'hint' => 'sales::order_status.hint_tab_draft', 'permission' => 'sales.order.view'],
        self::LIST_AWAITING => ['label' => 'sales::order_status.tab_awaiting', 'hint' => 'sales::order_status.hint_tab_awaiting', 'permission' => 'sales.order.view'],
        self::LIST_CREDIT_HELD => ['label' => 'sales::order_status.tab_credit_held', 'hint' => 'sales::order_status.hint_tab_credit_held', 'permission' => 'sales.order.view'],
        self::LIST_PENDING => ['label' => 'sales::order_tabs.pending', 'hint' => 'sales::order_tabs.hint_pending', 'permission' => 'sales.order.view'],
        self::LIST_DEPOT => ['label' => 'sales::order_status.tab_depot', 'hint' => 'sales::order_status.hint_tab_depot', 'permission' => 'sales.order.view'],
        self::LIST_PARTIAL => ['label' => 'sales::order_tabs.partial', 'hint' => 'sales::order_tabs.hint_partial', 'permission' => 'sales.order.view'],
        self::LIST_BACK => ['label' => 'sales::order_tabs.back', 'hint' => 'sales::order_tabs.hint_back', 'permission' => 'sales.order.view'],
        self::LIST_STALE => ['label' => 'sales::order_status.tab_stale', 'hint' => 'sales::order_status.hint_tab_stale', 'permission' => 'sales.order.view'],
        self::LIST_HISTORY => ['label' => 'sales::order_tabs.history', 'hint' => 'sales::order_tabs.hint_history', 'permission' => 'sales.order.view'],
    ];

    /**
     * তালিকার সারি — ছাঁকনি, খোঁজা ও ধাপ ধরে।
     *
     * @return LengthAwarePaginator<int, SalesOrder>
     */
    public function rows(?string $term, string $stage, ?int $customerId = null): LengthAwarePaginator
    {
        return $this->query($term, $stage, $customerId)->paginate(50)->withQueryString();
    }

    /**
     * ছাঁকা আদেশগুলোর কোয়েরি — পাতা ভাগের আগে; তালিকা আর তার যোগফলের পট্টি ([[x-ui.list-totals]]) একই সারি গোনে।
     *
     * @return EloquentBuilder<SalesOrder>
     */
    public function query(?string $term, string $stage, ?int $customerId = null): EloquentBuilder
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

        return match ($stage) {
            self::PLACED => $orders->where('delivered_total', '<=', 0),
            self::PARTIAL => $orders->whereRaw('delivered_total > 0 AND delivered_total < ordered_total'),
            self::DELIVERED => $orders->whereRaw('delivered_total >= ordered_total AND invoice_count = 0'),
            self::BILLED => $orders->where('invoice_count', '>', 0),
            default => $orders,
        };
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
            // ⭐ "আর দেওয়া হবে না" অংশ বাদ — বাকিটা বন্ধ করা আদেশ "পুরো গেছে"-র ইতিহাসে যায় (নকশার ধাপ ৭)
            ->selectRaw('COALESCE(SUM(ordered_qty - rejected_qty), 0)')
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

    /**
     * অর্ডার তালিকার একটা ট্যাবের ছাঁকনি — তালিকার নিজের কোয়েরির ওপরে (খোঁজা, তারিখ, সাজানো সব থাকে)।
     *
     * ⓘ সংখ্যাগুলো সাব-কোয়েরিতে, সারি ধরে নয় — উপরের একই কারণে। ⚠️ নিয়ম [[listTabsOf()]]-এর হুবহু এক,
     * নাহলে ট্যাবের গোনা আর তালিকার সারি আলাদা কথা বলত।
     *
     * @param  EloquentBuilder<SalesOrder>  $orders
     * @return EloquentBuilder<SalesOrder>
     */
    public function applyListTab(EloquentBuilder $orders, string $tab, bool $withCancelled = false): EloquentBuilder
    {
        $live = fn (EloquentBuilder $q): EloquentBuilder => $q->where('sal_orders.status', '<>', DocumentStatus::CANCELLED);
        // ⓘ বন্ধ বা ফেরত আদেশ আর অপেক্ষমাণ বা আংশিক নয় — তার জায়গা ইতিহাসে (৪ অক্টোবর ২০২৬, [[SalesOrderStatus::FINISHED]])
        $open = fn (EloquentBuilder $q): EloquentBuilder => $q->whereNotIn('sal_orders.status', SalesOrderStatus::FINISHED);
        [$lessThanOrdered, $lessBindings] = $this->deliveredAgainstOrdered('<');
        [$allGone, $goneBindings] = $this->deliveredAgainstOrdered('>=');

        return match ($tab) {
            self::LIST_PENDING => $open($orders)->where($this->deliveredQty(), '<=', 0),
            self::LIST_PARTIAL => $open($orders)->where($this->deliveredQty(), '>', 0)
                ->whereRaw($lessThanOrdered, $lessBindings),
            // ⓘ কেবল নিশ্চিত আদেশ — খসড়া আদেশের মাল এখনো কেউ চায়নি, তাই সে "পিছিয়ে" নেই
            self::LIST_BACK => $live($orders)->where('sal_orders.status', DocumentStatus::CONFIRMED)
                ->whereExists($this->shortLines()->selectRaw('1')),
            // ⓘ শেষ হয়ে যাওয়া আদেশ: সব মাল গেছে, নয়তো বাতিল — প্রতিটার পাতায় পুরো পথ ([[SalesOrderController::show()]])
            self::LIST_HISTORY => $orders->where(fn (EloquentBuilder $q) => $q
                ->whereIn('sal_orders.status', SalesOrderStatus::FINISHED)
                ->orWhere(fn (EloquentBuilder $done) => $done
                    ->where($this->deliveredQty(), '>', 0)
                    ->whereRaw($allGone, $goneBindings))),
            // ⭐ পুরনো খসড়া — খসড়া অবস্থায় তিন দিন বা তার বেশি ([[OrderProgress::isStale()]]-এর হুবহু নিয়ম)
            self::LIST_STALE => $orders->where('sal_orders.status', SalesOrderStatus::DRAFT)
                ->where('sal_orders.created_at', '<=', OrderProgress::staleCutoff()),
            // ⭐ নতুন ধারার ধাপ — অবস্থা ধরে; ডিপো যাচাই চিহ্ন ধরে ([[listTabsOf()]]-এর হুবহু নিয়ম; নকশার ধাপ ৮)
            self::LIST_DRAFT => $orders->where('sal_orders.status', SalesOrderStatus::DRAFT),
            self::LIST_AWAITING => $orders->where('sal_orders.status', SalesOrderStatus::AWAITING_APPROVAL),
            self::LIST_CREDIT_HELD => $orders->where('sal_orders.status', SalesOrderStatus::CREDIT_HELD),
            self::LIST_DEPOT => $orders->where('sal_orders.status', SalesOrderStatus::CONFIRMED)
                ->whereNotNull('sal_orders.depot_check_at'),
            // ⓘ "সব" — আগের তালিকা হুবহু: বাতিল লুকানো, চাইলে দেখা যায় (নিয়ম ৫)
            default => $withCancelled ? $orders : $live($orders),
        };
    }

    /**
     * প্রতিটা ট্যাবের গোনা — একটাই কোয়েরি, তারপর PHP-তে ভাগ ([[counts()]]-এর মতোই)।
     *
     * @param  EloquentBuilder<SalesOrder>  $orders  খোঁজা আর তারিখ বসানো, ট্যাব আর বাতিলের ছাঁকনি ছাড়া
     * @return array<string, int>
     */
    public function listCounts(EloquentBuilder $orders): array
    {
        $rows = DB::query()
            ->fromSub(
                $orders->select('sal_orders.id', 'sal_orders.status', 'sal_orders.created_at', 'sal_orders.depot_check_at')
                    ->selectSub($this->orderedQty(), 'ordered_total')
                    ->selectSub($this->deliveredQty(), 'delivered_total')
                    ->selectSub($this->shortLines()->selectRaw('COUNT(*)'), 'short_lines'),
                'o',
            )
            ->get();

        $counts = array_fill_keys(array_keys(self::LIST_TABS), 0);

        foreach ($rows as $row) {
            foreach ($this->listTabsOf($row) as $tab) {
                $counts[$tab]++;
            }
        }

        return $counts;
    }

    /**
     * একটা আদেশ কোন কোন ট্যাবে — [[applyListTab()]]-এর হুবহু নিয়ম।
     *
     * @return list<string>
     */
    public function listTabsOf(object $row): array
    {
        if (($row->status ?? null) === DocumentStatus::CANCELLED) {
            return [self::LIST_HISTORY];
        }

        // ⓘ বন্ধ বা ফেরত — "সব"-এ থাকে (বাতিল নয়), আর ইতিহাসে
        if (in_array($row->status ?? null, SalesOrderStatus::FINISHED, true)) {
            return [self::LIST_ALL, self::LIST_HISTORY];
        }

        $ordered = (string) ($row->ordered_total ?? '0');
        $delivered = (string) ($row->delivered_total ?? '0');

        $tabs = [self::LIST_ALL, match (true) {
            bccomp($delivered, '0', 4) <= 0 => self::LIST_PENDING,
            bccomp($delivered, $ordered, 4) < 0 => self::LIST_PARTIAL,
            default => self::LIST_HISTORY,
        }];

        if (($row->status ?? null) === DocumentStatus::CONFIRMED && (int) ($row->short_lines ?? 0) > 0) {
            $tabs[] = self::LIST_BACK;
        }

        if (($row->status ?? null) === SalesOrderStatus::DRAFT
            && ($row->created_at ?? null) !== null
            && Carbon::parse((string) $row->created_at)->lte(OrderProgress::staleCutoff())) {
            $tabs[] = self::LIST_STALE;
        }

        // ⭐ নতুন ধারার ধাপ — [[applyListTab()]]-এর হুবহু নিয়ম (নকশার ধাপ ৮)
        $status = $row->status ?? null;

        return [...$tabs, ...array_keys(array_filter([
            self::LIST_DRAFT => $status === SalesOrderStatus::DRAFT,
            self::LIST_AWAITING => $status === SalesOrderStatus::AWAITING_APPROVAL,
            self::LIST_CREDIT_HELD => $status === SalesOrderStatus::CREDIT_HELD,
            self::LIST_DEPOT => $status === SalesOrderStatus::CONFIRMED && ($row->depot_check_at ?? null) !== null,
        ]))];
    }

    /**
     * যা গেছে বনাম যা চাওয়া হয়েছিল — দুই সাব-কোয়েরির তুলনা, বাঁধনসহ।
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function deliveredAgainstOrdered(string $operator): array
    {
        $delivered = $this->deliveredQty();
        $ordered = $this->orderedQty();

        return [
            '('.$delivered->toSql().') '.$operator.' ('.$ordered->toSql().')',
            [...$delivered->getBindings(), ...$ordered->getBindings()],
        ];
    }

    /**
     * ব্যাক অর্ডারের সারি — যে লাইনের বাকি মাল গুদামের তাকে নেই।
     *
     * ⓘ মানে: (চাওয়া − যা গেছে) > ০, আর সেটা আদেশের গুদামের তাকের মালের (`floor`) চেয়ে বেশি। ⚠️ তাক, বিক্রয়যোগ্য
     * নয় — আদেশ নিশ্চিত হলে মালটা এই আদেশের নামেই ধরা থাকে, তাই "বিক্রয়যোগ্য" দিয়ে মাপলে প্রতিটা নিশ্চিত
     * আদেশই নিজের ধরা মালের জন্য ব্যাক অর্ডার দেখাত। গুদাম না থাকলে সব গুদামের তাক।
     */
    private function shortLines(): Builder
    {
        $sent = DB::table('sal_challan_lines')
            ->join('sal_challans', 'sal_challans.id', '=', 'sal_challan_lines.delivery_challan_id')
            ->selectRaw('COALESCE(SUM(sal_challan_lines.delivered_qty), 0)')
            ->whereColumn('sal_challan_lines.sales_order_line_id', 'sal_order_lines.id')
            ->where('sal_challans.status', DocumentStatus::CONFIRMED);

        $onShelf = DB::table('inv_stock_movements')
            ->selectRaw('COALESCE(SUM(inv_stock_movements.floor_change), 0)')
            ->whereColumn('inv_stock_movements.company_id', 'sal_orders.company_id')
            ->whereColumn('inv_stock_movements.product_id', 'sal_order_lines.product_id')
            ->where(fn (Builder $q) => $q->whereNull('sal_orders.warehouse_id')
                ->orWhereColumn('inv_stock_movements.warehouse_id', 'sal_orders.warehouse_id'));

        $left = 'sal_order_lines.ordered_qty - sal_order_lines.rejected_qty - ('.$sent->toSql().')';

        return DB::table('sal_order_lines')
            ->whereColumn('sal_order_lines.sales_order_id', 'sal_orders.id')
            ->whereRaw($left.' > 0', $sent->getBindings())
            ->whereRaw($left.' > ('.$onShelf->toSql().')', [...$sent->getBindings(), ...$onShelf->getBindings()]);
    }
}
