<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Support\SalesOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ বিক্রয় আদেশের তালিকা — কেন্দ্রীয় ছাঁকনি ইঞ্জিনে ([[OrderListFilters]]; মালিক, ১৪ নভেম্বর ২০২৬: "এইভাবে এই কাজটা ধরে ফেলো")।
 *
 *   - অবস্থার ঘর কেবল সেই অবস্থার আদেশ দেখায়; "অনুমোদনের অপেক্ষায়" কার্ড ঠিক সেগুলো গোনে
 *   - এলাকার ঘরে সংখ্যা আসে (এক কোয়েরিতে, পাতা ভাঙে না) আর এলাকা বাছলে কেবল সেই এলাকার আদেশ
 *   - উপরের ট্যাব খোঁজা বা ছাঁকনিতে হারায় না; ট্যাবের গোনাও ছাঁকনি মানে
 *   - উল্টো তারিখ (থেকে ৩১, পর্যন্ত ১) সোজা করে পড়া — আগের তারিখের ঘরের একই নিয়ম
 */
final class TheOrderListFiltersThroughTheEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $north;

    private Location $south;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->north = $this->place('ZON');
        $this->south = $this->place('ZOS');
        $northShop = $this->shop('Zox North Shop', $this->north);
        $southShop = $this->shop('Zox South Shop', $this->south);

        $this->order('ZSO-ASKING', $northShop, SalesOrderStatus::AWAITING_APPROVAL, '2026-03-10');
        $this->order('ZSO-SURE', $southShop, SalesOrderStatus::CONFIRMED, '2026-03-12');
        $this->order('ZSO-DRAFT', $southShop, SalesOrderStatus::DRAFT, '2026-04-20');
    }

    public function test_the_stage_box_and_the_waiting_card_pick_the_orders_that_wait_for_a_signature(): void
    {
        $this->listShows(['stage' => [SalesOrderStatus::AWAITING_APPROVAL], 'q' => 'ZSO'], ['ZSO-ASKING'], ['ZSO-SURE', 'ZSO-DRAFT'],
            '"অনুমোদনের অপেক্ষায়"-এ কেবল সইয়ের অপেক্ষার আদেশ');

        $html = (string) $this->get(route('sales.order.index', ['q' => 'ZSO']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/list-card-label">'.preg_quote(__('sales::order_status.state.awaiting_approval'), '/').'<(?:(?!list-card-label).)*?list-card-value num">'
            .preg_quote(__('sales::filter.orders', ['n' => '1']), '/').'</su', $html, '⛔ "অনুমোদনের অপেক্ষায়" কার্ডে একটা আদেশ দেখানোর কথা');
    }

    public function test_the_area_box_counts_in_one_go_and_narrows_to_its_place(): void
    {
        $response = $this->getJson(route('sales.order.index', ['q' => 'ZSO', '_filter_counts' => 1]));
        $this->assertSame(200, $response->getStatusCode(), '⛔ অর্ডারের তালিকার প্যানেল সংখ্যা চাইলে ভাঙল (৫০০) — এলাকার গোনায় দ্ব্যর্থ কলাম?');
        $counts = $response->json('counts');
        $this->assertSame(1, $counts['area'][(string) $this->north->id] ?? null, '⛔ উত্তরে একটা আদেশ — এলাকার ঘরে অন্য সংখ্যা');
        $this->assertSame(2, $counts['area'][(string) $this->south->id] ?? null, '⛔ দক্ষিণে দুটো আদেশ — এলাকার ঘরে অন্য সংখ্যা');

        $this->listShows(['area' => [$this->south->id], 'q' => 'ZSO'], ['ZSO-SURE', 'ZSO-DRAFT'], ['ZSO-ASKING'], 'দক্ষিণ এলাকা');
    }

    public function test_the_tab_survives_a_search_and_counts_with_the_filters(): void
    {
        $html = (string) $this->get(route('sales.order.index', ['tab' => 'draft', 'q' => 'ZSO']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form id="list-form"[^>]*>(?:(?!<\/form>).)*?<input type="hidden" name="tab" value="draft">/s', $html,
            '⛔ খুঁজলে বা ছাঁকনি দিলে উপরের ট্যাব হারায়');
        $this->assertStringContainsString('ZSO-DRAFT', $html);
        $this->assertStringNotContainsString('ZSO-SURE', $html, '⛔ "খসড়া" ট্যাবে নিশ্চিত আদেশ');

        // ⓘ ট্যাবের গোনা ছাঁকনি মানে — উত্তর এলাকায় খসড়া নেই, তাই খসড়ার ট্যাব শূন্য; সব ট্যাবে একটা
        $tabs = $this->get(route('sales.order.index', ['q' => 'ZSO', 'area' => [$this->north->id]]))->assertOk()->viewData('tabs');
        $count = collect($tabs)->pluck('count', 'key');
        $this->assertSame(0, (int) ($count['draft'] ?? -1), '⛔ এলাকা বাছার পরেও খসড়ার ট্যাব অন্য এলাকার খসড়া গুনল');
    }

    public function test_a_reversed_date_range_is_read_the_right_way_round(): void
    {
        $this->listShows(['from' => '2026-03-31', 'to' => '2026-03-01', 'q' => 'ZSO'], ['ZSO-ASKING', 'ZSO-SURE'], ['ZSO-DRAFT'],
            'উল্টো তারিখ (থেকে ৩১ মার্চ, পর্যন্ত ১ মার্চ)');
    }

    /**
     * ⭐ বারের "📅 তারিখ ▾" (পরিকল্পনা §৩.১/§৩.৩) — চটজলদি নাম এক চাপে, বাকি ছাঁকনি অটুট, বোতামে বাছা নামটা লেখা।
     * ⛔ ৩১ তারিখে "গত মাস" মানে আগের মাস ([[last-month-on-the-31st-is-this-month]]) — ৩১ মার্চে ফেব্রুয়ারি, মার্চ নয়।
     */
    public function test_the_date_button_on_the_bar_picks_a_period_in_one_press(): void
    {
        $this->travelTo('2026-03-31 15:00:00');

        $html = (string) $this->get(route('sales.order.index', ['q' => 'ZSO', 'area' => [$this->south->id]]))->assertOk()->getContent();
        $this->assertStringContainsString('data-date-bar', $html, '⛔ আদেশের তালিকার বারে "📅 তারিখ" বোতাম নেই');
        foreach (['today', 'yesterday', 'last7', 'this_month', 'last_month', 'last90', 'any'] as $preset) {
            $this->assertStringContainsString('data-date-preset="'.$preset.'"', $html, "⛔ তারিখের মেনুতে {$preset} নেই");
        }

        $thisMonth = $this->presetHref($html, 'this_month');
        parse_str((string) parse_url($thisMonth, PHP_URL_QUERY), $query);
        $this->assertSame('2026-03-01', $query['from'] ?? null, '⛔ "এই মাস" মাসের ১ তারিখ থেকে নয়');
        $this->assertSame('2026-03-31', $query['to'] ?? null, '⛔ "এই মাস" আজ পর্যন্ত নয়');
        $this->assertSame('ZSO', $query['q'] ?? null, '⛔ তারিখ বাছতে গিয়ে খোঁজা হারাল');
        $this->assertSame([(string) $this->south->id], array_map('strval', (array) ($query['area'] ?? [])), '⛔ তারিখ বাছতে গিয়ে এলাকার ছাঁকনি হারাল');

        parse_str((string) parse_url($this->presetHref($html, 'last_month'), PHP_URL_QUERY), $last);
        $this->assertSame(['2026-02-01', '2026-02-28'], [$last['from'] ?? null, $last['to'] ?? null],
            '⛔ ৩১ মার্চে "গত মাস" ফেব্রুয়ারি হওয়ার কথা (subMonth উপচে মার্চেই থাকে)');

        // চাপার পর — তালিকা সেই মাসের, বোতামে "এই মাস", মেনুতে সেটা বাছা
        $after = (string) $this->get($thisMonth)->assertOk()->getContent();
        $this->assertStringContainsString('ZSO-SURE', $after);
        $this->assertStringNotContainsString('ZSO-DRAFT', $after, '⛔ "এই মাস" (মার্চ) বাছার পরেও এপ্রিলের আদেশ');
        $this->assertMatchesRegularExpression('/data-date-bar-text>\s*'.preg_quote(__('core.list_filter.preset.this_month'), '/').'\s*</u', $after,
            '⛔ বাছার পরে বোতামে "এই মাস" লেখা নেই');
        $this->assertMatchesRegularExpression('/data-date-preset="this_month"\s+aria-current="true"/', $after, '⛔ মেনুতে বাছা নামটা চিহ্নিত নয়');
        // ⓘ বোতামেই "এই মাস" লেখা — তারিখ আবার দুটো চিপ হয়ে আসে না (বার দুই লাইনে ভাঙত); এলাকার চিপ থাকে
        $this->assertDoesNotMatchRegularExpression('/data-facet[^>]*>\s*<span class="truncate">'.preg_quote(__('sales::filter.order_date'), '/').'/u', $after,
            '⛔ বারের বোতামে তারিখ লেখা, তবু চিপেও একই তারিখ');
        $this->assertSame(1, preg_match_all('/<a data-facet /', $after), '⛔ এলাকার একটা চিপই থাকার কথা');

        // নিজে বাছা পরিসর — বোতামে তারিখ দুটো
        $own = (string) $this->get(route('sales.order.index', ['from' => '2026-03-05', 'to' => '2026-03-20']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-date-bar-text>\s*\S+ – \S+\s*</u', $own, '⛔ নিজে বাছা পরিসরে বোতামে তারিখ দুটো নেই');
    }

    public function test_the_customer_list_has_no_date_button_on_its_bar(): void
    {
        // ⓘ পরিকল্পনা: "📅 তারিখ (লেনদেনের তালিকায়)" — গ্রাহকের "যোগের তারিখ" প্যানেলেই থাকে, বারে নয়
        $html = (string) $this->get(route('customer.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-date-bar', $html, '⛔ গ্রাহকের তালিকায় বারে তারিখের বোতাম — পরিকল্পনায় কেবল লেনদেনের তালিকায়');
    }

    private function presetHref(string $html, string $preset): string
    {
        $this->assertMatchesRegularExpression('/<a href="([^"]+)" data-date-preset="'.$preset.'"/', $html, "⛔ {$preset}-এর লিংক নেই");
        preg_match('/<a href="([^"]+)" data-date-preset="'.$preset.'"/', $html, $m);

        return html_entity_decode($m[1]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $in
     * @param  list<string>  $out
     */
    private function listShows(array $query, array $in, array $out, string $why): void
    {
        $response = $this->get(route('sales.order.index', $query));
        $this->assertSame(200, $response->getStatusCode(), "⛔ {$why}: পাতা ভাঙল");
        $html = (string) $response->getContent();

        foreach ($in as $no) {
            $this->assertStringContainsString($no, $html, "⛔ {$why}: {$no} থাকার কথা, নেই।");
        }

        foreach ($out as $no) {
            $this->assertStringNotContainsString($no, $html, "⛔ {$why}: {$no} বাদ পড়ার কথা, আছে।");
        }
    }

    private function place(string $code): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => $code.' Place', 'name_bn' => $code.' জায়গা',
            'level' => Location::TERRITORY, 'is_active' => true,
        ]);
    }

    private function shop(string $name, Location $where): Customer
    {
        $customer = app(CustomerService::class)->create(['name_en' => $name, 'name_bn' => $name, 'credit_limit' => '0']);
        $customer->forceFill(['location_id' => $where->id])->saveQuietly();

        return $customer;
    }

    private function order(string $no, Customer $customer, string $status, string $date): int
    {
        $warehouse = (int) Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->value('id');

        return DB::table('sal_orders')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => $customer->id, 'warehouse_id' => $warehouse, 'trx_date' => $date,
            'status' => $status, 'subtotal' => '1000', 'total' => '1000', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
