<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/** বিক্রয়ের তিনটা রিপোর্ট, হিসাবের পর্দার ভিউ দিয়েই। */
class SalesReportController extends Controller implements HasMiddleware
{
    /**
     * @var array<string, string>
     */
    private const SLUGS = [
        'monthly' => 'sales.monthly',
        'pending-orders' => 'sales.pending_orders',
        // ⭐ আদায়ের সূচি — আজ, সপ্তাহ, মাস আর সীমার ব্যবহার (২ অক্টোবর ২০২৬)
        'collection-due' => 'sales.collection_due',
        // ⭐ কাগজের খাতা — রিপোর্ট সেন্টার ধাপ ৬ ([[SalesRegisterReports]])
        'register' => 'sales.register',
        // ⭐ পরিকল্পনা সংস্করণ ২ §৯ (৪ অক্টোবর ২০২৬; [[SalesOrderBookReports]])
        'open-orders' => 'sales.open_orders',
        'credit-blocked' => 'sales.credit_blocked',
        // ⭐ বাকি ও আদায় ([[CreditControlReports]], ৫ অক্টোবর ২০২৬)
        'credit-use' => 'sales.credit_use',
        'blocked-customers' => 'sales.credit_blocked_customers',
        'risky-customers' => 'sales.credit_risk',
        'limit-history' => 'sales.credit_limit_history',
        'invoice-book' => 'sales.invoice_book',
        'uninvoiced' => 'sales.uninvoiced',
        'by-customer' => 'sales.by_customer',
        'by-product' => 'sales.by_product',
        'by-brand' => 'sales.by_brand',
        'by-channel' => 'sales.by_channel',
        'by-route' => 'sales.by_route',
        // ⭐ সময়মতো ও পুরো — আদেশের লাইন ধরে (পরিকল্পনা সংস্করণ ২ §৯, ৬ অক্টোবর ২০২৬; [[DeliveryReports]])
        'otif' => \App\Modules\Sales\Reports\DeliveryReports::OTIF,
        'challan-status' => \App\Modules\Sales\Reports\DeliveryReports::CHALLAN_STATUS,
        // ⭐ আদেশ থেকে রওনার সময় — DO ধরে (পরিকল্পনা সংস্করণ ২ §৯ ঘ)
        'order-to-dispatch' => \App\Modules\Sales\Reports\DeliveryReports::ORDER_TO_DISPATCH,
        // ⭐ আদায়ের লক্ষ্য বনাম অর্জন — ডিলার ধরে, লক্ষ্যের পাতার একই অর্জন (পরিকল্পনা সংস্করণ ২ §৯ ঙ)
        'collection-target' => \App\Modules\Sales\Reports\CollectionTargetReports::KEY,
        // ⭐ বিক্রয়কর্মী ধরে বিক্রি — লক্ষ্যের একই নিয়মে (পরিকল্পনা সংস্করণ ২ §৯ গ; [[SalespersonReports]])
        'by-salesperson' => \App\Modules\Sales\Reports\SalespersonReports::KEY,
        // ⭐ কে কত বিল করেছেন — কাজের হিসাব, বিক্রি নয় (মালিক, ২৩ নভেম্বর ২০২৬; [[BillerReports]])
        'by-biller' => \App\Modules\Sales\Reports\BillerReports::KEY,
        // ⭐ প্রতিদিনের জমার খাতা (জমার খবর ধাপ গ, ১৮ নভেম্বর ২০২৬; [[DepositRegisterReport]])
        'deposit-register' => \App\Modules\Sales\Reports\DepositRegisterReport::KEY,
        // ⭐ টাকা আসা — আসল টাকা, রূপগুলো পাতার ট্যাবে ([[MoneyInReports]]; মালিক, ২৩ নভেম্বর ২০২৬)
        'money-in' => \App\Modules\Sales\Reports\MoneyInReports::DAY,
        'money-in-branch' => \App\Modules\Sales\Reports\MoneyInReports::BRANCH,
        'money-in-area' => 'sales.money_in_area',
        'money-in-territory' => 'sales.money_in_territory',
        'money-in-point' => 'sales.money_in_point',
        'money-in-customer' => 'sales.money_in_customer',
        'money-in-salesperson' => \App\Modules\Sales\Reports\MoneyInReports::PERSON,
        'money-in-channel' => \App\Modules\Sales\Reports\MoneyInReports::CHANNEL,
        'money-in-receipts' => \App\Modules\Sales\Reports\MoneyInReports::PAPERS,
        'money-in-excluded' => \App\Modules\Sales\Reports\MoneyInReports::EXCLUDED,
        'money-in-excluded-papers' => \App\Modules\Sales\Reports\MoneyInReports::EXCLUDED_PAPERS,
        // ⭐ এলাকা ধরে বিক্রয় (স্তর ধরে) আর শাখা ধরে বিক্রয় ([[AreaSalesReports]]; মালিক, ২৩ নভেম্বর ২০২৬)
        'area-sales' => 'sales.area_sales_area',
        'area-sales-territory' => 'sales.area_sales_territory',
        'area-sales-point' => 'sales.area_sales_point',
        'area-sales-customer' => 'sales.area_sales_customer',
        'branch-sales' => \App\Modules\Sales\Reports\AreaSalesReports::BRANCH,
        // ⭐ লক্ষ্য বনাম বিক্রয় — স্তর আর বিক্রয়কর্মী ধরে ([[CustomerInsightReports]])
        'target-sales' => 'sales.target_sales_area',
        'target-sales-territory' => 'sales.target_sales_territory',
        'target-sales-point' => 'sales.target_sales_point',
        'target-sales-customer' => 'sales.target_sales_customer',
        'target-sales-salesperson' => \App\Modules\Sales\Reports\CustomerInsightReports::TARGET_PERSON,
        // ⭐ পণ্য ধরে — পণ্যের মাসিক লক্ষ্য বনাম বিক্রি ([[ProductInsightReports::TARGET]]; মালিক, ২৩ নভেম্বর ২০২৬)
        'target-sales-product' => \App\Modules\Sales\Reports\ProductInsightReports::TARGET,
        // ⭐ বিক্রির ধারা — মাস আর তিন মাস ধরে ([[SalesTrendReports]])
        'sales-trend' => \App\Modules\Sales\Reports\SalesTrendReports::MONTHLY,
        'sales-trend-quarterly' => \App\Modules\Sales\Reports\SalesTrendReports::QUARTERLY,
        // ⭐ ABC — পণ্য আর গ্রাহক ([[ProductInsightReports]], [[CustomerInsightReports]])
        'product-abc' => \App\Modules\Sales\Reports\ProductInsightReports::ABC,
        'customer-abc' => \App\Modules\Sales\Reports\CustomerInsightReports::ABC,
        // ⭐ নতুন, ঘুমন্ত আর হারানো গ্রাহক
        'customers-new' => \App\Modules\Sales\Reports\CustomerInsightReports::NEW,
        'customers-idle-30' => 'sales.customers_idle_30',
        'customers-idle-60' => 'sales.customers_idle_60',
        'customers-idle-90' => 'sales.customers_idle_90',
        // ⭐ আদায়ের দক্ষতা আর DSO — স্তর আর শাখা ধরে
        'collection-efficiency' => 'sales.collection_efficiency_area',
        'collection-efficiency-territory' => 'sales.collection_efficiency_territory',
        'collection-efficiency-point' => 'sales.collection_efficiency_point',
        'collection-efficiency-customer' => 'sales.collection_efficiency_customer',
        'collection-efficiency-branch' => \App\Modules\Sales\Reports\CustomerInsightReports::EFFICIENCY_BRANCH,
        // ⭐ স্কিম ও ছাড়ের খরচ ([[SchemeCostReports]])
        'scheme-cost' => \App\Modules\Sales\Reports\SchemeCostReports::SCHEMES,
        'scheme-cost-bills' => \App\Modules\Sales\Reports\SchemeCostReports::BILLS,
        'discount-by-salesperson' => \App\Modules\Sales\Reports\SchemeCostReports::DISCOUNTS,
        // ⭐ দামের প্রাপ্তি ([[ProductInsightReports]])
        'price-realisation' => \App\Modules\Sales\Reports\ProductInsightReports::PRICE,
        // ⭐ ফেরতের হার — পণ্য আর জায়গার স্তর ধরে ([[ReturnRateReports]])
        'return-rate' => \App\Modules\Sales\Reports\ReturnRateReports::PRODUCT,
        'return-rate-area' => 'sales.return_rate_area',
        'return-rate-territory' => 'sales.return_rate_territory',
        'return-rate-point' => 'sales.return_rate_point',
        'return-rate-customer' => 'sales.return_rate_customer',
        // ⭐ বিক্রয়কর্মীর কাজের হিসাব ([[SalesForceReports]])
        'salesperson-productivity' => \App\Modules\Sales\Reports\SalesForceReports::KEY,
        // ⭐ এলাকা ধরে বাকির বয়স ([[DueAgeingReports]])
        'due-ageing' => 'sales.due_ageing_area',
        'due-ageing-territory' => 'sales.due_ageing_territory',
        'due-ageing-point' => 'sales.due_ageing_point',
        'due-ageing-customer' => 'sales.due_ageing_customer',
        // ⭐ দৈনিক বিক্রি ও আদায়ের সারাংশ ([[DailySummaryReports]])
        'daily-summary' => \App\Modules\Sales\Reports\DailySummaryReports::KEY,
    ];

