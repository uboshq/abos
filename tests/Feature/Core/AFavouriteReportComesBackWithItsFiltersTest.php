<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * রিপোর্টের প্রিয় — ছাঁকনিসহ রাখা, আর খুললে ছাঁকনিসহ ফেরা (রিপোর্ট সেন্টার ধাপ ১, "ছাঁকনি সংরক্ষণ / প্রিয়")।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * সংরক্ষিত দৃশ্য ([[SavedView]]) কেবল প্যারামিটারহীন পর্দা চিনত, আর প্রতিটা রিপোর্ট একটা স্লাগ (`sales.report.show` +
 * `by-customer`) — তাই রিপোর্টের পাতায় "এই দৃশ্যটা রেখে দিন" মেনুটাই আঁকা হত না, আর হাতে পাঠালে "এই পর্দাটা একটা
 * নির্দিষ্ট রেকর্ডের জন্য খোলে" বলে ফিরত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * ঠিকানা স্লাগসহ ([[ScreenAddress]]); রাখা যায়, রিপোর্ট সেন্টারে দেখা যায়, খুললে ছাঁকনি ফেরে — ⛔ আর ছাঁকনির মান
 * রিপোর্টের নিজের পথ দিয়েই যায় ([[ReportFilters::resolve()]]): প্রিয়তে অন্য কোম্পানির নম্বর থাকলে রিপোর্টই ফেরে।
 */
final class AFavouriteReportComesBackWithItsFiltersTest extends TestCase
{
    use RefreshDatabase;

    private const SCREEN = 'inventory.report.show:stock-position';

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** ⭐ রিপোর্টের পাতায় রাখার মেনু আছে, আর সে স্লাগসহ ঠিকানাই পাঠায়। */
    public function test_a_report_page_offers_to_keep_its_filters(): void
    {
        $page = $this->get(route('sales.report.show', ['by-customer', 'top' => 10]))->assertOk();

        $page->assertSee('data-view-menu', false);
        $page->assertSee('name="screen" value="sales.report.show:by-customer"', false);
        $page->assertSee('name="query" value="top=10"', false);
    }

    /** ⭐ রাখা যায়, আর তার ঠিকানায় ছাঁকনিগুলো ফেরে — পাতাটা খোলে। */
    public function test_a_kept_report_opens_with_its_filters(): void
    {
        $mine = (int) Warehouse::query()->where('code', 'WH-MMS')->value('id');

        $this->post(route('views.store'), [
            'screen' => self::SCREEN,
            'name' => 'ময়মনসিংহের মজুদ',
            'query' => 'warehouse_id='.$mine,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $view = SavedView::query()->where('name', 'ময়মনসিংহের মজুদ')->firstOrFail();

        $this->assertSame(self::SCREEN, $view->screen);
        $this->assertSame(route('inventory.report.show', 'stock-position').'?warehouse_id='.$mine, $view->url(),
            '⛔ প্রিয়টা তার রিপোর্টে ছাঁকনিসহ ফেরে না।');

        $this->get($view->url())->assertOk()->assertSee('WH-MMS · ', false);
    }

    /** ⛔ প্রিয়তে অন্য কোম্পানির গুদামের নম্বর — খুললে রিপোর্টই ফেরে, গোটা তালিকা নয়। */
    public function test_a_favourite_holding_another_companys_number_is_refused_when_opened(): void
    {
        $theirs = $this->warehouseOfAnotherCompany();

        $view = SavedView::query()->create([
            'user_id' => $this->owner->id,
            'screen' => self::SCREEN,
            'name' => 'অন্যের গুদাম',
            'query' => 'warehouse_id='.$theirs,
        ]);

        $this->get($view->url())
            ->assertRedirect()
            ->assertSessionHasErrors('warehouse_id');
    }

    /** ⛔ রেকর্ডের পাতা, স্লাগহীন রিপোর্ট, বা খোলা যায় না এমন রুট — কোনোটাই প্রিয় হয় না। */
    public function test_only_a_report_or_a_plain_screen_can_be_kept(): void
    {
        foreach (['sales.report.show', 'views.destroy:1', 'sales.report.show:Bad Slug!'] as $screen) {
            $this->post(route('views.store'), ['screen' => $screen, 'name' => 'ভুল '.$screen, 'query' => ''])
                ->assertSessionHasErrors('screen');
        }

        $this->assertSame(0, SavedView::query()->count(), '⛔ খোলা যায় না এমন ঠিকানা প্রিয় হিসেবে রাখা হলো।');
    }

    /** ⭐ রিপোর্ট সেন্টারে নিজের প্রিয় — ⛔ অন্যের নয়, অন্য কোম্পানির নয়। */
    public function test_the_center_lists_only_my_favourites_in_this_company(): void
    {
        SavedView::query()->create(['user_id' => $this->owner->id, 'screen' => 'sales.report.show:by-customer',
            'name' => 'আমার সেরা দোকান', 'query' => 'top=10']);

        $other = User::query()->where('id', '!=', $this->owner->id)->firstOrFail();
        SavedView::query()->create(['user_id' => $other->id, 'screen' => 'sales.report.show:by-customer',
            'name' => 'অন্যজনের প্রিয়', 'query' => '']);

        $fmart = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::forCompany($fmart->id, fn () => SavedView::query()->create(['user_id' => $this->owner->id,
            'screen' => 'sales.report.show:by-customer', 'name' => 'অন্য কোম্পানির প্রিয়', 'query' => '']));

        $page = $this->get(route('reports.center'))->assertOk();

        $page->assertSee('আমার সেরা দোকান');
        $page->assertSee(e(route('sales.report.show', 'by-customer').'?top=10'), false);
        $page->assertDontSee('অন্যজনের প্রিয়');
        $page->assertDontSee('অন্য কোম্পানির প্রিয়');
    }

    /** ⛔ একই মানুষ: রিপোর্টের চাবি নেই — প্রিয়টাও নেই; চাবি দিলে ফেরে। */
    public function test_a_favourite_follows_the_key_of_its_report(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        SavedView::query()->create(['user_id' => $clerk->id, 'screen' => 'sales.report.show:by-customer',
            'name' => 'কেরানির প্রিয়', 'query' => '']);

        $this->actingAs($clerk->fresh())->get(route('reports.center'))->assertOk()
            ->assertDontSee('কেরানির প্রিয়');

        $clerk->givePermissionTo('sales.report');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk->fresh())->get(route('reports.center'))->assertOk()
            ->assertSee('কেরানির প্রিয়');
    }

    private function warehouseOfAnotherCompany(): int
    {
        $fmart = Company::query()->where('code', 'FMART')->firstOrFail();

        return CompanyContext::forCompany($fmart->id, fn () => (int) app(WarehouseService::class)->create([
            'code' => 'WH-FM',
            'name_en' => 'Fmart Store',
            'name_bn' => 'এফমার্ট গুদাম',
            'branch_id' => Branch::query()->where('company_id', $fmart->id)->value('id'),
        ])->id);
    }
}
