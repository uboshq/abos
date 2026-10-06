<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\ReportRun;
use App\Models\User;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerMetrics;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DealerOwnership;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বিক্রয়কর্মী কেবল নিজের ডিলার দেখেন — ⛔১৬, ২ অক্টোবর ২০২৬।
 *
 * ⓘ মালিকের সিদ্ধান্ত (ক), ২৬ সেপ্টেম্বর ২০২৬, আর §ছ-এর উত্তর: বাঁধনহীন ডিলার অদৃশ্য; বিক্রয়কর্মী
 * কেবল অর্ডার দেন; উপরের স্তর নিচের গাছ দেখে; হাতবদলে নতুনজন পুরনো বিলসহ; নির্ধারিত রিপোর্টে
 * প্রত্যেকে নিজেরটা; সুইচ ডিফল্ট বন্ধ, ডেমোতে চালু (৪ অক্টোবর ২০২৬)। কাউন্টার, ম্যানেজার আর সুপার অ্যাডমিন সব দেখেন।
 *
 * ⭐ প্রতিটা দাবি **একই মানুষ দুইবার** — `sales@abos.test`, চিহ্ন (`customer.dealers.own`) ছাড়া
 * আর চিহ্নসহ, বা বাঁধন ছাড়া আর বাঁধনসহ ([[a-door-claim-needs-one-actor-twice]])। দুইজন আলাদা
 * মানুষে তফাতটা অনুমতি বা সদস্যপদ থেকেও আসতে পারত।
 *
 * ⓘ তিনজন ডিলার: **নিজের** (রহিম, এই বিক্রয়কর্মীর নামে), **অন্যের** (করিম, আরেক বিক্রয়কর্মীর
 * নামে), **বাঁধনহীন** (বিসমিল্লাহ, কারো নামে নয়)।
 */
final class ASalesmanSeesOnlyHisOwnDealersTest extends TestCase
{
    use RefreshDatabase;

    private const MINE = 'Rahim Traders';

    private const THEIRS = 'Karim Stores';

    private const LOOSE = 'Bismillah Enterprise';

    private Company $company;

    private User $owner;

    private User $sales;

    private User $other;

    private Customer $mine;

    private Customer $theirs;

    private Customer $loose;

    private SalesInvoice $myBill;

    private SalesInvoice $theirBill;

    private SalesOrder $theirOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->sales->forceFill(['locale' => 'en'])->save();
        $this->owner->forceFill(['locale' => 'en'])->save();

        $this->other = $this->salesman('other-sr@abos.test');

        $this->actingAs($this->owner);

        $this->mine = Customer::query()->where('name_en', self::MINE)->firstOrFail();
        $this->theirs = Customer::query()->where('name_en', self::THEIRS)->firstOrFail();
        $this->loose = Customer::query()->where('name_en', self::LOOSE)->firstOrFail();

        $opening = app(OpeningBalanceService::class);
        $opening->forReceivable('customer', (int) $this->mine->id, 'OB-MINE', '1000.0000', now()->toDateString());
        $opening->forReceivable('customer', (int) $this->theirs->id, 'OB-THEIRS', '7000.0000', now()->toDateString());
        $opening->forReceivable('customer', (int) $this->loose->id, 'OB-LOOSE', '300.0000', now()->toDateString());

        $this->myBill = $this->bill('DW-INV-MINE', $this->mine, '100.0000');
        $this->theirBill = $this->bill('DW-INV-THEIRS', $this->theirs, '900.0000');
        $this->theirOrder = $this->order('DW-SO-THEIRS', $this->theirs);