    /**
     * ⭐ এক রিপোর্টের কয়েক রূপ — পাতার মাথায় ট্যাব (মালিকের অনুমোদিত নমুনা, ২৩ নভেম্বর ২০২৬): স্লাগ → ট্যাবের নামের চাবি।
     * ⓘ ট্যাব বদলালে সময় আর ছাঁকনি সাথে যায় — যে ছাঁকনি নতুন রূপ চেনে না, ইঞ্জিন সেটা ফেলে দেয়।
     *
     * @var list<array<string, string>>
     */
    private const TABS = [
        [
            'money-in' => 'sales::money_in.tab_day',
            'money-in-branch' => 'sales::money_in.tab_branch',
            'money-in-area' => 'sales::money_in.tab_area',
            'money-in-territory' => 'sales::money_in.tab_territory',
            'money-in-point' => 'sales::money_in.tab_point',
            'money-in-customer' => 'sales::money_in.tab_customer',
            'money-in-salesperson' => 'sales::money_in.tab_person',
            'money-in-channel' => 'sales::money_in.tab_channel',
            'money-in-receipts' => 'sales::money_in.tab_papers',
            'money-in-excluded' => 'sales::money_in.tab_excluded',
        ],
        [
            'area-sales' => 'sales::area_sales.tab_area',
            'area-sales-territory' => 'sales::area_sales.tab_territory',
            'area-sales-point' => 'sales::area_sales.tab_point',
            'area-sales-customer' => 'sales::area_sales.tab_customer',
        ],
        [
            'target-sales' => 'sales::area_sales.tab_area',
            'target-sales-territory' => 'sales::area_sales.tab_territory',
            'target-sales-point' => 'sales::area_sales.tab_point',
            'target-sales-customer' => 'sales::area_sales.tab_customer',
            'target-sales-salesperson' => 'sales::customer_insight.tab_person',
            'target-sales-product' => 'sales::product_target.tab_product',
        ],
        [
            'sales-trend' => 'sales::sales_trend.tab_monthly',
            'sales-trend-quarterly' => 'sales::sales_trend.tab_quarterly',
        ],
        [
            'customers-new' => 'sales::customer_insight.tab_new',
            'customers-idle-30' => 'sales::customer_insight.tab_idle_30',
            'customers-idle-60' => 'sales::customer_insight.tab_idle_60',
            'customers-idle-90' => 'sales::customer_insight.tab_idle_90',
        ],
        [
            'collection-efficiency' => 'sales::area_sales.tab_area',
            'collection-efficiency-territory' => 'sales::area_sales.tab_territory',
            'collection-efficiency-point' => 'sales::area_sales.tab_point',
            'collection-efficiency-customer' => 'sales::area_sales.tab_customer',
            'collection-efficiency-branch' => 'sales::customer_insight.tab_branch',
        ],
        [
            'scheme-cost' => 'sales::scheme_cost.tab_schemes',
            'discount-by-salesperson' => 'sales::scheme_cost.tab_discounts',
        ],
        [
            'return-rate' => 'sales::return_rate.tab_product',
            'return-rate-area' => 'sales::area_sales.tab_area',
            'return-rate-territory' => 'sales::area_sales.tab_territory',
            'return-rate-point' => 'sales::area_sales.tab_point',
            'return-rate-customer' => 'sales::area_sales.tab_customer',
        ],
        [
            'due-ageing' => 'sales::area_sales.tab_area',
            'due-ageing-territory' => 'sales::area_sales.tab_territory',
            'due-ageing-point' => 'sales::area_sales.tab_point',
            'due-ageing-customer' => 'sales::area_sales.tab_customer',
        ],
    ];

