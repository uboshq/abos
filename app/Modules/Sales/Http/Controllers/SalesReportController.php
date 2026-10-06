<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FinancialYear;
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
        // ⭐ বিক্রয়কর্মী ধরে বিক্রি — লক্ষ্যের একই নিয়মে (পরিকল্পনা সংস্করণ ২ §৯ গ; [[SalespersonReports]])
        'by-salesperson' => \App\Modules\Sales\Reports\SalespersonReports::KEY,
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.report')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $asked = $request->only($definition->requestKeys());

        /*
         * ⭐ মাসওয়ারি বিক্রয় খুললেই চলতি অর্থবছর — ইঞ্জিনের ডিফল্ট "এই মাস" এখানে একটাই সারি দিত
         * (মালিক, ১ অক্টোবর ২০২৬)। ⓘ বছরের সারি না থাকলে ইঞ্জিনের ডিফল্টই।
         */
        if ($slug === 'monthly' && blank($asked['from'] ?? null)) {
            $from = FinancialYear::forDate(now())?->starts_on?->toDateString();

            if ($from !== null) {
                $asked['from'] = $from;
            }
        }

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
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => $definition->hasFilter('branch')
                ? Branch::query()->active()->orderBy('name_en')->get()
                : collect(),
            'accounts' => collect(),
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
}
