<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ মজুদ দেখার চাবি ছাড়া ফোনে মজুদের রিপোর্টও নয় — মালিক, ১ ও ১৩ অক্টোবর ২০২৬ ("SR মজুদ দেখেন না"; a6, ১৯ অক্টোবর ২০২৬)।
 *
 * ⓘ অ্যাপের ভিতরের ওয়েব এই সারিগুলো লুকায় ([[WebInsideApp::STOCK_SCREENS]]), কিন্তু ফোনের রিপোর্ট-তালিকা আর দরজা কেবল রিপোর্টের
 * নিজের চাবি দেখত — `inventory.report` আছে, মজুদ-দেখা নেই এমন বিক্রয়কর্মী তালিকা থেকে "মজুদের মূল্য" খুলতে পারতেন।
 *
 * দাবি: তালিকায় মজুদের কোনো রিপোর্ট নেই (মজুদ মডিউল আর নামে "stock"); দরজা আর PDF ৪০৩; মজুদ-দেখার চাবি দিলে খোলে।
 */
final class ThePhoneReportListShowedStockToASalesmanTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_the_stock_view_key_no_stock_report_is_listed_or_opened(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $rep = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id, 'is_active' => true]);
        $rep->companies()->attach($company->id, ['is_active' => true]);
        $grant = function (string $key) use ($company, $rep): void {
            CompanyContext::forCompany($company->id, fn () => $rep->givePermissionTo(Permission::findOrCreate($key, 'web')));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        };
        $grant('inventory.report');
        $grant('sales.report');

        $keys = array_column($this->phone($rep, '/api/v1/reports')->assertOk()->json(), 'key');
        $this->assertContains('sales.register', $keys, 'দাবির ভিত্তি নেই — বিক্রয়ের রিপোর্টও আসেনি');
        $stock = array_values(array_filter($keys, fn (string $k) => str_starts_with($k, 'inventory.') || str_contains($k, 'stock')));
        $this->assertSame([], $stock, '⛔ মজুদ-দেখার চাবি ছাড়া তালিকায় মজুদের রিপোর্ট');

        $this->phone($rep, '/api/v1/reports/inventory.stock_value')->assertForbidden();
        $this->phone($rep, '/api/v1/reports/inventory.stock_value/export?format=pdf')->assertForbidden();

        $grant('inventory.stock.view');
        $this->phone($rep, '/api/v1/reports/inventory.stock_value')->assertOk();
        $this->assertContains('inventory.stock_value', array_column($this->phone($rep, '/api/v1/reports')->json(), 'key'));
    }

    private function phone(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