    /**
     * ⭐ যে রিপোর্টের দরজার চাবি `sales.report` নয় — জমার খাতা দেখেন হিসাবরক্ষক, যাঁর বিক্রয়ের রিপোর্টের চাবি নেই।
     * ⓘ বাকি সব আগের মতো `sales.report`; রিপোর্টের নিজের ঘোষিত চাবিও ইঞ্জিন দেখে।
     *
     * @var array<string, string>
     */
    private const OWN_KEYS = [
        'deposit-register' => 'sales.claim.view',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        /*
         * ⓘ রুটে সবার জন্য একটা চাবি নেই — প্রতিটা স্লাগ নিজেরটা চায়, `show()`-এ ([[PurchaseReportController]]-এর একই উপায়);
         * বেশিরভাগই `sales.report`, জমার খাতা `sales.claim.view` ([[OWN_KEYS]])। `auth` রুট-গোষ্ঠীতেই আছে।
         */
        return [];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $this->authorize(self::OWN_KEYS[$slug] ?? 'sales.report');

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $asked = $request->only($definition->requestKeys());

        /*
         * ⭐ মাসওয়ারি বিক্রয় খুললেই চলতি অর্থবছর — ইঞ্জিনের ডিফল্ট "এই মাস" এখানে একটাই সারি দিত
         * (মালিক, ১ অক্টোবর ২০২৬)। ⓘ বছরের সারি না থাকলে ইঞ্জিনের ডিফল্টই।
         */
        // ⓘ মাসওয়ারির চলতি অর্থবছর এখন রিপোর্টের নিজের ঘোষণা ([[ReportDefinition::$defaultPeriod]]) — ফোনের দরজাও একই সময় পায়

        $result = $this->reports->run(
            $key,
            /*
             * ⭐ ঘরগুলো ঘোষণা থেকেই — ২১ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ আগে এখানে একটা হাতে লেখা তালিকা ছিল, আর আটটা রিপোর্ট
             * কন্ট্রোলারে আটটা তালিকা এক ছিল না। ⚠️ ছয়টা `party_type_id`
             * পাঠাত না, অথচ রিপোর্টগুলো ছাঁকনিটা ঘোষণা করত আর পর্দায় ঘরটা
             * আঁকা হত — ব্যবহারকারী বেছে দিতেন আর কিছুই বদলাত না।
             *
             * ⓘ যে ঘোষণা থেকে ঘরটা আঁকা হয়, এখন সেখান থেকেই পড়া হয়।
             */
            $asked,
            page: max(1, (int) $request->query('page', 1)),
            // ⭐ "সব শাখা"-তে শাখা ধরে ভাগ + সর্বমোট — ভাগ হবে কি না ইঞ্জিন ঠিক করে ([[ReportEngine::branchPlan()]])
            byBranch: true,
        );

        return view('accounts::report.show', [
            ...$this->tabsFor($request, $slug),
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => $definition->hasFilter('branch')
                ? Branch::query()->active()->orderBy('name_en')->get()
                : collect(),
            // ⓘ টাকা আসার রিপোর্টে খাত বাছা যায় — কেবল টাকার খাত (নগদ, ব্যাংক, মোবাইল ব্যাংকিং), হিসাবের একই তালিকা থেকে
            'accounts' => $definition->hasFilter('account')
                ? \App\Modules\Accounts\Reports\Filters\MoneyAccountFilter::accounts()->get()
                : collect(),
            /*
             * পক্ষের ধরনের ছাঁকনি — কেবল যে রিপোর্ট চেয়েছে তার জন্য।
             *
             * ঘোষণা না করলে তালিকাটা খালি যায়, আর পর্দা ঘরটাই আঁকে না।
             * সব রিপোর্টে জোর করে বসালে মজুদের রিপোর্টেও "পক্ষের ধরন"
             * ড্রপডাউন বসত, যেখানে প্রশ্নটার কোনো মানে নেই।
             */
            'partyTypes' => $definition->hasFilter('party_type')
                ? PartyType::query()->active()->orderBy('code')->get()
                : collect(),
            // ⭐ ফলটা এক লাইনে — যে রিপোর্ট সারাংশ ঘোষণা করে (OTIF %, ৬ অক্টোবর ২০২৬); ⓘ আগে এই দরজা পাঠাতই না, তাই কোনো
            // বিক্রয় রিপোর্টের ফল পাতায় দেখাত না, অথচ হিসাবের রিপোর্টে দেখাত ([[ReportController]])
            'summary' => $definition->summary === null ? null : ($definition->summary)($result->totals),
        ]);
    }

