<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে মডিউলের ড্যাশবোর্ড — মালিক, ৪ অক্টোবর ২০২৬: *"egulo nadile bujbo kikore kihocche"* ([[DashboardApiController]])।
 *
 * দাবি:
 *   ফোনের সংখ্যা ওয়েবের ইঞ্জিনের হুবহু ([[DashboardEngine::for()]]) — মজুদ, বিক্রি, হিসাব;
 *   একই মানুষ — মজুদ দেখার চাবি ছাড়া ৪০৩, চাবি দিলে খোলে; খরচ দেখার চাবি ছাড়া মজুদের মূল্য ঢাকা (`hidden`), দিলে খোলা;
 *   ফোনে মডিউলটা বন্ধ থাকলে ৪০৩, এমন মডিউল নেই তো ৪০৪;
 *   তালিকায় কেবল খোলার মতো মডিউলগুলো।
 */
final class TheOwnerCouldNotSeeHisBusinessOnThePhoneTest extends TestCase
{
    use RefreshDatabase;

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
        app(StandardChart::class)->install();

        foreach (['inventory', 'sales', 'accounts'] as $code) {
            app(SettingsService::class)->set(PhoneModules::key($code), true);
        }
    }

    public function test_the_phone_shows_the_same_figures_the_web_engine_draws(): void
    {
        foreach (['inventory', 'sales', 'accounts'] as $code) {
            $web = app(DashboardEngine::class)->for($code, $this->owner);
            Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

            $phone = $this->getJson("/api/v1/dashboard/{$code}")->assertOk()->json();

            $this->assertSame($web->title, $phone['title']);
            $this->assertSame(array_map(fn ($s) => $s->label, $web->stats), array_column($phone['stats'], 'label'), "⛔ {$code}: সংখ্যার তালিকা আলাদা।");
            $this->assertSame(array_map(fn ($s) => $s->value, $web->stats), array_column($phone['stats'], 'value'), "⛔ {$code}: ফোন আর ওয়েব আলাদা অঙ্ক বলছে।");
        }
    }

    public function test_the_same_person_needs_the_key_and_the_cost_key_uncovers_the_stock_value(): void
    {
        $keeper = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $keeper->companies()->attach($this->company->id, ['is_active' => true]);

        Sanctum::actingAs($keeper->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/dashboard/inventory')->assertForbidden();

        $this->grant($keeper, 'inventory.stock.view');
        Sanctum::actingAs($keeper->fresh(), [AuthController::APP]);
        $hidden = $this->getJson('/api/v1/dashboard/inventory')->assertOk()->json('stats.0');
        $this->assertTrue($hidden['hidden'], '⛔ খরচের চাবি ছাড়াই মজুদের মূল্য খোলা।');
        $this->assertNull($hidden['value']);

        $this->grant($keeper, 'inventory.cost.view');
        Sanctum::actingAs($keeper->fresh(), [AuthController::APP]);
        $shown = $this->getJson('/api/v1/dashboard/inventory')->assertOk()->json('stats.0');
        $this->assertFalse($shown['hidden']);
        $this->assertNotNull($shown['value']);
    }

    public function test_a_module_off_on_the_phone_or_unknown_is_refused_and_the_list_names_only_open_ones(): void
    {
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/dashboard/no_such_module')->assertNotFound();

        $listed = array_column($this->getJson('/api/v1/dashboard')->assertOk()->json('modules'), 'module');
        $this->assertContains('inventory', $listed);

        app(SettingsService::class)->set(PhoneModules::key('inventory'), false);
        app()->forgetInstance(PhoneModules::class);
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        $this->getJson('/api/v1/dashboard/inventory')->assertForbidden();
        $this->assertNotContains('inventory', array_column($this->getJson('/api/v1/dashboard')->json('modules'), 'module'));
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
