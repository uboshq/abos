<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerMetrics;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Dashboard\SalesOverview;
use App\Modules\Sales\Models\SalesTarget;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Metrics\SalesPeriod;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * বিক্রয়ের পর্দা যা বলে, খাতাও তাই বলে (NEXUS §৪)।
 *
 * ── ⭐ দাবিগুলো ──────────────────────────────────────────────────────
 *   ⓵ দরজা `sales.report` — একই মানুষ, চাবি ছাড়া ৪০৩, চাবিতে খোলে
 *   ⓶ প্রতিটা কার্ড নিজের চাবিতে — একই মানুষ, চাবি ছাড়া কার্ডটাই নেই
 *   ⓷ প্রতিটা সংখ্যা হাতে গোনা যোগফলের সমান — SQL-এর SUM নয়, PHP-র bcadd
 *   ⓸ ফোন, হোম পর্দা আর এই পর্দা এক অঙ্ক — [[SalesMetrics]] থেকেই
 *   ⓹ অন্য কোম্পানির বিল গোনা হয় না; শাখায় সীমিত মানুষ কেবল নিজের শাখা
 *   ⓺ উল্টো তারিখের পরিসর চুপ করে অদলবদল হয় না
 *
 * ⓘ মানুষটা ভূমিকাহীন — ডেমোর ভূমিকা বদলায়, আর ভূমিকার উপর দাঁড়ানো দাবি
 * কাল ভুল কারণে লাল হত ([[TheOwnerAskedHowTodayWentFromThePhoneTest]]-এর একই কারণ)।
 */