    /**
     * এই স্লাগ যে দলে, তার ট্যাবগুলো — দল না থাকলে কিছুই নয় (পাতা তখন আগের মতো)।
     *
     * ⓘ ঠিকানায় সময় আর ছাঁকনি থেকে যায়; পাতা, খোঁজা আর "Top" নয় — ওগুলো এক রূপের নিজের।
     *
     * @return array{reportTabs?: list<array{key: string, label: string, url: string, active: bool}>}
     */
    private function tabsFor(Request $request, string $slug): array
    {
        foreach (self::TABS as $group) {
            // ⓘ দলের ভেতরের পাতা (যেমন "বাদ যাওয়া কাগজ") ট্যাবে নেই, তবু দলের ট্যাব দেখায় — তার মূল রূপটা চালু
            $home = isset($group[$slug]) ? $slug : collect(array_keys($group))
                ->sortByDesc(fn (string $s) => strlen($s))
                ->first(fn (string $s) => str_starts_with($slug, $s.'-'));

            if ($home === null) {
                continue;
            }

            $carry = $request->except(['page', 'q', 'top', 'compare', 'export', 'print', 'hide', 'sort', 'dir']);
            $tabs = [];

            foreach ($group as $tab => $label) {
                $tabs[] = [
                    'key' => $tab,
                    'label' => (string) __($label),
                    'url' => route('sales.report.show', ['slug' => $tab, ...$carry]),
                    'active' => $tab === $home,
                ];
            }

            return ['reportTabs' => $tabs];
        }

        return [];
    }
}
