<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Services\LocationService;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\RouteTarget;
use App\Modules\Sales\Models\RouteVisit;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\RouteMetrics;
use App\Modules\Sales\Services\RouteVisitService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * রুটে ডিলার ছিলেন, কিন্তু রুটের কোনো খাতা ছিল না (NEXUS §২৭)।
 *
 * ── যা দাবি করা হচ্ছে ─────────────────────────────────────────────────
 *   ১. রুটের যোগফল = তার ডিলারদের বিল, আদায় আর বাকির যোগফল — আর বাকিটা
 *      **খতিয়ান থেকে**, [[Customer::outstanding()]]-এর সাথে পয়সায় পয়সায়।
 *   ২. ডিলার রুট বদলালে তাঁর পুরো খাতা নতুন রুটে যায় (আজকের রুট —
 *      কারণটা [[RouteMetrics]]-এর মাথায়)।
 *   ৩. অন্য কোম্পানির রুট আর ছকের ঘর ৪০৪।
 *   ৪. দুইটা দরজা, দুইটা চাবি — একই মানুষ, চাবি ছাড়া ৪০৩, চাবিসহ খোলে।
 *   ৫. ছকের পাহারা — পয়েন্টকে রুট বলা, অন্য কোম্পানির মানুষ, একই বারে
 *      দুইবার, আজে-বাজে বার — প্রতিটা বিপজ্জনক ইনপুট ফেরত যায়।
 *   ৬. শেষ করা ঘর থাকে; রিপোর্ট আর পর্দা একই অঙ্ক বলে।
 */
final class ARouteHadDealersButNoBooksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Location $routeOne;

    private Location $routeTwo;

    private Customer $rahim;

    private Customer $alam;

    private Customer $karim;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /* দুইটা রুট — দুইটাই কেন্দুয়া বাজার পয়েন্টের নিচে, গাছের নিয়ম মেনে */
        $point = Location::query()->where('code', 'PT-KDA')->firstOrFail();
        $this->routeOne = $this->route($point, 'RT-KDA-SAT', 'Kendua Saturday');
        $this->routeTwo = $this->route($point, 'RT-KDA-SUN', 'Kendua Sunday');

        $this->rahim = $this->onRoute('Rahim Traders', $this->routeOne);
        $this->alam = $this->onRoute('Alam Store', $this->routeOne);
        $this->karim = $this->onRoute('Karim Stores', $this->routeTwo);
    }

    // ── ১ · রুটের যোগফল = ডিলারদের যোগফল ──────────────────────────────────

    public function test_a_routes_totals_are_the_sum_of_its_dealers_bills_money_and_dues(): void
    {
        $billOne = $this->sell($this->rahim, 5);
        $billTwo = $this->sell($this->alam, 3);
        $paid = $this->collect($this->rahim, '200');

        [$from, $to] = $this->thisMonth();
        $figures = app(RouteMetrics::class)->forRoutes([$this->routeOne->id, $this->routeTwo->id], $from, $to);
        $one = $figures[$this->routeOne->id];

        $this->assertSame(2, $one['customers'], 'রুটে দুইজন ডিলার বসানো, গোনা হয়েছে অন্য সংখ্যা।');

        $this->assertSame(
            bcadd((string) $billOne->total, (string) $billTwo->total, 4),
            $one['sales'],
            '⛔ রুটের বিক্রয় তার দুই ডিলারের বিলের যোগফল নয়।',
        );

        $this->assertSame(bcadd((string) $paid->amount, '0', 4), $one['collections'],
            '⛔ রুটের আদায় ডিলারের আদায়ের কাগজের সাথে মেলে না।');

        /* ⭐ বাকি — খতিয়ান থেকে, ডিলারের নিজের পাতার সংখ্যার সাথে পয়সায় পয়সায় */
        $this->assertSame(
            bcadd($this->rahim->fresh()->outstanding(), $this->alam->fresh()->outstanding(), 4),
            $one['outstanding'],
            '⛔ রুটের বাকি ডিলারদের খতিয়ানের বাকির যোগফল নয় — দুই পর্দা দুই অঙ্ক দেখাবে।',
        );

        /* সমীকরণটা সবসময় মেলে: আগের জের + বিক্রয় − ফেরত − আদায় + অন্যান্য = বাকি */
        $computed = bcadd(bcsub(bcsub(bcadd($one['opening'], $one['sales'], 4), $one['returns'], 4), $one['collections'], 4), $one['other'], 4);
        $this->assertSame($one['outstanding'], $computed, '⛔ রুটের খাতার সমীকরণ মেলে না।');

        /* ডিলার ধরে যোগ করলেও একই — পর্দার উপরের যোগফল আর নিচের সারি একই সংজ্ঞা */
        $perCustomer = app(RouteMetrics::class)->forCustomers([$this->rahim->id, $this->alam->id], $from, $to);

        foreach (RouteMetrics::FIGURES as $key) {
            $this->assertSame(
                bcadd($perCustomer[$this->rahim->id][$key], $perCustomer[$this->alam->id][$key], 4),
                $one[$key],
                "⛔ '{$key}': রুটের অঙ্ক তার ডিলারদের সারির যোগফল নয়।",
            );
        }

        /* অন্য রুটে কিছুই চুইয়ে যায়নি */
        $this->assertSame('0.0000', $figures[$this->routeTwo->id]['sales'],
            '⛔ রুট ২-এ কিছু বিক্রি হয়নি, অথচ তার খাতায় অঙ্ক বসেছে।');
        $this->assertSame(1, $figures[$this->routeTwo->id]['customers']);
    }

    // ── ২ · রুট বদলালে খাতাও যায় ─────────────────────────────────────────

    /**
     * ⭐ সিদ্ধান্ত: রুটের খাতা ডিলারের **আজকের** রুট ধরে।
     *
     * ⓘ খতিয়ানের সারিতে রুট লেখা থাকে না, আর বকেয়া তুলবেন নতুন রুটের লোক
     * (বাঁধনের নকশা §খ৪)। তাই সরানোর পর পুরনো বিল আর বাকি নতুন রুটে, পুরনো
     * রুট খালি।
     */
    public function test_a_dealer_moved_to_another_route_takes_his_whole_book_with_him(): void
    {
        $bill = $this->sell($this->alam, 4);
        [$from, $to] = $this->thisMonth();

        $before = app(RouteMetrics::class)->forRoutes([$this->routeOne->id, $this->routeTwo->id], $from, $to);
        $this->assertSame(bcadd((string) $bill->total, '0', 4), $before[$this->routeOne->id]['sales'],
            'প্রস্তুতিটাই ভুল — সরানোর আগে বিলটা রুট ১-এ নেই।');

        $this->alam->forceFill(['location_id' => $this->routeTwo->id])->save();

        $after = app(RouteMetrics::class)->forRoutes([$this->routeOne->id, $this->routeTwo->id], $from, $to);

        $this->assertSame('0.0000', $after[$this->routeOne->id]['sales'],
            '⛔ ডিলার সরে গেছেন, অথচ তাঁর বিল পুরনো রুটের খাতায় রয়ে গেল।');
        $this->assertSame('0.0000', $after[$this->routeOne->id]['outstanding']);

        $this->assertSame(bcadd((string) $bill->total, '0', 4), $after[$this->routeTwo->id]['sales'],
            '⛔ নতুন রুট ডিলারের পুরনো বিল পায়নি।');
        $this->assertSame($this->alam->fresh()->outstanding(), bcsub($after[$this->routeTwo->id]['outstanding'],
            $this->karim->fresh()->outstanding(), 4),
            '⛔ নতুন রুট ডিলারের পুরো বাকি পায়নি — তুলবেন যিনি, তিনি জানবেন না কত।');
        $this->assertSame(2, $after[$this->routeTwo->id]['customers']);
    }

    // ── ৩ · অন্য কোম্পানি ────────────────────────────────────────────────

    public function test_another_companys_route_and_schedule_row_are_not_found(): void
    {
        [$foreignRoute, $foreignVisit] = $this->foreignRouteAndVisit();

        $this->get(route('sales.route.show', $foreignRoute->id))->assertNotFound();

        $this->post(route('sales.route.visit.assign', $foreignRoute->id), [
            'user_id' => $this->owner->id,
            'weekdays' => [6],
            'effective_from' => now()->toDateString(),
        ])->assertNotFound();

        $this->post(route('sales.route.visit.end', $foreignVisit->id), [
            'effective_to' => now()->toDateString(),
        ])->assertNotFound();

        $this->assertNull(
            RouteVisit::query()->withoutGlobalScopes()->whereKey($foreignVisit->id)->value('effective_to'),
            '⛔ অন্য কোম্পানির ছকের ঘর এখান থেকে শেষ করা গেছে।',
        );
        $this->assertSame(1, RouteVisit::query()->withoutGlobalScopes()->where('route_id', $foreignRoute->id)->count(),
            '⛔ অন্য কোম্পানির রুটে এখান থেকে ছক বসেছে।');

        /* তালিকা আর রিপোর্টেও নেই */
        $this->get(route('sales.route.index'))->assertOk()->assertDontSee('RT-FOREIGN');

        $rows = app(ReportEngine::class)->run('sales.by_route')->rows;
        $this->assertNotContains('RT-FOREIGN', collect($rows)->pluck('route_code')->all(),
            '⛔ অন্য কোম্পানির রুট রিপোর্টে এসেছে।');
    }

    // ── ৪ · চাবি ──────────────────────────────────────────────────────────

    public function test_the_route_screens_ask_for_the_view_key(): void
    {
        $this->sell($this->rahim, 2); // ⓘ সারি থাকুক — খালি তালিকায় রেন্ডারের কোড চলেই না

        $user = $this->withEverythingBut('sales.route.view');
        $this->assertFalse($user->can('sales.route.view'), 'প্রস্তুতিটাই ভুল — চাবি হাতে আছে।');

        $this->actingAs($user)->get(route('sales.route.index'))->assertForbidden();
        $this->actingAs($user)->get(route('sales.route.show', $this->routeOne))->assertForbidden();

        $user->givePermissionTo('sales.route.view');
        $user = $user->fresh();

        $this->actingAs($user)->get(route('sales.route.index'))->assertOk()->assertSee('RT-KDA-SAT');
        // ⓘ কোড ধরে, নাম নয় — নাম ব্যবহারকারীর ভাষায় আসে, কোড সব ভাষায় এক
        $this->actingAs($user)->get(route('sales.route.show', $this->routeOne))->assertOk()->assertSee($this->rahim->code);
    }

    /**
     * ⭐ বিক্রয়কর্মীর হাতে রুটের চাবি নেই — মালিকের নিয়ম, ২৬ সেপ্টেম্বর ২০২৬:
     * *"বিক্রয়কর্মী কেবল নিজের ডিলার দেখবেন।"*
     *
     * ── ⛔ কেন এটা উপরের দাবিটার পুনরাবৃত্তি নয় ──────────────────────
     * উপরের দাবিটা মাপে *"চাবি ছাড়া কেউ ঢুকতে পারে না"*। ⓘ সেটা সত্যি,
     * কিন্তু মালিকের নিয়মটা **চাবি নিয়ে নয়, ভূমিকা নিয়ে** — আর ঐ দুইটা
     * এক জিনিস কেবল ততক্ষণ, যতক্ষণ ভূমিকা-ছকে চাবিটা বসানো না হয়।
     *
     * ⚠️ তাই এই দাবিটা ছকটাকেই ধরে ([[DemoSeeder]]-এর বাদ-তালিকা)। ⭐ কেউ
     * একদিন বিক্রয়কর্মীর ছকে `sales.route.view` যোগ করলে এটা লাল হবে, আর
     * তখন তাঁকে নিচের প্রশ্নটার মুখোমুখি হতে হবে।
     *
     * ── ⛔ আর সেই প্রশ্নটা, খোলাখুলি লেখা ───────────────────────────────
     * এই পর্দায় **ডিলার-স্তরের কোনো দেয়াল নেই**:
     * [[RouteAccountController::show()]] রুটের প্রতিটা `Customer` দেখায়,
     * তাদের বিক্রয় আর বাকিসহ। ⓘ অর্থাৎ আজ নিয়মটা টিকে আছে **কেবল
     * চাবিটা না দেওয়ার কারণে** — পর্দার নিজের কোনো সুরক্ষা নেই।
     *
     * ⚠️ দেয়ালটা এখনো বানানো যায়নি, আর কারণটা সৎ: কর্মী↔ডিলার সংযোগটাই
     * এখনো নেই (মালিকের ২৬ সেপ্টেম্বরের সিদ্ধান্ত 'ক'-এর বাকি অংশ)।
     * ⓘ ঐ সংযোগ এলে এখানে `Customer`-এর কোয়েরিটাও ওটা মেনে ছাঁকতে হবে,
     * ঠিক যেমন [[Lead::scopeVisibleTo()]] করে।
     *
     * ⛔ তাই *"পর্দাটা সব ডিলার দেখায়"* নিয়ে কোনো দাবি লেখা **হয়নি** —
     * সেটা আজকের আচরণ, আর ঐ সংযোগ আসার দিনেই বদলাবে। ⚠️ দাবি লিখলে
     * সেদিন সারাইটাই লাল হত, আর কেউ ভাবত সারাইটা ভুল।
     */
    public function test_the_salesman_role_does_not_hold_the_route_key(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        foreach (['sales.route.view', 'sales.route.manage'] as $key) {
            $this->assertFalse($salesman->can($key), implode(PHP_EOL, [
                "⛔ বিক্রয়কর্মীর হাতে `{$key}` চলে এসেছে।",
                '',
                '⚠️ এই পর্দায় ডিলার-স্তরের দেয়াল নেই — RouteAccountController::show()',
                'রুটের প্রতিটা ডিলার তাদের বিক্রয় ও বাকিসহ দেখায়। তাই এই চাবিটা',
                'দেওয়া মানে বিক্রয়কর্মী **সবার** ডিলার দেখছেন, আর সেটা মালিকের',
                'নিয়মের সরাসরি উল্টো (২৬ সেপ্টেম্বর ২০২৬)।',
                '',
                '⭐ চাবিটা সত্যিই দিতে হলে আগে Customer-এর কোয়েরিটা কর্মী↔ডিলার',
                'সংযোগ মেনে ছাঁকতে হবে — Lead::scopeVisibleTo()-এর মতো।',
            ]));
        }

        /* ⓘ আর পর্দা দুইটাও সত্যিই বন্ধ — ছকে না থাকা মানে দরজায় না ঢোকা */
        $this->actingAs($salesman)->get(route('sales.route.index'))->assertForbidden();
        $this->actingAs($salesman)->get(route('sales.route.show', $this->routeOne))->assertForbidden();
    }

    /**
     * ⭐ পাতাটা সারিসহ খোলে — ডিলার, ছকের চালু আর শেষ হওয়া ঘর, লক্ষ্য, আর
     * ম্যানেজারের দুইটা ফর্ম।
     *
     * ⚠️ খালি তালিকায় কলামের render কখনো চলে না, আর শেষ-করার ফর্মটা
     * কেবল চালু ঘরে আঁকা হয় — তাই প্রতিটা শাখার একটা করে সারি বসানো।
     */
    public function test_the_route_page_opens_with_a_dealer_a_schedule_and_a_target(): void
    {
        $this->sell($this->rahim, 3);

        $service = app(RouteVisitService::class);
        $service->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [6], 'effective_from' => now()->subMonths(2)->toDateString(),
        ]);
        [$ended] = $service->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [1],
            'effective_from' => now()->subMonths(2)->toDateString(),
        ])->all();
        $service->end($ended, now()->subMonth()->toDateString());
        $service->setTarget($this->routeOne, now()->startOfMonth(), '4321');

        $running = RouteVisit::query()->where('weekday', 6)->firstOrFail();

        $this->get(route('sales.route.show', $this->routeOne))
            ->assertOk()
            ->assertSee($this->rahim->code)
            ->assertSee($this->owner->name)
            // চালু ঘরে শেষ-করার ফর্ম আছে; আগেই শেষ হওয়া ঘরে নেই
            ->assertSee(route('sales.route.visit.end', $running), false)
            ->assertDontSee(route('sales.route.visit.end', $ended), false)
            ->assertSee(route('sales.route.visit.assign', $this->routeOne), false)
            ->assertSee(route('sales.route.target', $this->routeOne), false)
            ->assertSee('4321');

        /* তালিকায়ও লক্ষ্য আর লোকের নাম */
        $this->get(route('sales.route.index'))
            ->assertOk()
            ->assertSee('RT-KDA-SAT')
            ->assertSee($this->owner->name);

        /* ⛔ আজে-বাজে ঠিকানা — অ্যারে-মাস বা অ্যারে-খোঁজা পাতা ভাঙে না, চলতি মাস দেখায় */
        $this->get(route('sales.route.index', ['month' => ['x'], 'q' => ['y']]))->assertOk();
        $this->get(route('sales.route.show', [$this->routeOne, 'month' => ['x']]))->assertOk();
    }

    public function test_the_schedule_and_target_ask_for_the_manage_key(): void
    {
        $user = $this->withEverythingBut('sales.route.manage');
        $this->assertTrue($user->can('sales.route.view'));

        $form = ['user_id' => $this->owner->id, 'weekdays' => [6, 1], 'effective_from' => now()->toDateString()];
        $url = route('sales.route.visit.assign', $this->routeOne);

        $this->postAs($url, $form, $user)->assertForbidden();
        $this->assertSame(0, RouteVisit::query()->count(), '⛔ চাবি ছাড়া ৪০৩ দিয়েও ছকে সারি বসেছে।');

        $targetUrl = route('sales.route.target', $this->routeOne);
        $this->postAs($targetUrl, ['month' => now()->startOfMonth()->toDateString(), 'amount' => '5000'], $user)
            ->assertForbidden();
        $this->assertSame(0, RouteTarget::query()->count());

        $user->givePermissionTo('sales.route.manage');
        $user = $user->fresh();

        $this->postAs($url, $form, $user)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, RouteVisit::query()->where('route_id', $this->routeOne->id)->count(),
            '⛔ একই মানুষ চাবি পাওয়ার পরেও ছক বসেনি — উপরের ৪০৩ কিছুই প্রমাণ করে না।');

        $this->postAs($targetUrl, ['month' => now()->startOfMonth()->toDateString(), 'amount' => '5000'], $user)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('5000.0000', (string) RouteTarget::query()->where('route_id', $this->routeOne->id)->value('amount'));
    }

    // ── ৫ · ছকের পাহারা ──────────────────────────────────────────────────

    public function test_a_point_is_not_a_route_and_cannot_carry_a_schedule(): void
    {
        $point = Location::query()->where('code', 'PT-KDA')->firstOrFail();

        $this->assertSame('route_id', $this->refusedOn(fn () => app(RouteVisitService::class)->assign($point, [
            'user_id' => $this->owner->id, 'weekdays' => [6], 'effective_from' => now()->toDateString(),
        ])));

        $this->post(route('sales.route.visit.assign', $point), [
            'user_id' => $this->owner->id, 'weekdays' => [6], 'effective_from' => now()->toDateString(),
        ])->assertNotFound();

        $this->get(route('sales.route.show', $point))->assertNotFound();
        $this->assertSame(0, RouteVisit::query()->count());
    }

    public function test_a_person_from_another_company_cannot_be_put_on_a_route(): void
    {
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();
        $stranger = User::factory()->create(['current_company_id' => $beta->id]);
        $stranger->companies()->attach($beta->id);

        $this->assertSame('user_id', $this->refusedOn(fn () => app(RouteVisitService::class)->assign($this->routeOne, [
            'user_id' => $stranger->id, 'weekdays' => [6], 'effective_from' => now()->toDateString(),
        ])));

        $this->assertSame(0, RouteVisit::query()->count(),
            '⛔ অন্য কোম্পানির কর্মী এই কোম্পানির রুটের ছকে বসেছেন।');
    }

    public function test_the_same_person_cannot_be_on_the_same_route_on_the_same_day_twice(): void
    {
        $service = app(RouteVisitService::class);

        $service->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [6, 1], 'effective_from' => '2026-09-01',
        ]);

        /* ⛔ বিপজ্জনক ইনপুট: শনিবার আবার, পরের তারিখ থেকে — প্রথমটা তখনো চলছে */
        $this->assertSame('weekdays', $this->refusedOn(fn () => $service->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [6], 'effective_from' => '2026-09-15',
        ])));

        $this->assertSame(2, RouteVisit::query()->count());

        /* ⭐ পাল্টা: অন্য বার চলে — পাহারাটা সব কিছু আটকাচ্ছে না */
        $service->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [3], 'effective_from' => '2026-09-15',
        ]);
        $this->assertSame(3, RouteVisit::query()->count());

        /* ⭐ পাল্টা: শনিবারের ঘর শেষ হলে তার পরদিন থেকে আবার শনিবার চলে */
        $saturday = RouteVisit::query()->where('weekday', 6)->firstOrFail();
        $service->end($saturday, '2026-09-30');

        $service->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [6], 'effective_from' => '2026-10-01',
        ]);
        $this->assertSame(2, RouteVisit::query()->where('weekday', 6)->count());
    }

    public function test_a_weekday_outside_the_week_is_refused(): void
    {
        foreach ([[7], [-1], ['1.5'], ['sat'], []] as $bad) {
            $this->assertSame('weekdays', $this->refusedOn(fn () => app(RouteVisitService::class)->assign($this->routeOne, [
                'user_id' => $this->owner->id, 'weekdays' => $bad, 'effective_from' => now()->toDateString(),
            ])), 'আজে-বাজে বার: '.json_encode($bad));
        }

        $this->assertSame('effective_to', $this->refusedOn(fn () => app(RouteVisitService::class)->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [6],
            'effective_from' => '2026-09-10', 'effective_to' => '2026-09-01',
        ])));

        $this->assertSame(0, RouteVisit::query()->count());
    }

    // ── ৬ · ইতিহাস, লক্ষ্য, রিপোর্ট ─────────────────────────────────────

    public function test_ending_a_schedule_row_keeps_it_and_the_route_forgets_him_only_afterwards(): void
    {
        $service = app(RouteVisitService::class);
        [$visit] = $service->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [6], 'effective_from' => '2026-09-01',
        ])->all();

        $this->post(route('sales.route.visit.end', $visit), ['effective_to' => '2026-09-20'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, RouteVisit::query()->count(), '⛔ শেষ করতে গিয়ে ছকের সারি মুছে গেছে — হাতবদলের ইতিহাস হারাল।');
        $this->assertSame('2026-09-20', $visit->fresh()->effective_to->toDateString());

        $metrics = app(RouteMetrics::class);
        $this->assertSame([$this->owner->name], $metrics->salespeopleOn([$this->routeOne->id], '2026-09-20')[$this->routeOne->id],
            '⛔ শেষ দিনেও তিনি রুটে ছিলেন।');
        $this->assertSame([], $metrics->salespeopleOn([$this->routeOne->id], '2026-09-21')[$this->routeOne->id],
            '⛔ শেষ হওয়ার পরদিনও তিনি রুটের লোক হিসেবে দেখাচ্ছেন।');

        /* ⛔ আগে শেষ হওয়া ঘরকে পরের তারিখে টেনে আনা যায় না */
        $this->assertSame('effective_to', $this->refusedOn(fn () => $service->end($visit->fresh(), '2026-09-25')));
    }

    public function test_the_route_target_is_measured_like_the_salesperson_target(): void
    {
        /*
         * ⚠️ ভ্যাটসহ পণ্য — নাহলে "ভ্যাট বাদ" দাবিটা কিছুই মাপত না।
         *
         * ⓘ ডেমোর পণ্যে কর নেই, আর তখন `SUM(amount)` আর `SUM(amount - tax)`
         * একই অঙ্ক দেয় — মিউট্যান্ট (ভ্যাটসহ অর্জন) দিব্যি বেঁচে ছিল।
         */
        $vat = Tax::create([
            'code' => 'ZRTVAT', 'name_en' => 'VAT 15%', 'name_bn' => 'VAT 15%',
            'rate' => '15', 'kind' => 'vat', 'is_inclusive' => false,
        ]);
        $this->product->forceFill(['tax_id' => $vat->id])->save();
        $this->product = $this->product->fresh();

        $bill = $this->sell($this->rahim, 6);
        $month = now()->startOfMonth();

        $this->assertSame(1, bccomp((string) $bill->fresh()->tax, '0', 4),
            'প্রস্তুতিটাই ভুল — বিলে ভ্যাট বসেনি, তাই "ভ্যাট বাদ" মাপা যাচ্ছে না।');

        app(RouteVisitService::class)->setTarget($this->routeOne, $month, '1200');

        $expected = '0';

        foreach ($bill->fresh(['lines'])->lines as $line) {
            $expected = bcadd($expected, bcsub((string) $line->amount, (string) $line->tax, 4), 4);
        }

        $row = app(RouteMetrics::class)->targetsFor([$this->routeOne->id, $this->routeTwo->id], $month)[$this->routeOne->id];

        $this->assertSame('1200.0000', $row['target']);
        $this->assertSame(bcadd($expected, '0', 4), $row['achieved'],
            '⛔ রুটের অর্জন বিলের লাইনের ভ্যাট-বাদ যোগফল নয় — বিক্রয়কর্মীর লক্ষ্যের সংজ্ঞা থেকে সরে গেছে।');
        $this->assertNotNull($row['percent']);

        /* ⛔ বিপজ্জনক ইনপুট: bcmath যা পড়তে পারে না, আর ঋণাত্মক — আগের লক্ষ্য অক্ষত থাকে */
        foreach (['1e3', '-5', 'abc'] as $bad) {
            $this->assertSame('amount', $this->refusedOn(
                fn () => app(RouteVisitService::class)->setTarget($this->routeOne, $month, $bad)), "লক্ষ্য: {$bad}");
        }
        $this->assertSame('1200.0000', (string) RouteTarget::query()->value('amount'));

        /* ⛔ পয়েন্টে লক্ষ্য বসে না */
        $point = Location::query()->where('code', 'PT-KDA')->firstOrFail();
        $this->assertSame('route_id', $this->refusedOn(
            fn () => app(RouteVisitService::class)->setTarget($point, $month, '500')));

        /* খালি মানে লক্ষ্য তুলে নেওয়া — শূন্য টাকার লক্ষ্য নয় */
        app(RouteVisitService::class)->setTarget($this->routeOne, $month, '');
        $this->assertSame(0, RouteTarget::query()->count());
        $this->assertNull(app(RouteMetrics::class)->targetsFor([$this->routeOne->id], $month)[$this->routeOne->id]['percent']);
    }

    public function test_the_route_report_is_registered_and_says_what_the_screen_says(): void
    {
        $this->assertContains('sales.by_route', app(ReportEngine::class)->keys(),
            '⛔ রুটের রিপোর্ট নিবন্ধিত নয় — module.php-এর `reports`-এ RouteReports নেই।');

        $this->sell($this->rahim, 5);
        $this->sell($this->karim, 2);
        $this->collect($this->rahim, '150');
        app(RouteVisitService::class)->assign($this->routeOne, [
            'user_id' => $this->owner->id, 'weekdays' => [6], 'effective_from' => now()->subMonth()->toDateString(),
        ]);

        [$from, $to] = $this->thisMonth();
        $result = app(ReportEngine::class)->run('sales.by_route', ['from' => $from, 'to' => $to]);
        $rows = collect($result->rows)->keyBy('route_code');

        $screen = app(RouteMetrics::class)->forRoutes([$this->routeOne->id, $this->routeTwo->id], $from, $to);

        foreach ([['RT-KDA-SAT', $this->routeOne], ['RT-KDA-SUN', $this->routeTwo]] as [$code, $route]) {
            $this->assertTrue($rows->has($code), "রিপোর্টে {$code} নেই।");

            foreach (RouteMetrics::FIGURES as $key) {
                $this->assertSame($screen[$route->id][$key], bcadd((string) $rows[$code][$key], '0', 4),
                    "⛔ {$code} '{$key}': রিপোর্ট আর রুটের পর্দা দুই অঙ্ক বলে।");
            }

            $this->assertSame($screen[$route->id]['customers'], (int) $rows[$code]['customer_count']);
        }

        $this->assertStringContainsString($this->owner->name, (string) $rows['RT-KDA-SAT']['salespeople']);

        /* পর্দার পথেও খোলে */
        $this->get(route('sales.report.show', 'by-route'))->assertOk()->assertSee('RT-KDA-SAT');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function route(Location $point, string $code, string $name): Location
    {
        return app(LocationService::class)->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'level' => Location::ROUTE,
            'parent_id' => $point->id,
        ]);
    }

    private function onRoute(string $name, Location $route): Customer
    {
        $customer = Customer::query()->where('name_en', $name)->firstOrFail();

        // ⓘ সীমা বড় — এখানে মাপা হচ্ছে খাতা, সীমার দেয়াল নয়
        $customer->forceFill(['location_id' => $route->id, 'credit_limit' => '100000000'])->save();

        return $customer->fresh();
    }

    /** বাকিতে বিক্রি — `qty` × ১০০ টাকা। */
    private function sell(Customer $customer, int $qty): SalesInvoice
    {
        $sale = app(DirectSaleService::class)->complete(
            [
                'customer_id' => $customer->id,
                'warehouse_id' => $this->warehouse->id,
                'deposit' => '0',
            ],
            [['product_id' => $this->product->id, 'qty' => (string) $qty, 'rate' => '100']],
        );

        $invoice = $sale['invoice'];
        $this->assertContains($invoice->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিলটা খাতায় বসেনি।');

        return $invoice;
    }

    private function collect(Customer $customer, string $amount): Collection
    {
        $service = app(CollectionService::class);

        $collection = $service->confirm($service->create([
            'customer_id' => $customer->id,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
        ], []));

        $this->assertContains($collection->fresh()->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — আদায়টা খাতায় বসেনি।');

        return $collection->fresh();
    }

    /** @return array{0: string, 1: string} */
    private function thisMonth(): array
    {
        return [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()];
    }

    /** @return array{0: Location, 1: RouteVisit} */
    private function foreignRouteAndVisit(): array
    {
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($beta->id, $beta->defaultBranch()?->id);

        $route = Location::query()->create([
            'code' => 'RT-FOREIGN',
            'name_en' => 'Foreign Route',
            'name_bn' => 'অন্যের রুট',
            'level' => Location::ROUTE,
            'is_active' => true,
        ]);

        $visit = RouteVisit::query()->create([
            'route_id' => $route->id,
            'user_id' => $this->owner->id,
            'weekday' => 6,
            'effective_from' => now()->toDateString(),
        ]);

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertSame($beta->id, (int) $route->company_id, 'প্রস্তুতিটাই ভুল — রুটটা অন্য কোম্পানির নয়।');

        return [$route, $visit];
    }

    /** ঐ একটা চাবি ছাড়া বাকি সব — প্রতিটা দাবিতে নতুন মানুষ (spatie ক্যাশ করে)। */
    private function withEverythingBut(string $key): User
    {
        $this->assertTrue(Permission::query()->where('name', $key)->exists(),
            "⛔ '{$key}' চাবিটাই নেই — module.php-এর `permissions`-এ বসানো হয়নি।");

        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        $user->givePermissionTo(Permission::query()->where('name', '<>', $key)->get());

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function postAs(string $url, array $form, User $as): TestResponse
    {
        return $this->actingAs($as)->from($url)->call('POST', $url, $form);
    }

    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('কিছুই আটকায়নি — বিপজ্জনক ইনপুট দিব্যি চলে গেল।');
    }
}
