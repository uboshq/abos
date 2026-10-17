<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Dashboard\SalesOverview;
use App\Modules\Sales\Metrics\SalesPeriod;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ "বিক্রয়ের হালচাল" ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*।
 *
 * ⓘ দাবি: ফোনের দরজা ([[SalesOverviewApiController]]) ওয়েবের পাতার একই সেবার একই সংখ্যা পাঠায়, মডিউলের ড্যাশবোর্ডের আকারে
 * (stats · panels · listings); চাবি `sales.report` ছাড়া ৪০৩; ভুল তারিখের পরিসর এই মাসে ফেরে আর কারণটা বলে।
 */
final class TheSalesOverviewOpensAsAPhoneScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_phone_gets_the_web_pages_own_cards_in_the_dashboard_shape(): void
    {
        $web = app(SalesOverview::class)->for($this->owner, SalesPeriod::preset(SalesPeriod::MONTH));
        $this->assertNotSame([], $web['cards'], 'পরীক্ষার ছকটাই ভুল: মালিকের কোনো কার্ড নেই।');

        $this->phone($this->owner);
        $answer = $this->getJson('/api/v1/sales/overview?period=month')->assertOk();

        $this->assertSame('month', $answer->json('period.kind'));
        $this->assertNull($answer->json('period.refused'));
        $this->assertSame(
            array_map(fn (array $c) => [(string) $c['label'], (string) $c['value']], $web['cards']),
            array_map(fn (array $s) => [$s['label'], $s['value']], $answer->json('stats')),
            '⛔ ফোনের কার্ড ওয়েবের পাতার কার্ড নয় — আলাদা হিসাব, বা বাদ পড়েছে।',
        );

        foreach ($answer->json('panels') as $panel) {
            $this->assertContains($panel['kind'], ['series', 'breakdown'], 'ফোন এই ধরনের চার্ট আঁকতে পারে না।');
        }
        foreach ($answer->json('listings') as $listing) {
            $this->assertNotEmpty($listing['columns']);
            foreach ($listing['rows'] as $row) {
                $this->assertCount(count($listing['columns']), $row, '⛔ সারির ঘর কলামের সাথে মেলে না।');
            }
        }
        $this->assertContains(__('sales::overview.panel.top_customers'), array_column($answer->json('listings'), 'label'));
    }

    public function test_a_bad_range_falls_back_to_this_month_and_says_why(): void
    {
        $this->phone($this->owner);
        $answer = $this->getJson('/api/v1/sales/overview?period=custom&from=2026-10-10&to=2026-10-01')->assertOk();

        $this->assertSame('month', $answer->json('period.kind'));
        $this->assertNotNull($answer->json('period.refused'), '⛔ ভুল পরিসর নীরবে বদলে গেল — কারণ নেই।');
    }

    public function test_someone_without_the_sales_report_key_is_refused(): void
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $clerk->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->owner->current_company_id, fn () => $clerk->givePermissionTo(
            Permission::findOrCreate('sales.order.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->phone($clerk);
        $this->getJson('/api/v1/sales/overview')->assertForbidden();
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }
}