        /*
         * ⓘ ডেমো এখন বিক্রয়কর্মীকে চিহ্ন আর প্রায় সব ডিলারের বাঁধন দেয় (মালিকের উত্তর "ক", ৩ অক্টোবর
         * ২০২৬)। এই দাবিগুলো নিজের ছক বানায়: ডেমোর বাঁধন মোছা, আর শুরু চিহ্ন ছাড়া — "চিহ্ন ছাড়া/চিহ্নসহ"
         * তুলনাটা তাহলেই একই মানুষের দুই অবস্থা।
         */
        \App\Modules\Customer\Models\DealerBinding::query()->withoutGlobalScopes()->where('user_id', $this->sales->id)->delete();

        $bindings = app(DealerBindingService::class);
        $bindings->bind((int) $this->sales->id, [(int) $this->mine->id], now()->toDateString());
        $bindings->bind((int) $this->other->id, [(int) $this->theirs->id], now()->toDateString());

        Auth::logout();
        $this->unwall();
    }

    // ── তালিকা, খোঁজা, ঠিকানা ───────────────────────────────────────────────

    /** ⭐ একই বিক্রয়কর্মী: চিহ্ন ছাড়া তিনজনই; চিহ্নসহ কেবল নিজের — অন্যের আর বাঁধনহীন দুইজনই নেই। */
    public function test_the_dealer_list_shows_only_his_own_dealer_once_he_is_walled(): void
    {
        $this->web($this->sales)->get(route('customer.index'))->assertOk()
            ->assertSee(self::MINE)->assertSee(self::THEIRS)->assertSee(self::LOOSE);

        $this->wall();

        $this->web($this->sales)->get(route('customer.index'))->assertOk()
            ->assertSee(self::MINE)
            ->assertDontSee(self::THEIRS)
            ->assertDontSee(self::LOOSE);
    }

    /** ⛔ অন্যের ডিলার, তাঁর বিল আর আদেশ — ঠিকানা জেনেও ৪০৪; নিজেরটা খোলে; চিহ্ন তুললে আবার খোলে। */
    public function test_the_other_dealer_and_his_papers_are_not_found_by_address(): void
    {
        $this->wall();

        $this->web($this->sales)->get(route('customer.show', $this->mine))->assertOk();
        $this->web($this->sales)->get(route('sales.invoice.show', $this->myBill))->assertOk();

        $this->web($this->sales)->get(route('customer.show', $this->theirs))->assertNotFound();
        $this->web($this->sales)->get(route('customer.show', $this->loose))->assertNotFound();
        $this->web($this->sales)->get(route('sales.invoice.show', $this->theirBill))->assertNotFound();
        $this->web($this->sales)->get(route('sales.order.show', $this->theirOrder))->assertNotFound();

        $this->unwall();

        $this->web($this->sales)->get(route('customer.show', $this->theirs))->assertOk();
        $this->web($this->sales)->get(route('sales.invoice.show', $this->theirBill))->assertOk();
    }

    /** ⛔ সর্বজনীন খোঁজায় অন্যের ডিলার আর তাঁর বিল নেই। */
    public function test_the_global_search_finds_his_dealer_but_not_the_other(): void
    {
        $this->wall();

        $mine = json_encode($this->web($this->sales)->getJson(route('search', ['q' => 'Rahim']))->assertOk()->json('hits'));
        $theirs = json_encode($this->web($this->sales)->getJson(route('search', ['q' => 'Karim']))->assertOk()->json('hits'));
        $bill = json_encode($this->web($this->sales)->getJson(route('search', ['q' => 'DW-INV-THEIRS']))->assertOk()->json('hits'));

        $this->assertStringContainsString(self::MINE, (string) $mine, 'নিজের ডিলারই খোঁজায় নেই — দাবিটা কিছু মাপছে না।');
        $this->assertStringNotContainsString(self::THEIRS, (string) $theirs, '⛔ খোঁজায় অন্যের ডিলার এসেছে।');
        $this->assertStringNotContainsString('DW-INV-THEIRS', (string) $bill, '⛔ খোঁজায় অন্যের বিল এসেছে।');

        $this->unwall();

        $theirs = json_encode($this->web($this->sales)->getJson(route('search', ['q' => 'Karim']))->assertOk()->json('hits'));
        $this->assertStringContainsString(self::THEIRS, (string) $theirs);
    }

    // ── রিপোর্ট — অঙ্ক মিলিয়ে, কেবল সারি নয় ─────────────────────────────────

    /** ⭐ ডিলার ধরে বিক্রি: দেয়ালে কেবল নিজের ১০০; দেয়াল তুললে ১০০০; অন্যের নাম পর্দায় নেই। */
    public function test_the_sales_report_counts_only_his_dealer(): void
    {
        $this->wall();

        $this->assertSame('100.00', $this->total('sales.by_customer', 'total'));
        $this->web($this->sales)->get(route('sales.report.show', ['slug' => 'by-customer']))->assertOk()
            ->assertSee(self::MINE)->assertDontSee(self::THEIRS);

        $this->unwall();

        $this->assertSame('1000.00', $this->total('sales.by_customer', 'total'));
    }

    /** ⭐ বকেয়ার তালিকা আর বকেয়ার বয়স: দেয়ালে ১,০০০; দেয়াল তুললে ৮,৩০০। */
    public function test_dues_and_ageing_count_only_his_dealer(): void
    {
        $this->wall();

        $this->assertSame('1000.00', $this->total('customer.due_list', 'outstanding'));
        $this->assertSame('1000.00', $this->total('customer.ageing', 'outstanding'));

        $this->unwall();

        $this->assertSame('8300.00', $this->total('customer.due_list', 'outstanding'));
    }

    /**
     * ⛔ রিপোর্টের ডিলার-ছাঁকনিতে অন্যের ডিলার চাইলে কিছুই নয় — দেয়াল ছাঁকনির উপরে।
     * ⓘ রিপোর্ট সেন্টারের বাছাই-ঘর ([[CustomerFilter]], abos-bb-র rf প্যাচ) `Customer::query()`
     * থেকে ভরে, তাই সেখানেও কেবল নিজের ডিলার — নিচে সেই কোয়েরিটাই মাপা।
     */
    public function test_the_report_customer_filter_cannot_reach_the_other_dealer(): void
    {
        $this->wall();
        $this->actingAs($this->sales->fresh());

        /*
         * ⓘ দুই দেয়াল: ছাঁকনির উৎস ([[ReportFilters]]) অন্যের ডিলারকে "নাগালের বাইরে" বলে ফেরায়; তা না
         * থাকলেও রিপোর্টের নিজের ডিলার-দেয়াল সারিটা দিত না। যেকোনো একটায় ফেরত/শূন্য — দেখা যায় না।
         */
        try {
            $result = app(ReportEngine::class)->run('sales.monthly', ['customer_id' => $this->theirs->id, 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);
            $this->assertSame(0, $result->totalRows, '⛔ রিপোর্টের ছাঁকনি দিয়ে অন্যের ডিলারের বিক্রি দেখা গেছে।');
        } catch (\Illuminate\Validation\ValidationException) {
            // ⭐ ফেরত — ছাঁকনির উৎস অন্যের ডিলার চেনেই না
        }

        $picker = Customer::query()->inViewedBranch()->orderBy('code')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([(int) $this->mine->id], $picker, '⛔ ছাঁকনির বাছাই-ঘরে অন্যের ডিলার আছে।');

        if (class_exists('App\\Modules\\Customer\\Reports\\Filters\\CustomerFilter')) {
            $options = app('App\\Modules\\Customer\\Reports\\Filters\\CustomerFilter')->options();
            $this->assertSame([(int) $this->mine->id], array_keys($options));
        }

        $this->unwall();
        $this->actingAs($this->sales->fresh());

        $result = app(ReportEngine::class)->run('sales.monthly', ['customer_id' => $this->theirs->id, 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);
        $this->assertGreaterThan(0, $result->totalRows, 'দেয়াল ছাড়াও অন্যের ডিলারের বিক্রি নেই — দাবিটা কিছু মাপছে না।');
    }

    /** ⛔ দেয়াল না বসানো রিপোর্ট দেয়ালের মানুষকে ৪০৩ — চিহ্ন তুললে একই মানুষ পান। */
    public function test_a_report_that_does_not_lay_the_dealer_wall_is_refused_not_leaked(): void
    {
        $engine = app(ReportEngine::class);
        $engine->register(new \App\Core\Engines\Report\ReportDefinition(
            key: 'guard.forgot_the_dealer_wall',
            title: 'Forgot the dealer wall',
            query: fn (array $f) => \Illuminate\Support\Facades\DB::table('sal_invoices')->where('company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'sal_invoices.branch_id'))->select('id'),
            columns: [['key' => 'id', 'label' => 'Id', 'type' => \App\Core\Engines\Report\ReportColumn::TEXT]],
            filters: ['branch'],
        ));

        $this->wall();
        $this->actingAs($this->sales->fresh());

        try {
            $engine->run('guard.forgot_the_dealer_wall');
            $this->fail('⛔ ডিলারের দেয়াল ছাড়া রিপোর্ট বিক্রয়কর্মী পেয়ে গেছেন।');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertStringContainsString('guard.forgot_the_dealer_wall', $e->getMessage());
        }

        $this->unwall();
        $this->actingAs($this->sales->fresh());
        $this->assertSame(1, $engine->run('guard.forgot_the_dealer_wall')->page);
    }

    // ── ফোন ─────────────────────────────────────────────────────────────────

    /** ⭐ ফোনের টেনে আনা: দেয়ালে কেবল নিজের ডিলার আর তাঁর বকেয়া; দেয়াল তুললে সবাই। */
    public function test_the_phone_pulls_only_his_dealer(): void
    {
        $this->wall();

        $ids = $this->pulled('customer');
        $this->assertContains((string) $this->mine->public_id, $ids, 'নিজের ডিলারই ফোনে নামেনি — দাবিটা কিছু মাপছে না।');
        $this->assertNotContains((string) $this->theirs->public_id, $ids, '⛔ ফোনে অন্যের ডিলার নেমেছে।');
        $this->assertNotContains((string) $this->loose->public_id, $ids, '⛔ ফোনে বাঁধনহীন ডিলার নেমেছে।');

        $this->unwall();

        $this->assertContains((string) $this->theirs->public_id, $this->pulled('customer'));
    }

    // ── কেবল অর্ডার ─────────────────────────────────────────────────────────

    /** ⛔ অন্যের ডিলারের অর্ডার ফেরত, বাংলায়; নিজের ডিলারে এই আপত্তি নেই; দেয়াল তুললে অন্যেরটাতেও নেই। */
    public function test_an_order_for_the_other_dealer_is_refused_in_bangla(): void
    {
        $this->wall();
        $this->sales->forceFill(['locale' => 'bn'])->save();

        $this->web($this->sales)->post(route('sales.order.store'), ['customer_id' => $this->theirs->id])
            ->assertSessionHasErrors(['customer_id' => (string) __('customer::binding.dealer_out_of_reach', [], 'bn')]);

        $this->web($this->sales)->post(route('sales.order.store'), ['customer_id' => $this->mine->id])
            ->assertSessionDoesntHaveErrors('customer_id');

        $this->unwall();

        $this->web($this->sales)->post(route('sales.order.store'), ['customer_id' => $this->theirs->id])
            ->assertSessionDoesntHaveErrors('customer_id');
    }

    /** ⛔ বিক্রয়কর্মী অর্ডার ছাড়া কিছুই তৈরি করেন না — একই মানুষ, চিহ্ন ছাড়া পারেন। */
    public function test_a_walled_salesman_creates_nothing_but_orders(): void
    {
        $this->actingAs($this->sales->fresh());
        $this->assertTrue($this->sales->fresh()->can('sales.invoice.create'), 'চিহ্ন ছাড়াও বিল কাটার চাবি নেই — দাবিটা কিছু মাপছে না।');
        $this->assertTrue($this->sales->fresh()->can('customer.create'));

        $this->wall();
        $walled = $this->sales->fresh();

        $this->assertTrue($walled->can('sales.order.create'), '⛔ দেয়ালের ভিতরে অর্ডার দেওয়াই আটকে গেছে।');
        foreach (['sales.invoice.create', 'sales.challan.create', 'sales.collection.create', 'sales.return.create', 'customer.create', 'customer.update', 'sales.quotation.create', 'sales.order.update', 'customer.conduct.manage'] as $key) {
            $this->assertFalse($walled->can($key), "⛔ দেয়ালের ভিতরের বিক্রয়কর্মী এখনো পারেন: {$key}");
        }
        $this->assertTrue($walled->can('sales.invoice.view'), 'দেখার চাবিও কেড়ে নেওয়া হয়েছে — নিয়ম কেবল তৈরিতে।');

        $this->web($this->sales)->get(route('customer.create'))->assertForbidden();
    }

    // ── fail-closed, সুইচ, মালিক ─────────────────────────────────────────────

    /** ⛔ বাঁধনহীন বিক্রয়কর্মী কিছুই দেখেন না; সুইচ বন্ধ করলে একই মানুষ সব দেখেন। */
    public function test_a_salesman_with_no_binding_sees_nothing_and_the_switch_off_restores_everything(): void
    {
        $this->wall();
        $binding = \App\Modules\Customer\Models\DealerBinding::query()->withoutGlobalScopes()->where('user_id', $this->sales->id)->firstOrFail();
        $binding->forceFill(['ends_on' => now()->subDay()->toDateString(), 'starts_on' => now()->subDays(5)->toDateString()])->save();
        $this->forget();

        $this->web($this->sales)->get(route('customer.index'))->assertOk()
            ->assertDontSee(self::MINE)->assertDontSee(self::THEIRS)->assertDontSee(self::LOOSE);

        $this->assertFalse(app(SettingsService::class)->definitions()[DealerScope::SWITCH]['default'],
            '⛔ সুইচের ডিফল্ট বন্ধ থাকার কথা — বাঁধনের আগে লাইভে কিছু বদলাবে না (৪ অক্টোবর ২০২৬)।');
        $this->assertTrue(app(SettingsService::class)->get(DealerScope::SWITCH), 'ডেমো কোম্পানিতে সুইচ চালু থাকার কথা — দাবিটা কিছু মাপছে না।');
        app(SettingsService::class)->set(DealerScope::SWITCH, false);
        $this->forget();

        $this->web($this->sales)->get(route('customer.index'))->assertOk()
            ->assertSee(self::MINE)->assertSee(self::THEIRS)->assertSee(self::LOOSE);
    }

    /**
     * ⭐ মালিক কোথাও দেয়ালে নন — তালিকা, ঠিকানা, রিপোর্ট, খোঁজা, বাঁধার পর্দা, বিল কাটার চাবি।
     * ⛔ এমনকি সুপার অ্যাডমিনের রোল থেকে "সব ডিলার" চাবি তুলে নিলেও (দুই তালা) — আগে একটা ৪০৩-দেয়াল
     * মালিককে বাইরে রেখেছিল।
     */
    public function test_the_owner_is_never_walled_anywhere(): void
    {
        $this->wall();

        $check = function (): void {
            $this->web($this->owner)->get(route('customer.index'))->assertOk()
                ->assertSee(self::MINE)->assertSee(self::THEIRS)->assertSee(self::LOOSE);
            $this->web($this->owner)->get(route('customer.show', $this->theirs))->assertOk();
            $this->web($this->owner)->get(route('sales.invoice.show', $this->theirBill))->assertOk();
            $this->web($this->owner)->get(route('sales.order.show', $this->theirOrder))->assertOk();
            $this->web($this->owner)->get(route('customer.binding.index'))->assertOk();
            $this->actingAs($this->owner->fresh());
            $this->assertSame('1000.00', $this->total('sales.by_customer', 'total', $this->owner));
            $this->assertTrue($this->owner->fresh()->can('sales.invoice.create'));
            $this->assertFalse(app(DealerScope::class)->walled($this->owner->fresh()));
        };

        $check();

        CompanyContext::forCompany($this->company->id, fn () => Role::findByName('super_admin', 'web')->revokePermissionTo(DealerScope::ALL));
        $this->forget();

        $check();
    }

    // ── গাছ, হাতবদল ─────────────────────────────────────────────────────────

    /** ⭐ উপরওয়ালা নিচের লোকের ডিলার দেখেন — একই উপরওয়ালা, নিচে কেউ না থাকলে কিছুই নয়। */
    public function test_a_supervisor_sees_the_dealers_of_everyone_under_him(): void
    {
        $this->wall();
        $boss = $this->salesman('tsm@abos.test');

        $this->web($boss)->get(route('customer.index'))->assertOk()->assertDontSee(self::MINE);

        $this->actingAs($this->owner);
        app(DealerBindingService::class)->setSupervisor((int) $this->sales->id, (int) $boss->id);
        $this->forget();

        $this->web($boss)->get(route('customer.index'))->assertOk()
            ->assertSee(self::MINE)->assertDontSee(self::THEIRS)->assertDontSee(self::LOOSE);
    }

    /**
     * ⭐ হাতবদল: নতুনজন পুরনো বিলসহ দেখেন, পুরনোজন আর দেখেন না — দুইজনই একই মানুষ আগে-পরে।
     * ⓘ কার বিক্রি (আদেশের লেখক) বদলায় না।
     */
    public function test_a_handover_moves_the_dealer_with_his_old_bills(): void
    {
        $this->wall();

        $this->web($this->sales)->get(route('sales.invoice.show', $this->theirBill))->assertNotFound();
        $this->web($this->other)->get(route('sales.invoice.show', $this->theirBill))->assertOk();
        $writer = $this->theirOrder->created_by;

        $this->actingAs($this->owner);
        app(DealerBindingService::class)->handover((int) $this->other->id, (int) $this->sales->id, null, now()->toDateString());
        $this->forget();

        $this->web($this->sales)->get(route('sales.invoice.show', $this->theirBill))->assertOk();
        $this->web($this->other)->get(route('sales.invoice.show', $this->theirBill))->assertNotFound();
        $this->assertSame($writer, SalesOrder::query()->withoutGlobalScopes()->find($this->theirOrder->id)?->created_by);
    }

    // ── নির্ধারিত রিপোর্ট ──────────────────────────────────────────────────

    /** ⭐ প্রাপক বিক্রয়কর্মী নিজের আলাদা ফাইল পান, কেবল নিজের ডিলার; চিহ্ন ছাড়া ভাগের ফাইলেই থাকেন। */
    public function test_a_scheduled_report_gives_the_salesman_only_his_own_part(): void
    {
        Storage::fake('local');
        $this->wall();

        $this->actingAs($this->owner);
        $schedule = app(ScheduleService::class)->create([
            'report_key' => 'sales.by_customer',
            'frequency' => 'daily',
            'format' => 'csv',
            'filters' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
            'recipients' => [$this->sales->id],
        ]);
        Auth::logout();
        $this->forget();

        app(ScheduledReportRunner::class)->runOne($schedule->fresh());

        $runs = ReportRun::query()->withoutGlobalScopes()->where('report_schedule_id', $schedule->id)->get();
        $own = $runs->first(fn (ReportRun $run) => array_map('intval', (array) $run->recipients) === [(int) $this->sales->id]);
        $this->assertNotNull($own, '⛔ বিক্রয়কর্মীর নিজের ফাইল হয়নি।');
        $text = (string) Storage::disk('local')->get((string) $own->file_path);
        $this->assertTrue(str_contains($text, self::MINE) || str_contains($text, (string) $this->mine->name_bn),
            'নিজের ডিলারই ফাইলে নেই — দাবিটা কিছু মাপছে না।');
        $this->assertStringNotContainsString(self::THEIRS, $text, '⛔ বিক্রয়কর্মীর ফাইলে অন্যের ডিলার।');
        $this->assertStringNotContainsString((string) $this->theirs->name_bn, $text, '⛔ বিক্রয়কর্মীর ফাইলে অন্যের ডিলার।');

        foreach ($runs as $run) {
            if ($run->id !== $own->id) {
                $this->assertNotContains((int) $this->sales->id, array_map('intval', (array) $run->recipients),
                    '⛔ বিক্রয়কর্মী ভাগের (মালিকের চোখের) ফাইলও নামাতে পারেন।');
            }
        }

        $this->unwall();
        ReportRun::query()->withoutGlobalScopes()->delete();
        app(ScheduledReportRunner::class)->runOne($schedule->fresh());

        $this->assertSame(1, ReportRun::query()->withoutGlobalScopes()->where('report_schedule_id', $schedule->id)->count());
    }

    // ── বাঁধার পর্দা ───────────────────────────────────────────────────────

    /** ⭐ আগাম দেখা: বাঁধনহীন দেয়ালের মানুষ লাল; বাঁধলে একই মানুষ আর লাল নন। */
    public function test_the_binding_screen_marks_a_salesman_who_would_see_nothing(): void
    {
        $this->wall();
        $fresh = $this->salesman('new-sr@abos.test');

        $this->web($this->owner)->get(route('customer.binding.index'))->assertOk()
            ->assertSee('data-staff="'.$fresh->id.'" data-blind="1"', false);

        $this->actingAs($this->owner);
        app(DealerBindingService::class)->bind((int) $fresh->id, [(int) $this->loose->id], now()->toDateString());
        $this->forget();

        $this->web($this->owner)->get(route('customer.binding.index'))->assertOk()
            ->assertSee('data-staff="'.$fresh->id.'" data-blind="0"', false);

        $this->web($this->sales)->get(route('customer.binding.index'))->assertForbidden();
    }

    /** ⭐ হোম পর্দার বকেয়া: দেয়ালে কেবল নিজের ডিলার। */
    public function test_the_dues_figure_counts_only_his_dealer(): void
    {
        $this->wall();
        $this->actingAs($this->sales->fresh());
        $this->assertSame('1000.0000', app(CustomerMetrics::class)->dues($this->sales->fresh(), now()->toDateString())['amount']);

        $this->unwall();
        $this->actingAs($this->sales->fresh());
        $this->assertSame('8300.0000', app(CustomerMetrics::class)->dues($this->sales->fresh(), now()->toDateString())['amount']);
    }

    // ── abos-2c-র দরজা: ডিলারের খবর কার কাছে ──────────────────────────────

    /**
     * ⭐ `DealerOwnership::peopleFor()` — আজ বাঁধা বিক্রয়কর্মী আর তাঁর উপরের প্রত্যেকে; বাঁধনহীন
     * ডিলারে খালি; অন্য কোম্পানির মানুষ কখনো নয়, বাঁধন বা গাছে ভুল করে বসে থাকলেও।
     */
    public function test_the_people_behind_a_dealer_are_his_salesman_and_everyone_above(): void
    {
        $this->actingAs($this->owner);
        $tsm = $this->salesman('tsm-up@abos.test');
        $rsm = $this->salesman('rsm-up@abos.test');
        $service = app(DealerBindingService::class);
        $service->setSupervisor((int) $this->sales->id, (int) $tsm->id);
        $service->setSupervisor((int) $tsm->id, (int) $rsm->id);

        $people = app(DealerOwnership::class)->peopleFor($this->mine->fresh());
        $this->assertEqualsCanonicalizing([(int) $this->sales->id, (int) $tsm->id, (int) $rsm->id], $people->all());
        $this->assertSame($people->unique()->count(), $people->count());

        $this->assertSame([], app(DealerOwnership::class)->peopleFor($this->loose->fresh())->all(), 'বাঁধনহীন ডিলারে কেউ নেই।');

        // ⛔ অন্য কোম্পানির মানুষ — ভুল করে বাঁধা আর উপরে বসানো, তবু আসেন না
        $stranger = User::factory()->create(['email' => 'stranger@abos.test', 'is_active' => true]);
        \Illuminate\Support\Facades\DB::table('dealer_bindings')->insert([
            'public_id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id,
            'user_id' => $stranger->id, 'customer_id' => $this->mine->id, 'starts_on' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('staff_supervisors')->where('user_id', $rsm->id)->delete();
        \Illuminate\Support\Facades\DB::table('staff_supervisors')->insert([
            'public_id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id,
            'user_id' => $rsm->id, 'supervisor_id' => $stranger->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $people = app(DealerOwnership::class)->peopleFor($this->mine->fresh());
        $this->assertNotContains((int) $stranger->id, $people->all(), '⛔ অন্য কোম্পানির মানুষ ডিলারের খবর পেতেন।');
        $this->assertContains((int) $rsm->id, $people->all());
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────────

    private function salesman(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'current_company_id' => $this->company->id,
            'is_active' => true,
            'locale' => 'en',
        ]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->assignRole('salesman'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** ⭐ চিহ্ন দেওয়া — রোলেই, যেমন লাইভে দেওয়া হবে। */
    private function wall(): void
    {
        CompanyContext::forCompany($this->company->id, fn () => Role::findByName('salesman', 'web')
            ->givePermissionTo(Permission::findOrCreate(DealerScope::OWN, 'web')));
        $this->forget();
    }

    private function unwall(): void
    {
        CompanyContext::forCompany($this->company->id, fn () => Role::findByName('salesman', 'web')
            ->revokePermissionTo(DealerScope::OWN));
        $this->forget();
    }

    private function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();
        app(\App\Core\Services\PermissionOverrides::class)->forget();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function web(User $user): self
    {
        $this->app['auth']->forgetGuards();
        $this->forget();

        return $this->actingAs($user->fresh());
    }

    /** একটা রিপোর্টের যোগফল, দুই দশমিকে — ঐ মানুষের চোখে। */
    private function total(string $key, string $column, ?User $as = null): string
    {
        $this->forget();
        $this->actingAs(($as ?? $this->sales)->fresh());

        $result = app(ReportEngine::class)->run($key, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);

        return bcadd((string) ($result->totals[$column] ?? '0'), '0', 2);
    }

    /** @return list<string> ফোনে নামা গ্রাহকের public_id */
    private function pulled(string $module): array
    {
        $this->forget();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sales->fresh(), [AuthController::ACCESS]);

        $records = $this->getJson('/api/v1/sync/'.$module.'/pull?deviceId=dw-phone-1')->assertOk()->json('records');

        return array_values(array_map(fn (array $r): string => (string) ($r['entityId'] ?? ''), (array) $records));
    }

    private function bill(string $no, Customer $customer, string $total): SalesInvoice
    {
        return SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => $customer->id,
            'trx_date' => now()->toDateString(),
            'subtotal' => $total,
            'total' => $total,
            'status' => 'confirmed',
        ]);
    }

    private function order(string $no, Customer $customer): SalesOrder
    {
        return SalesOrder::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => $customer->id,
            'trx_date' => now()->toDateString(),
            'total' => '50.0000',
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);
    }
}
