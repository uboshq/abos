<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ বিক্রয়ের হালচালের প্রতিটা ভাগ নিট বিক্রি — বিল − ফেরত, VAT বাদে (IFRS 15.47/15.51; fe-র নিট বিক্রি ২, ২১ অক্টোবর ২০২৬)।
 *
 * দাবি, আসল পথে — ওয়েবের পাতা `GET /sales/overview?period=today` আর ফোনের `GET /api/v1/sales/overview` (Sanctum + অ্যাপের ক্ষমতা),
 * আসল নিশ্চিত বিলে আর আসল নিশ্চিত আংশিক ফেরতে ([[SalesInvoiceService]], [[SalesReturnService]]):
 *   · বিল ১,০০,০০০ + VAT ১০,০০০, ফেরত ১০,০০০ + VAT ১,০০০ → শাখা, বিক্রেতা, এরিয়া, সেরা ক্রেতা, সেরা পণ্য আর দিনের ঘর
 *     প্রতিটা ঠিক ৯০,০০০ বাড়ে (VAT বাদে, ফেরত বাদে); পণ্যের পরিমাণ ৯ বাড়ে;
 *   · ভাগ (%) নিট বিক্রির মোটে; পুরো তালিকার যোগের সারি = নিট বিক্রির কার্ড, পাতায় আঁকা আর ফোনে পাঠানো;
 *   · "এই সময়ে পাওনা বাড়ল" = বিল (VAT সহ) − ফেরত (VAT সহ) − আদায়: ১,১০,০০০ − ১১,০০০ − ৫০,০০০ = ৪৯,০০০;
 *     আদায় বেশি হলে "এই সময়ে পাওনা কমল", অঙ্ক ধনাত্মক।
 */