final class TheSalesScreenCountsWhatTheBooksSayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->user = $this->newUser();

        // বাকির সীমা এখানে প্রশ্ন নয় — সরাসরি বিক্রয় যেন সীমায় না আটকায়
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
    }

    // ── ⓵ দরজা ─────────────────────────────────────────────────────────

    public function test_the_door_opens_on_the_report_key_for_the_same_person(): void
    {
        $this->overview()->assertForbidden();

        $this->grant($this->user, 'sales.report');

        $this->overview()->assertOk();
    }

    /** ⛔ দরজার চাবি একা কোনো সংখ্যা আনে না — "কিছু দেখার অনুমতি নেই" বলে। */
    public function test_the_door_key_alone_brings_no_figure(): void
    {
        $this->grant($this->user, 'sales.report');

        $response = $this->overview()->assertOk();

        $this->assertSame([], $response->viewData('cards'));
        $this->assertSame([], $response->viewData('panels'));
        $response->assertSee(__('sales::overview.nothing_for_you'));
    }

    // ── ⓶ প্রতিটা কার্ড নিজের চাবিতে ─────────────────────────────────────

    public function test_the_invoice_key_brings_the_sales_cards_and_nothing_else(): void
    {
        $this->assertCardsFollowTheKey(SalesOverview::INVOICE, [
            'sales.today', 'sales.month', 'sales.year',
            'sales.overview.invoices', 'sales.overview.direct', 'sales.overview.gross',
            'sales.overview.discount', 'sales.overview.tax', 'sales.overview.net',
        ], ['daily', 'monthly', 'top_customers', 'top_products', 'slow_products',
            'by_seller', 'by_territory', 'by_branch']);
    }

    public function test_the_order_key_brings_the_order_cards_and_nothing_else(): void
    {
        $this->assertCardsFollowTheKey(SalesOverview::ORDER,
            ['sales.overview.orders', 'sales.overview.pending_delivery'], []);
    }

    public function test_the_return_key_brings_the_return_card_and_nothing_else(): void
    {
        $this->assertCardsFollowTheKey(SalesOverview::RETURN, ['sales.overview.returns'], ['return_trend']);
    }

    public function test_the_collection_key_brings_the_collection_card_and_nothing_else(): void
    {
        $this->assertCardsFollowTheKey(SalesOverview::COLLECTION, ['sales.overview.collection'], []);
    }

    public function test_the_due_report_key_brings_the_receivable_card_and_nothing_else(): void
    {
        $this->assertCardsFollowTheKey(SalesOverview::DUES, ['sales.overview.receivable'], []);
    }

    public function test_the_target_key_brings_the_target_card_and_nothing_else(): void
    {
        $this->assertCardsFollowTheKey(SalesOverview::TARGET, ['sales.overview.target'], []);
    }

    /**
     * ⛔ "বাকি পড়ল" দুই চাবির বিয়োগ — একটা থাকলে আসে না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: কেবল বিলের চাবি। কার্ডটা এলে আদায়ের অঙ্কটা বিয়োগ
     * করে বের করা যেত — যে আদায় দেখতে পারেন না, তিনি পেতেন।
     */
    public function test_the_gap_card_needs_both_the_invoice_and_the_collection_key(): void
    {
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);

        $this->assertArrayNotHasKey('sales.overview.outstanding', $this->cards(),
            '⛔ কেবল বিলের চাবিতে "বাকি পড়ল" এসেছে — আদায়ের অঙ্ক বিয়োগেই বেরিয়ে যেত।');

        $this->grant($this->user, SalesOverview::COLLECTION);

        $this->assertArrayHasKey('sales.overview.outstanding', $this->cards());
    }

    /** ⛔ আদায়ের চাবি ছাড়া মাসিক ছকে আদায়ের কলামটাই নেই। */
    public function test_the_monthly_collection_column_follows_the_collection_key(): void
    {
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);

        $this->assertNull($this->overview()->viewData('panels')['monthly']['collected']);

        $this->grant($this->user, SalesOverview::COLLECTION);

        $this->assertIsArray($this->overview()->viewData('panels')['monthly']['collected']);
    }

    // ── ⓷ ⓸ সংখ্যাগুলো ─────────────────────────────────────────────────

    /**
     * ⭐ বিলের কার্ডগুলো হাতে লেখা অঙ্কের যোগফল।
     *
     * ⓘ দুইটা বিল আসল পথে নিশ্চিত, তারপর মাথার ঘরগুলো জানা অঙ্কে বসানো —
     * যাতে প্রত্যাশাটা এই ফাইলেই লেখা থাকে, কোনো কোয়েরি থেকে না আসে।
     */
    public function test_the_invoice_cards_equal_the_hand_counted_bills(): void
    {
        $before = $this->handSumOfPostedToday();

        $a = $this->billToday();
        $b = $this->billToday();
        $this->setHeader($a, subtotal: '1000.0000', discount: '50.0000', tax: '71.2500', total: '1021.2500');
        $this->setHeader($b, subtotal: '400.0000', discount: '0.0000', tax: '0.0000', total: '400.0000');

        // একটা খসড়া — গোনা হবে না
        $draft = app(SalesInvoiceService::class)->create($this->header(), $this->lines());
        $this->setHeader($draft, subtotal: '99999.0000', discount: '0.0000', tax: '0.0000', total: '99999.0000');
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status);

        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);
        $cards = $this->cards();

        $this->assertSame($before['count'] + 2, (int) $cards['sales.overview.invoices']['raw']);
        $this->assertSame(bcadd($before['gross'], '1400.0000', 4), bcadd($cards['sales.overview.gross']['raw'], '0', 4));
        $this->assertSame(bcadd($before['discount'], '50.0000', 4), bcadd($cards['sales.overview.discount']['raw'], '0', 4));
        $this->assertSame(bcadd($before['tax'], '71.2500', 4), bcadd($cards['sales.overview.tax']['raw'], '0', 4));
        $this->assertSame(bcadd($before['net'], '1421.2500', 4), bcadd($cards['sales.overview.net']['raw'], '0', 4),
            '⛔ খসড়া বিল গোনা হয়েছে, বা নিশ্চিত বিল বাদ পড়েছে।');

        // ⭐ ফোন আর হোম পর্দার একই সংজ্ঞা — "আজকের বিক্রয়" কার্ড আর নিট এক অঙ্ক
        $this->actingAs($this->user);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame(bcadd(SalesMetrics::salesToday()->value(), '0', 4),
            bcadd($cards['sales.overview.net']['raw'], '0', 4), '⛔ একই দিনের দুই সংজ্ঞা — পর্দা আর ফোন আলাদা বলবে।');
        $this->assertSame($cards['sales.today']['raw'], SalesMetrics::salesToday()->value());
    }

    /** ⭐ বারো মাসের প্রতিটা মাস একই সংজ্ঞার — মাস ধরে ভাগ, নতুন যোগফল নয়। */
    public function test_every_month_of_the_trend_is_the_one_definition(): void
    {
        $this->billToday();
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);

        $monthly = $this->overview()->viewData('panels')['monthly']['rows'];
        $this->assertCount(12, $monthly, '⛔ ফাঁকা মাস বাদ পড়েছে — বারোটা থাকার কথা।');

        $this->actingAs($this->user);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        foreach ($monthly as $month) {
            $this->assertSame(bcadd(SalesMetrics::invoiceTotal($month['from'], $month['to']), '0', 4),
                bcadd($month['net'], '0', 4), "⛔ {$month['ym']} মাসের ধারা আর বিক্রয়ের সংজ্ঞা আলাদা।");
        }

        $this->assertNotSame('0.0000', bcadd(end($monthly)['net'], '0', 4), 'এই মাসে বিক্রয় নেই — দাবিটা কিছু মাপছে না।');
    }

    /** ⭐ ভাগগুলো জোড়া দিলে নিট বিক্রয় — শাখা ধরে, এরিয়া ধরে, বিক্রেতা ধরে। */
    public function test_every_breakdown_adds_up_to_net_sales(): void
    {
        $this->billToday();
        $this->billToday();
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);

        $response = $this->overview();
        $net = bcadd($this->cardsOf($response)['sales.overview.net']['raw'], '0', 4);
        $panels = $response->viewData('panels');
        $this->assertNotSame('0.0000', $net, 'বিক্রয় নেই — দাবিটা কিছু মাপছে না।');

        foreach (['by_branch' => $panels['by_branch'], 'by_seller' => $panels['by_seller'],
            'by_territory' => $panels['by_territory']['rows']] as $name => $rows) {
            $sum = array_reduce($rows, fn (string $c, array $r) => bcadd($c, $r['amount'], 4), '0');
            $this->assertSame($net, $sum, "⛔ {$name}-এর ভাগগুলো জোড়া দিলে নিট বিক্রয় হয় না।");
        }
    }

    /** ⭐ আদায় আর বাজারের পাওনা — ভাগ করা দুই উৎস, ফোনের হুবহু। */
    public function test_collection_and_receivable_are_the_shared_definitions(): void
    {
        $this->billToday();
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::COLLECTION);
        $this->grant($this->user, SalesOverview::DUES);

        $cards = $this->cards();
        $today = Carbon::today()->toDateString();

        $this->actingAs($this->user);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertSame(SalesMetrics::collectionTotal($today, $today), $cards['sales.overview.collection']['raw']);
        $this->assertSame(
            app(CustomerMetrics::class)->dues($this->user, $today)['amount'],
            $cards['sales.overview.receivable']['raw'],
        );
    }

    /**
     * ⭐ সরাসরি বিক্রয় কেবল আদেশহীন চালানের বিল।
     *
     * ⚠️ বিপজ্জনক ইনপুট: চালান ছাড়া অফিসের বিল — ওটা সরাসরি বিক্রয় নয়,
     * অথচ "আদেশ নেই" শর্তটা ঢিলে লিখলে ওটাও ঢুকে যেত।
     */
    public function test_only_a_counter_sale_counts_as_a_direct_sale(): void
    {
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);
        $before = $this->cards()['sales.overview.direct']['raw'];

        $this->billToday();
        $this->assertSame($before, $this->cards()['sales.overview.direct']['raw'],
            '⛔ চালান ছাড়া অফিসের বিল সরাসরি বিক্রয়ে গোনা হয়েছে।');

        /*
         * ⚠️ বিপজ্জনক ইনপুট ২: আদেশ → চালান → বিল। চালান আছে, তাই "চালান আছে কি
         * না" দেখেই থামলে এটা সরাসরি বিক্রয়ে ঢুকত — আদেশের ঘরটাই আসল পার্থক্য।
         * (মিউট্যান্ট d4 এই সারি ছাড়া বেঁচে গিয়েছিল।)
         */
        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $productId = Product::query()->orderBy('id')->value('id');
        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create($this->header(),
            [['product_id' => $productId, 'ordered_qty' => '2', 'rate' => '100']]))->load('lines');
        $challans = app(DeliveryChallanService::class);
        $paper = $challans->create($this->header() + ['sales_order_id' => $order->id], [[
            'product_id' => $productId, 'sales_order_line_id' => $order->lines->first()->id,
            'delivered_qty' => '2', 'rate' => '100',
        ]]);
        $challans->confirm($paper->fresh(['lines']));
        $invoices = app(SalesInvoiceService::class);
        $fromOrder = $invoices->confirm($invoices->create($this->header(), [[
            'product_id' => $productId, 'delivery_challan_line_id' => $paper->fresh(['lines'])->lines->first()->id,
            'qty' => '2', 'rate' => '100',
        ]]));
        $this->assertContains($fromOrder->fresh()->status, DocumentStatus::POSTED, 'আদেশের বিল নিশ্চিত হয়নি — দাবিটা কিছু মাপছে না।');

        $this->assertSame($before, $this->cards()['sales.overview.direct']['raw'],
            '⛔ আদেশ থেকে আসা চালানের বিল সরাসরি বিক্রয়ে গোনা হয়েছে।');

        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $sale = app(DirectSaleService::class)->complete(
            $this->header() + ['deposit' => '0'],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '2', 'rate' => '100']],
        );
        $total = (string) $sale['invoice']->fresh()->total;
        $this->assertContains($sale['invoice']->fresh()->status, DocumentStatus::POSTED, 'বিক্রয় নিশ্চিত হয়নি — দাবিটা কিছু মাপছে না।');

        $this->assertSame(bcadd($before, $total, 4), bcadd($this->cards()['sales.overview.direct']['raw'], '0', 4));
    }

    /** ⭐ "মাল যায়নি" আদেশ-অনুসরণের পাতার হুবহু সংখ্যা। */
    public function test_pending_delivery_is_the_tracking_pages_number(): void
    {
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::ORDER);

        $cards = $this->cards();

        $this->actingAs($this->user);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $counts = app(OrderTracking::class)->counts(null);

        $this->assertSame((string) ($counts[OrderTracking::PLACED] + $counts[OrderTracking::PARTIAL]),
            $cards['sales.overview.pending_delivery']['raw']);
    }

    /** ⭐ ফেরত — নিশ্চিত ফেরতের হাতে গোনা যোগফল; খসড়া ফেরত বাদ। */
    public function test_the_return_card_equals_the_hand_counted_returns(): void
    {
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::RETURN);
        $before = $this->cards()['sales.overview.returns']['raw'];

        $posted = $this->returnToday();
        $draft = $this->returnToday();
        DB::table('sal_returns')->where('id', $posted->id)
            ->update(['status' => DocumentStatus::CONFIRMED, 'total' => '333.5000']);
        DB::table('sal_returns')->where('id', $draft->id)->update(['total' => '9999.0000']);

        $this->assertSame(bcadd($before, '333.5000', 4), bcadd($this->cards()['sales.overview.returns']['raw'], '0', 4),
            '⛔ খসড়া ফেরত গোনা হয়েছে, বা নিশ্চিত ফেরত বাদ পড়েছে।');
    }

    /**
     * ⭐ আদায় আর "বাকি পড়ল" — হাতে লেখা অঙ্কে, কোনো ভাগ করা পদ্ধতি থেকে নয়।
     *
     * ⓘ উপরের দাবি বলে পর্দা আর ফোন একই পদ্ধতি ডাকে; এটা বলে পদ্ধতিটা
     * নিজেই ঠিক — নিশ্চিত আদায় গোনা, খসড়া আদায় নয়।
     */
    public function test_the_collection_and_gap_cards_equal_the_hand_counted_money(): void
    {
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);
        $this->grant($this->user, SalesOverview::COLLECTION);
        $before = $this->cards();

        $bill = $this->billToday();
        $this->setHeader($bill, subtotal: '1000.0000', discount: '0.0000', tax: '0.0000', total: '1000.0000');

        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $collections = app(CollectionService::class);
        $customer = Customer::query()->orderBy('id')->value('id');
        $collections->confirm($collections->create(
            ['customer_id' => $customer, 'trx_date' => Carbon::today()->toDateString(), 'amount' => '300'], []));

        // একটা খসড়া আদায় — গোনা হবে না
        $collections->create(['customer_id' => $customer, 'trx_date' => Carbon::today()->toDateString(), 'amount' => '5000'], []);

        $after = $this->cards();

        $this->assertSame(bcadd($before['sales.overview.collection']['raw'], '300', 4),
            bcadd($after['sales.overview.collection']['raw'], '0', 4),
            '⛔ আদায়ের কার্ড নিশ্চিত আদায়ের যোগফল নয় — খসড়া গোনা হয়েছে, বা নিশ্চিতটা বাদ পড়েছে।');

        // বাকি পড়ল = নিট − আদায়, দুইটাই এই পাতার নিজের কার্ড থেকে
        $this->assertSame(
            bcsub(bcadd($after['sales.overview.net']['raw'], '0', 4), bcadd($after['sales.overview.collection']['raw'], '0', 4), 4),
            bcadd($after['sales.overview.outstanding']['raw'], '0', 4),
            '⛔ "বাকি পড়ল" নিট বিক্রয় থেকে আদায় বাদ দিয়ে হয় না।',
        );
        $this->assertSame(bcadd(bcsub($before['sales.overview.outstanding']['raw'], '0', 4), '700', 4),
            bcadd($after['sales.overview.outstanding']['raw'], '0', 4));
    }

    /**
     * ⭐ টার্গেটের অর্জন — যাঁর টার্গেট আছে কেবল তাঁর বিক্রয়, ভ্যাট বাদে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: টার্গেটহীন একজনের বড় বিল। গোনা হলে শতাংশ ফুলে উঠত।
     */
    public function test_the_target_card_counts_only_people_who_have_a_target(): void
    {
        $month = Carbon::today()->startOfMonth()->toDateString();

        foreach ([[$this->owner->id, '2000'], [$this->newUser()->id, '3000']] as [$userId, $amount]) {
            SalesTarget::query()->create([
                'company_id' => $this->company->id,
                'branch_id' => $this->company->defaultBranch()?->id,
                'user_id' => $userId,
                'month' => $month,
                'amount' => $amount,
            ]);
        }

        // মালিকের একটা বিল — টার্গেট আছে
        $mine = $this->billToday();

        // টার্গেটহীন একজনের বিল
        $stranger = $this->newUser();
        $this->actingAs($stranger);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $service = app(SalesInvoiceService::class);
        $service->confirm($service->create($this->header(),
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '5', 'rate' => '1000']]));

        // হাতে গোনা: মালিকের বিলের সারিগুলো, দাম − ভ্যাট
        $achieved = $mine->fresh('lines')->lines
            ->reduce(fn (string $c, $l) => bcsub(bcadd($c, (string) $l->amount, 4), (string) $l->tax, 4), '0');
        $this->assertSame(1, bccomp($achieved, '0', 4), 'মালিকের বিলে কিছু নেই — দাবিটা কিছু মাপছে না।');
        $expected = bcdiv(bcmul($achieved, '100', 6), '5000', 1);

        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::TARGET);
        $card = $this->cards()['sales.overview.target'];

        $this->assertSame($expected, $card['raw'],
            '⛔ টার্গেটের অর্জন হাতে গোনা অঙ্ক নয় — টার্গেটহীন বিক্রয় গোনা হয়েছে, বা টার্গেট যোগ হয়নি।');
        $this->assertSame($expected.'%', $card['value']);
    }

    // ── ⓹ দেয়াল ───────────────────────────────────────────────────────

    /** ⛔ অন্য কোম্পানির বিল এই কোম্পানির কোনো সংখ্যায় ঢোকে না। */
    public function test_another_companys_bill_is_not_counted_anywhere(): void
    {
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);
        $this->billToday();

        $before = $this->overview();
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        $moved = $this->billToday();
        DB::table('sal_invoices')->where('id', $moved->id)->update(['company_id' => $other->id]);

        $after = $this->overview();

        foreach (['sales.overview.invoices', 'sales.overview.net', 'sales.overview.gross', 'sales.today'] as $key) {
            $this->assertSame($this->cardsOf($before)[$key]['raw'], $this->cardsOf($after)[$key]['raw'],
                "⛔ অন্য কোম্পানির বিল {$key}-এ ঢুকেছে।");
        }

        $this->assertSame($before->viewData('panels')['by_branch'], $after->viewData('panels')['by_branch']);
        $this->assertSame($before->viewData('panels')['top_customers'], $after->viewData('panels')['top_customers']);
    }

    /**
     * ⛔ এক শাখায় সীমিত মানুষ অন্য শাখার বিল দেখেন না — কার্ডে না, ভাগেও না।
     *
     * ⓘ একই মানুষ দুইবার: সীমা ছাড়া দুই শাখাই, সীমা বসালে একটা।
     */
    public function test_a_person_held_to_one_branch_sees_only_that_branch(): void
    {
        $branches = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $branches->count(), 'ডেমোতে দুইটা শাখা নেই — দাবিটা কিছু মাপছে না।');
        [$mine, $theirs] = [$branches[0], $branches[1]];

        $here = $this->billToday();
        $there = $this->billToday();
        DB::table('sal_invoices')->where('id', $here->id)->update(['branch_id' => $mine->id]);
        DB::table('sal_invoices')->where('id', $there->id)->update(['branch_id' => $theirs->id, 'total' => '777.0000']);

        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);

        $all = $this->cards()['sales.overview.net']['raw'];

        $this->holdTo([$mine->id]);

        // ⚠️ DataScope `scoped` — আগের অনুরোধের "সীমা নেই" ক্যাশে থেকে গেলে দাবিটা কিছুই মাপত না
        $this->app->forgetScopedInstances();
        $response = $this->overview();
        $held = $this->cardsOf($response)['sales.overview.net']['raw'];

        // হাতে গোনা: আজকের নিশ্চিত বিল, এই শাখার বা শাখাহীন — PHP-তে
        $expected = SalesInvoice::acrossBranches()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereDate('trx_date', Carbon::today()->toDateString())
            ->where(fn ($q) => $q->where('branch_id', $mine->id)->orWhereNull('branch_id'))
            ->get()
            ->reduce(fn (string $c, SalesInvoice $i) => bcadd($c, (string) $i->total, 4), '0');

        $this->assertSame($expected, bcadd($held, '0', 4), '⛔ শাখায় সীমিত মানুষের নিট অন্য শাখার বিল গুনেছে।');
        $this->assertSame(bcadd($held, '777.0000', 4), bcadd($all, '0', 4), 'দুই অবস্থার ফারাক ঠিক অন্য শাখার বিলটা হওয়ার কথা।');

        $names = array_column($response->viewData('panels')['by_branch'], 'name');
        $this->assertNotContains($theirs->name(), $names, '⛔ শাখা ধরে ভাগে অন্য শাখার সারি এসেছে।');
    }

    // ── ⓺ সময়কাল ─────────────────────────────────────────────────────

    /**
     * ⛔ উল্টো, ভুয়া আর অতিরিক্ত লম্বা পরিসর — চুপ করে অদলবদল নয়, এই মাস আর সতর্কবার্তা।
     */
    public function test_a_bad_date_range_is_refused_out_loud(): void
    {
        $this->grant($this->user, 'sales.report');

        foreach ([
            ['from' => '2026-09-30', 'to' => '2026-09-01'],   // উল্টো
            ['from' => '2026-02-31', 'to' => '2026-03-05'],   // এমন তারিখ নেই
            ['from' => '2024-01-01', 'to' => '2026-01-01'],   // ৩৬৬ দিনের বেশি
            ['from' => "2026-09-01' or 1=1", 'to' => '2026-09-05'],
        ] as $range) {
            $response = $this->overview(['period' => SalesPeriod::CUSTOM] + $range)->assertOk();
            $period = $response->viewData('period');

            $this->assertTrue($period->refused, '⛔ ভুল পরিসর মেনে নেওয়া হয়েছে: '.json_encode($range));
            $this->assertSame(SalesPeriod::MONTH, $period->kind);
            $this->assertSame(Carbon::today()->startOfMonth()->toDateString(), $period->from);
            $response->assertSee('data-period-refused', false);
        }

        $good = $this->overview(['period' => SalesPeriod::CUSTOM, 'from' => '2026-09-01', 'to' => '2026-09-05'])
            ->viewData('period');
        $this->assertFalse($good->refused);
        $this->assertSame(['2026-09-01', '2026-09-05'], [$good->from, $good->to]);
    }

    /** ⭐ বাছা সময়কালই কার্ডের সময়কাল — গতকালের বিল "আজ"-এ নেই, "মাস"-এ আছে। */
    public function test_the_chosen_period_is_the_period_counted(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $this->grant($this->user, 'sales.report');
        $this->grant($this->user, SalesOverview::INVOICE);

        $yesterday = $this->billToday();
        DB::table('sal_invoices')->where('id', $yesterday->id)->update(['trx_date' => '2026-09-14', 'total' => '250.0000']);

        $today = $this->cardsOf($this->overview(['period' => SalesPeriod::TODAY]))['sales.overview.net']['raw'];
        $month = $this->cardsOf($this->overview(['period' => SalesPeriod::MONTH]))['sales.overview.net']['raw'];
        $custom = $this->cardsOf($this->overview(['period' => SalesPeriod::CUSTOM,
            'from' => '2026-09-14', 'to' => '2026-09-14']))['sales.overview.net']['raw'];

        // ⓘ হাতে গোনা: মাস = মাসের ১ তারিখ থেকে আজ পর্যন্ত নিশ্চিত বিল, PHP-তে
        $handMonth = SalesInvoice::acrossBranches()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', ['2026-09-01', '2026-09-15'])
            ->get()
            ->reduce(fn (string $c, SalesInvoice $i) => bcadd($c, (string) $i->total, 4), '0');

        $this->assertSame('250.0000', bcadd($custom, '0', 4), '⛔ হাতে বাছা দিনটার বিক্রয় ভুল।');
        $this->assertSame($handMonth, bcadd($month, '0', 4), '⛔ মাসের সংখ্যায় গতকালের বিলটা নেই।');
        $handToday = SalesInvoice::acrossBranches()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereDate('trx_date', '2026-09-15')
            ->get()
            ->reduce(fn (string $c, SalesInvoice $i) => bcadd($c, (string) $i->total, 4), '0');

        // ⓘ আজ কোনো বিল নেই — খালি reduce '0' ফেরায়, তাই দুই দিকই চার দশমিকে
        $this->assertSame(bcadd($handToday, '0', 4), bcadd($today, '0', 4), '⛔ "আজ" গতকালের বিলটা গুনেছে।');
        $this->assertSame(1, bccomp($handMonth, $handToday, 4), 'মাস আর আজ এক — দাবিটা কিছু মাপছে না।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /**
     * একই মানুষ: চাবি ছাড়া কার্ডগুলো নেই, চাবি দিলে ঠিক এগুলোই আছে — আর কিছু নয়।
     *
     * @param  list<string>  $cards
     * @param  list<string>  $panels
     */
    private function assertCardsFollowTheKey(string $key, array $cards, array $panels): void
    {
        $this->grant($this->user, 'sales.report');

        $off = $this->overview()->assertOk();
        foreach ($cards as $card) {
            $this->assertArrayNotHasKey($card, $this->cardsOf($off), "⛔ {$key} ছাড়াই {$card} এসেছে।");
            $off->assertDontSee('data-card="'.$card.'"', false);
        }

        $this->grant($this->user, $key);

        $on = $this->overview()->assertOk();
        $this->assertSame($cards, array_keys($this->cardsOf($on)),
            "⛔ {$key} দিলে ঠিক এই কার্ডগুলো আসার কথা — কম বা বেশি নয়।");
        $this->assertSame($panels, array_keys($on->viewData('panels')),
            "⛔ {$key} দিলে ঠিক এই ভাগগুলো আসার কথা।");

        foreach ($cards as $card) {
            $on->assertSee('data-card="'.$card.'"', false);
        }
    }

    /** @param array<string, string> $query */
    private function overview(array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->user->fresh())->get(route('sales.overview', $query));
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(array $query = []): array
    {
        return $this->cardsOf($this->overview($query));
    }

    /** @return array<string, array<string, mixed>> */
    private function cardsOf(TestResponse $response): array
    {
        return collect($response->viewData('cards'))->keyBy('key')->all();
    }

    private function newUser(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param list<int> $branchIds */
    private function holdTo(array $branchIds): void
    {
        foreach ($branchIds as $id) {
            UserDataScope::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'user_id' => $this->user->id,
                'scope_type' => UserDataScope::BRANCH,
                'scope_id' => $id,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function header(): array
    {
        return [
            'customer_id' => Customer::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => Carbon::today()->toDateString(),
        ];
    }

    /** @return list<array<string, string|int>> */
    private function lines(): array
    {
        return [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']];
    }

    /** আজকের একটা নিশ্চিত বিল — আসল পথে, মালিকের হাতে। */
    private function billToday(): SalesInvoice
    {
        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create($this->header(), $this->lines()));
    }

    /** আজকের একটা খসড়া ফেরত — আসল পথে; নিশ্চিত করা হয় দাবির ভেতরে, সারিতে। */
    private function returnToday(): SalesReturn
    {
        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // ফেরতের কারণ এখন বাধ্যতামূলক — ডেমোর নিজের "DAMAGE"
        $reason = ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id');

        return app(SalesReturnService::class)->create($this->header() + ['reason_code_id' => $reason], $this->lines());
    }

    private function setHeader(SalesInvoice $invoice, string $subtotal, string $discount, string $tax, string $total): void
    {
        DB::table('sal_invoices')->where('id', $invoice->id)
            ->update(compact('subtotal', 'discount', 'tax', 'total'));
    }

    /**
     * আজকের নিশ্চিত বিলগুলোর যোগফল — PHP-তে, সারি ধরে; SQL-এর SUM নয়।
     *
     * @return array{count: int, gross: string, discount: string, tax: string, net: string}
     */
    private function handSumOfPostedToday(): array
    {
        $rows = SalesInvoice::acrossBranches()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereDate('trx_date', Carbon::today()->toDateString())
            ->get();

        $sum = fn (string $column) => $rows->reduce(fn (string $c, SalesInvoice $i) => bcadd($c, (string) $i->{$column}, 4), '0');

        return [
            'count' => $rows->count(),
            'gross' => bcadd($sum('subtotal'), '0', 4),
            'discount' => bcadd($sum('discount'), '0', 4),
            'tax' => bcadd($sum('tax'), '0', 4),
            'net' => bcadd($sum('total'), '0', 4),
        ];
    }
}