final class TheSalesOverviewBreakdownsAreNetSalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->web($this->owner);
        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        app(SettingsService::class)->set('sales.vat_enabled', true);
        $this->assertTrue((bool) app(SettingsService::class)->get('sales.vat_enabled', false), 'প্রস্তুতিটাই ভুল — বিক্রিতে VAT চালু হয়নি।');

        // ⭐ চালে ১০% VAT, দামের উপরে — বিল আর ফেরত দুইটাতেই VAT
        $vat = Tax::query()->create(['code' => 'NS2A-VAT10', 'name_en' => 'NS2A-VAT10', 'name_bn' => 'NS2A-VAT10', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->forceFill(['tax_id' => $vat->id])->save();

        Customer::acrossDealers()->whereIn('name_en', ['Rahim Traders'])->get()
            ->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save());
    }

    public function test_every_breakdown_of_the_overview_grows_by_the_net_sale_only(): void
    {
        $rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $before = $this->overview();

        [$bill] = $this->fixture();

        $after = $this->overview();
        $d = fn (string $k) => bcsub($after[$k], $before[$k], 4);

        $this->assertSame('90000.0000', $d('net'), '⛔ নিট বিক্রির কার্ড ৯০,০০০ বাড়েনি — প্রস্তুতি বা উৎস ভুল।');

        // ── প্রতিটা ভাগ ঠিক নিট বিক্রি বাড়ে, বিলের মোট (১,১০,০০০) নয় ──
        $this->assertSame('90000.0000', $d('branch'), '⛔ শাখার ঘর VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame('90000.0000', $d('seller'), '⛔ বিক্রেতার ঘর VAT বা ফেরত বাদ দেয় না — ফেরত মূল বিলের মানুষের ঘর থেকে কমেনি।');
        $this->assertSame('90000.0000', $d('territory'), '⛔ এরিয়ার ঘরগুলো VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame('90000.0000', $d('customer'), '⛔ সেরা ক্রেতার ঘর VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame('90000.0000', $d('product'), '⛔ সেরা পণ্যের ঘর VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame('9.0000', $d('product_qty'), '⛔ পণ্যের পরিমাণে ফেরত বাদ যায়নি।');
        $this->assertSame('90000.0000', $d('daily'), '⛔ দিনের ঘর VAT বা ফেরত বাদ দেয় না।');

        // ── পুরো তালিকার যোগ = নিট বিক্রি; ভাগ নিট বিক্রির মোটে ──
        foreach (['by_branch', 'by_seller', 'by_territory'] as $key) {
            $this->assertSame($after['net'], $after['totals'][$key]['amount'], "⛔ {$key}-এর যোগের সারি নিট বিক্রি নয়।");
            $this->assertTrue($after['totals'][$key]['whole'], "⛔ {$key} পুরো তালিকা, অথচ যোগের নাম \"মোট\" নয়।");
        }
        $this->assertSame(SalesAnalytics::share($after['customer'], $after['net']), $after['customer_share'],
            '⛔ ক্রেতার ভাগ (%) নিট বিক্রির মোটে নয়।');
        $this->assertSame(SalesAnalytics::share($after['branch'], $after['net']), $after['branch_share'],
            '⛔ শাখার ভাগ (%) নিট বিক্রির মোটে নয়।');

        // ── পাতায় যোগের সারি আঁকা, আর ফোনেও একই যোগ ──
        $this->assertSame(Money::format($after['net']), $after['html_branch_total'], '⛔ পাতায় শাখার তালিকার নিচে মোট নেই, বা নিট বিক্রি নয়।');
        $phone = $this->phone();
        $branchListing = collect($phone->json('listings'))->firstWhere('label', __('sales::overview.panel.by_branch'));
        $this->assertNotNull($branchListing, 'প্রস্তুতিটাই ভুল — ফোনে শাখার তালিকা নেই।');
        // ⓘ ফোনের যোগ = দেখানো সারিগুলোর (গোল করা) যোগ — পয়সার নিচের ভগ্নাংশ না থাকলে নিট বিক্রির কার্ডই
        $netColumn = array_search('net', array_column($branchListing['columns'], 'key'), true);
        $this->assertNotFalse($netColumn, '⛔ ফোনের শাখার তালিকায় নিট বিক্রির কলাম নেই।');
        $phoneSum = array_reduce($branchListing['rows'], fn (string $s, array $r) => bcadd($s, str_replace(',', '', $r[$netColumn]), 4), '0.0000');
        $this->assertSame(Money::format($phoneSum), $branchListing['total']['net'] ?? null, '⛔ ফোনের শাখার তালিকার মোট দেখানো সারিগুলোর যোগ নয়।');
        $this->assertSame(0, bccomp(Money::round($after['net'], 2), $phoneSum, 2), '⛔ ফোনের শাখার তালিকার মোট নিট বিক্রি নয়।');
        $this->assertSame(__('sales::overview.total_all'), $branchListing['total']['name'] ?? null);

        // ⓘ নাম — "নিট বিক্রি (VAT ও ফেরত বাদে)"
        $this->assertSame('নিট বিক্রি (VAT ও ফেরত বাদে)', __('sales::overview.col.net'));
        $this->assertSame($rahim->id, $after['customer_id']);
        $this->assertSame($rice->id, $after['product_id']);
        $this->assertNotNull($bill);
    }

    public function test_the_receivable_rose_by_bills_less_returns_less_collections_with_vat(): void
    {
        $before = $this->cards();
        [$bill] = $this->fixture();
        $collection = $this->collect($bill, '50000');
        $this->assertContains($collection->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — আদায় খাতায় বসেনি।');

        $after = $this->cards();
        $rise = bcsub(bcadd((string) $after['sales.overview.outstanding']['raw'], '0', 4), bcadd((string) $before['sales.overview.outstanding']['raw'], '0', 4), 4);

        // ১,১০,০০০ − ১১,০০০ − ৫০,০০০ = ৪৯,০০০ — VAT সহ, কারণ গ্রাহক VAT-ও দেন; নিট বিক্রি (৯০,০০০) − আদায় নয়
        $this->assertSame('49000.0000', $rise, '⛔ পাওনার নড়াচড়া বিল (VAT সহ) − ফেরত (VAT সহ) − আদায় নয়।');

        $raw = bcadd((string) $after['sales.overview.outstanding']['raw'], '0', 4);
        if (bccomp($raw, '0', 4) >= 0) {
            $this->assertSame('এই সময়ে পাওনা বাড়ল', $after['sales.overview.outstanding']['label'], '⛔ পাওনা বাড়ল, অথচ নাম তা বলে না।');
            $this->assertSame(Money::format($raw), $after['sales.overview.outstanding']['value']);
        }
    }

    public function test_more_collected_than_billed_says_the_receivable_fell(): void
    {
        $before = $this->cards();
        $old = bcadd((string) $before['sales.overview.outstanding']['raw'], '0', 4);
        [$bill] = $this->fixture();

        // ⚠️ বিপজ্জনক ইনপুট: বিল − ফেরতের চেয়ে বেশি আদায় — আগের যা-ই থাকুক, শেষে ঋণাত্মক
        $extra = bccomp($old, '0', 4) > 0 ? $old : '0';
        $paid = bcadd('150000', $extra, 4);
        $this->assertContains($this->collect($bill, $paid)->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — আদায় খাতায় বসেনি।');

        $card = $this->cards()['sales.overview.outstanding'];
        $raw = bcadd((string) $card['raw'], '0', 4);
        $this->assertSame(bcsub(bcadd($old, '99000', 4), $paid, 4), $raw, '⛔ পাওনার নড়াচড়া ভুল।');
        $this->assertSame(-1, bccomp($raw, '0', 4), 'প্রস্তুতিটাই ভুল — পাওনা কমেনি।');
        $this->assertSame('এই সময়ে পাওনা কমল', $card['label'], '⛔ পাওনা কমল, অথচ নাম "কমল" নয়।');
        $this->assertSame(Money::format(bcsub('0', $raw, 4)), $card['value'], '⛔ "কমল"-এর পাশে অঙ্ক ধনাত্মক নয়।');
        $this->assertSame('good', $card['tone']);

        // ⓘ ইংরেজিতেও একই শব্দ
        $this->assertSame('Receivable fell this period', __('sales::overview.card.outstanding_down', [], 'en'));
        $this->assertSame('Receivable rose this period', __('sales::overview.card.outstanding_up', [], 'en'));
    }

    /**
     * ⭐ ফোনের দরজা (fe আর a4, ২১ অক্টোবর ২০২৬): প্রতিটা তালিকার যোগ `listing.total`-এ (কলামের চাবি → লেখা), সারিতে নয়; টাকার
     * প্রতিটা ঘর সারিগুলোর bcmath যোগ; আর অ্যাপ ০.৪.৪১-এর জন্য পুরনো চাবি `amount` নতুন `net`-এর পাশে, একই অঙ্কে।
     */
    public function test_the_phone_lists_carry_their_total_apart_and_keep_the_old_amount_key(): void
    {
        $this->fixture();
        $this->web($this->owner);
        $panels = $this->get(route('sales.overview', ['period' => 'today']))->assertOk()->viewData('panels');
        $listings = collect($this->phone()->json('listings'))->keyBy('label');

        $territory = __('sales::overview.panel.by_territory', ['level' => __('master_data::level.'.$panels['by_territory']['level'])]);
        $expected = [
            __('sales::overview.panel.top_customers') => $panels['top_customers'],
            __('sales::overview.panel.top_products') => $panels['top_products'],
            __('sales::overview.panel.slow_products') => $panels['slow_products'],
            __('sales::overview.panel.by_seller') => $panels['by_seller'],
            $territory => $panels['by_territory']['rows'],
            __('sales::overview.panel.by_branch') => $panels['by_branch'],
        ];
        $this->assertSame(array_keys($expected), $listings->keys()->all(), 'প্রস্তুতিটাই ভুল — ফোনে ঠিক এই ছয়টা তালিকা নেই।');
        $money = fn (string $text) => bcadd(str_replace(',', '', $text), '0', 4);

        foreach ($listings as $label => $listing) {
            $keys = array_column($listing['columns'], 'key');
            $this->assertArrayHasKey('total', $listing, "⛔ {$label}: যোগ `listing.total`-এ নেই।");
            $this->assertNotContains('amount', $keys, "⛔ {$label}: `amount` কলাম হিসেবে ঘোষিত — অ্যাপ নিট অঙ্ক দুইবার আঁকবে।");
            $this->assertSame([...$keys, ...(in_array('net', $keys, true) ? ['amount'] : [])], array_keys($listing['total']),
                "⛔ {$label}: যোগের চাবি কলামের চাবি (আর পুরনো `amount`) নয়।");

            // ⓘ সারির সংখ্যা = তালিকার আসল সারি: পুরো তালিকা ("মোট") সবগুলো, প্রথম দশের তালিকা দরজার সীমায় — যোগের সারি সারিতে ঢোকেনি
            $whole = $listing['total'][$keys[0]] === __('sales::overview.total_all');
            $this->assertSame($whole, in_array($label, [__('sales::overview.panel.by_seller'), $territory, __('sales::overview.panel.by_branch')], true),
                "⛔ {$label}: যোগের নাম তালিকার ধরনের সাথে মেলে না।");
            $this->assertCount($whole ? count($expected[$label]) : min(count($expected[$label]), 20), $listing['rows'],
                "⛔ {$label}: সারির সংখ্যায় যোগের সারি ঢুকেছে, বা সারি বাদ পড়েছে।");
            $this->assertNotContains($listing['total'][$keys[0]], array_column($listing['rows'], 0), "⛔ {$label}: যোগের সারি সাধারণ সারি হয়ে বসেছে।");

            $rows = array_map(fn (array $r) => array_combine($keys, $r), $listing['rows']);
            foreach (array_intersect($keys, ['net', 'revenue']) as $column) {
                $sum = array_reduce($rows, fn (string $s, array $r) => bcadd($s, $money($r[$column]), 4), '0.0000');
                $this->assertSame(Money::format($sum), $listing['total'][$column], "⛔ {$label}: যোগের {$column} সারিগুলোর bcmath যোগ নয়।");
            }
            if (in_array('count', $keys, true)) {
                $this->assertSame((string) array_sum(array_map(fn (array $r) => (int) $r['count'], $rows)), $listing['total']['count'],
                    "⛔ {$label}: বিলের সংখ্যার যোগ ভুল।");
            }

            // ⓘ পুরনো অ্যাপের চাবি — যোগে `amount` তথ্য হিসেবে, `net`-এর সমান; সারি ঘর-ক্রমের তালিকা, তাই সেখানে নামের চাবি নেই
            if (in_array('net', $keys, true)) {
                $this->assertSame($listing['total']['net'], $listing['total']['amount'], "⛔ {$label}: যোগে `amount` আর `net` দুই অঙ্ক।");
                foreach ($listing['rows'] as $r) {
                    $this->assertTrue(array_is_list($r), "⛔ {$label}: সারি আর ঘর-ক্রমের তালিকা নয় — দুই অ্যাপই ভাঙবে।");
                }
            }
        }
    }

    /**
     * ⭐ ফোনের যোগ = দেখানো সারিগুলোর গোল করা অঙ্কের যোগ (fe, ২১ অক্টোবর ২০২৬)। ⚠️ বিপজ্জনক ইনপুট: পয়সার নিচের ভগ্নাংশ —
     * দুইজন ক্রেতার নিট .০০৪০-তে শেষ; প্রতিটা সারি নিচে গোল হয়, অথচ কাঁচা যোগ (.০০৮০) উপরে — তাই কাঁচা অঙ্ক যোগ করলে যোগফল
     * এক পয়সা বেশি দেখাত, আর সারি যোগ করা মানুষ মেলাতে পারতেন না।
     */
    public function test_the_phone_total_adds_the_rounded_rows_it_shows(): void
    {
        [$bill] = $this->fixture();
        $niloy = Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail();
        $niloy->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save();
        $second = $this->billTo((int) $niloy->id, '5', '7000');

        // ⓘ দুই বিলের মোটে .০০৪০ — পয়সার নিচের ভগ্নাংশ (মাথার ঘর সরাসরি, [[TheSalesScreenCountsWhatTheBooksSayTest]]-এর ধাঁচে)
        foreach ([$bill, $second] as $paper) {
            DB::table('sal_invoices')->where('id', $paper->id)->update(['total' => DB::raw('total + 0.0040')]);
        }
        $this->assertSame('0040', substr(bcadd((string) DB::table('sal_invoices')->where('id', $second->id)->value('total'), '0', 4), -4),
            'প্রস্তুতিটাই ভুল — বিলের মোটে পয়সার নিচের ভগ্নাংশ বসেনি।');

        $this->web($this->owner);
        $raw = array_column($this->get(route('sales.overview', ['period' => 'today']))->assertOk()->viewData('panels')['top_customers'], 'amount', 'id');
        $this->assertArrayHasKey((int) $bill->customer_id, $raw, 'প্রস্তুতিটাই ভুল — রহিম প্রথম দশে নেই।');
        $this->assertArrayHasKey((int) $niloy->id, $raw, 'প্রস্তুতিটাই ভুল — নিলয় প্রথম দশে নেই।');
        $this->assertSame('0040', substr(bcadd((string) $raw[(int) $niloy->id], '0', 4), -4), 'প্রস্তুতিটাই ভুল — সারির নিটে ভগ্নাংশ নেই।');

        // ⓘ প্রস্তুতির প্রমাণ: এই তালিকায় "গোল করা সারির যোগ" আর "কাঁচা যোগের গোল" সত্যিই আলাদা — নইলে দাবিটা কিছু মাপত না
        $roundedSum = array_reduce($raw, fn (string $s, $a) => bcadd($s, Money::round((string) $a, 2), 4), '0.0000');
        $rawSum = array_reduce($raw, fn (string $s, $a) => bcadd($s, (string) $a, 4), '0.0000');
        $this->assertNotSame(Money::format($rawSum), Money::format($roundedSum), 'প্রস্তুতিটাই ভুল — ভগ্নাংশগুলো যোগে এক পয়সাও সরায় না।');

        $listing = collect($this->phone()->json('listings'))->firstWhere('label', __('sales::overview.panel.top_customers'));
        $this->assertNotNull($listing, 'প্রস্তুতিটাই ভুল — ফোনে সেরা ক্রেতার তালিকা নেই।');
        $net = array_search('net', array_column($listing['columns'], 'key'), true);
        $shown = array_reduce($listing['rows'], fn (string $s, array $r) => bcadd($s, str_replace(',', '', $r[$net]), 4), '0.0000');

        $this->assertSame(Money::format($roundedSum), Money::format($shown), 'প্রস্তুতিটাই ভুল — ফোনের সারি পাতার সারির গোল করা অঙ্ক নয়।');
        $this->assertSame(Money::format($shown), $listing['total']['net'], '⛔ ফোনের যোগ দেখানো সারিগুলোর যোগ নয় — কাঁচা অঙ্ক যোগ করে এক পয়সা সরেছে।');
        $this->assertSame($listing['total']['net'], $listing['total']['amount']);
    }

    /**
     * ⭐ সারির সীমা আর যোগ (fe, ২১ অক্টোবর ২০২৬): পুরো তালিকা (বিক্রেতা ধরে) দরজার ২০-এর সীমা পেরোলেও সব সারি পাঠায়, যোগের নাম
     * "মোট" আর যোগ = সব সারি = নিট বিক্রি; প্রথম দশের তালিকা (সেরা ক্রেতা) কেবল দশটা, যোগের নাম "এই সারিগুলোর যোগ" আর যোগ = কেবল
     * পাঠানো সারি, পুরো নিট বিক্রি নয়। ⚠️ বিপজ্জনক ইনপুট: ২১ জন বিক্রেতা আর ২১ জন নতুন ক্রেতা।
     */
    public function test_a_whole_list_sends_every_row_and_a_top_list_only_its_ten(): void
    {
        $this->fixture();

        for ($i = 1; $i <= 21; $i++) {
            $this->web($this->owner);
            $customer = app(\App\Modules\Customer\Services\CustomerService::class)->create([
                'name_en' => "NS2A Buyer {$i}", 'name_bn' => "এনএস২এ ক্রেতা {$i}", 'credit_limit' => '0', 'credit_days' => 30,
            ]);
            $customer->forceFill(['credit_limit' => '100000000'])->save();
            $paper = $this->billTo((int) $customer->id, '1', (string) (1000 + $i));

            // ⓘ প্রতিটা বিল আলাদা মানুষের কাটা — বিক্রেতা ধরে তালিকা ২০ ছাড়ায়
            $seller = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
            DB::table('sal_invoices')->where('id', $paper->id)->update(['created_by' => $seller->id]);
        }

        $this->web($this->owner);
        $page = $this->get(route('sales.overview', ['period' => 'today']))->assertOk();
        $panels = $page->viewData('panels');
        $net = bcadd((string) collect($page->viewData('cards'))->firstWhere('key', 'sales.overview.net')['raw'], '0', 4);
        $this->assertGreaterThan(20, count($panels['by_seller']), 'প্রস্তুতিটাই ভুল — বিক্রেতার তালিকা ২০ ছাড়ায়নি।');
        $this->assertCount(SalesAnalytics::TOP, $panels['top_customers'], 'প্রস্তুতিটাই ভুল — সেরা ক্রেতার তালিকা দশে কাটেনি।');

        $listings = collect($this->phone()->json('listings'))->keyBy('label');
        $sum = function (array $listing): string {
            $net = array_search('net', array_column($listing['columns'], 'key'), true);

            return array_reduce($listing['rows'], fn (string $s, array $r) => bcadd($s, str_replace(',', '', $r[$net]), 4), '0.0000');
        };

        // ── পুরো তালিকা: সব সারি, "মোট", যোগ = নিট বিক্রি ──
        $sellers = $listings[__('sales::overview.panel.by_seller')];
        $this->assertCount(count($panels['by_seller']), $sellers['rows'], '⛔ বিক্রেতার পুরো তালিকা ২০-এ কাটা — "মোট" তখন না-পাঠানো সারিও গোনে।');
        $this->assertSame(__('sales::overview.total_all'), $sellers['total']['name']);
        $this->assertSame(Money::format($sum($sellers)), $sellers['total']['net'], '⛔ বিক্রেতার যোগ পাঠানো সারির যোগ নয়।');
        // ⓘ পুরো তালিকার প্রতিটা সারি এসেছে: পাতার সব সারির গোল করা অঙ্কের যোগ = ফোনের যোগ (পাতার সারির যোগ = নিট বিক্রি, প্রথম দাবি)
        $allSellers = array_reduce($panels['by_seller'], fn (string $s, array $r) => bcadd($s, Money::round((string) $r['amount'], 2), 4), '0.0000');
        $this->assertSame(Money::format($allSellers), $sellers['total']['net'], '⛔ বিক্রেতার পুরো তালিকার যোগে সব সারি নেই।');

        // ── প্রথম দশ: দশটা, "এই সারিগুলোর যোগ", যোগ = কেবল এই দশ ──
        $top = $listings[__('sales::overview.panel.top_customers')];
        $this->assertCount(SalesAnalytics::TOP, $top['rows'], '⛔ সেরা ক্রেতার তালিকা দশের বেশি বা কম পাঠায়।');
        $this->assertSame(__('sales::overview.total_shown'), $top['total']['name'], '⛔ প্রথম দশের যোগ "এই সারিগুলোর যোগ" বলে না।');
        $this->assertSame(Money::format($sum($top)), $top['total']['net'], '⛔ প্রথম দশের যোগ পাঠানো সারির যোগ নয়।');
        $this->assertSame(1, bccomp(Money::round($net, 2), $sum($top), 2), '⛔ প্রথম দশের যোগ পুরো নিট বিক্রি — না-পাঠানো সারিও গোনা।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function billTo(int $customerId, string $qty, string $rate): SalesInvoice
    {
        $this->web($this->owner);
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);
        $paper = $service->confirm($service->create([
            'customer_id' => $customerId,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => Carbon::today()->toDateString(),
        ], [[
            'product_id' => Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->id,
            'qty' => $qty, 'rate' => $rate,
        ]]))->fresh('lines');
        $this->assertContains($paper->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিলটা খাতায় বসেনি।');

        return $paper;
    }

    /** @return array{0: SalesInvoice, 1: SalesReturn} বিল ১,১০,০০০ (VAT ১০,০০০) আর আংশিক ফেরত ১১,০০০ (VAT ১,০০০), দুইটাই আজ, খাতায় */
    private function fixture(): array
    {
        $today = Carbon::today()->toDateString();
        $bill = $this->bill('10', '10000');
        $return = $this->giveBack($bill, '1', $today);

        $this->assertSame('110000.0000', bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা VAT সহ ১,১০,০০০ নয়।');
        $this->assertSame('10000.0000', bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT ১০,০০০ নয়।');
        $this->assertSame('0.0000', bcadd((string) $bill->freight_charge, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে গাড়িভাড়া আছে।');
        $this->assertSame((int) $this->owner->id, (int) $bill->created_by, 'প্রস্তুতিটাই ভুল — বিলটা মালিকের কাটা নয়।');
        $this->assertSame('11000.0000', bcadd((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতটা VAT সহ ১১,০০০ নয়।');
        $this->assertSame('1000.0000', bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT ১,০০০ নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');
        $this->assertSame($today, $return->trx_date->toDateString());

        return [$bill, $return];
    }

    /** @return array<string, mixed> আজকের পাতা থেকে বিলের শাখা, মালিক, রহিম আর চালের ঘর */
    private function overview(): array
    {
        $this->web($this->owner);
        $response = $this->get(route('sales.overview', ['period' => 'today']))->assertOk();
        $panels = $response->viewData('panels');
        $totals = $response->viewData('totals');
        $branchId = (int) $this->warehouse->branch_id;
        $branchName = \App\Models\Branch::query()->withoutGlobalScopes()->findOrFail($branchId)->name();
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();
        $sum = fn (array $rows) => array_reduce($rows, fn (string $s, array $r) => bcadd($s, (string) $r['amount'], 4), '0.0000');
        $row = fn (array $rows, string $k, mixed $v) => collect($rows)->firstWhere($k, $v);

        $branch = $row($panels['by_branch'], 'name', $branchName);
        $customer = $row($panels['top_customers'], 'id', $rahim->id);
        $product = $row($panels['top_products'], 'id', $rice->id);
        $today = collect($panels['daily'])->firstWhere('date', Carbon::today()->toDateString());
        $this->assertNotNull($today, 'প্রস্তুতিটাই ভুল — দিনের ধারায় আজ নেই।');

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        $cells = (new DOMXPath($dom))->query('//*[@data-panel="by_branch"]//tfoot//td');

        return [
            'net' => bcadd((string) collect($response->viewData('cards'))->firstWhere('key', 'sales.overview.net')['raw'], '0', 4),
            'branch' => bcadd((string) ($branch['amount'] ?? '0'), '0', 4),
            'branch_share' => $branch['share'] ?? null,
            'seller' => bcadd((string) ($row($panels['by_seller'], 'id', (int) $this->owner->id)['amount'] ?? '0'), '0', 4),
            'territory' => $sum($panels['by_territory']['rows']),
            'customer' => bcadd((string) ($customer['amount'] ?? '0'), '0', 4),
            'customer_share' => $customer['share'] ?? null,
            'customer_id' => $customer['id'] ?? null,
            'product' => bcadd((string) ($product['amount'] ?? '0'), '0', 4),
            'product_qty' => bcadd((string) ($product['qty'] ?? '0'), '0', 4),
            'product_id' => $product['id'] ?? null,
            'daily' => bcadd((string) $today['value'], '0', 4),
            'totals' => $totals,
            // ⓘ যোগের সারির টাকার ঘর — নাম, বিল, টাকা, ভাগ: তৃতীয়টা
            'html_branch_total' => $cells->length >= 3 ? trim($cells->item(2)->textContent) : null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(): array
    {
        $this->web($this->owner);

        return collect($this->get(route('sales.overview', ['period' => 'today']))->assertOk()->viewData('cards'))->keyBy('key')->all();
    }

    private function bill(string $qty, string $rate): SalesInvoice
    {
        $this->web($this->owner);
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => Carbon::today()->toDateString(),
        ], [[
            'product_id' => Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->id,
            'qty' => $qty, 'rate' => $rate,
        ]]))->fresh('lines');
    }

    private function giveBack(SalesInvoice $bill, string $qty, string $on): SalesReturn
    {
        $this->web($this->owner);
        CompanyContext::set((int) $this->company->id, (int) $bill->branch_id);
        $returns = app(SalesReturnService::class);

        return $returns->confirm($returns->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $bill->warehouse_id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => $on,
        ], [[
            'product_id' => $bill->lines->first()->product_id,
            'sales_invoice_line_id' => $bill->lines->first()->id,
            'qty' => $qty,
        ]]))->fresh();
    }

    private function collect(SalesInvoice $bill, string $amount): \App\Modules\Sales\Models\Collection
    {
        $this->web($this->owner);
        CompanyContext::set((int) $this->company->id, (int) $bill->branch_id);
        $collections = app(CollectionService::class);

        return $collections->confirm($collections->create(
            ['customer_id' => $bill->customer_id, 'trx_date' => Carbon::today()->toDateString(), 'amount' => $amount], []))->fresh();
    }

    private function web(User $user): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        $this->actingAs($user->fresh());
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);
    }

    private function phone(): TestResponse
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/sales/overview?period=today')->assertOk();
    }
}
