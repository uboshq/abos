<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মালিকের কেন্দ্র আর বাকি নিয়ন্ত্রণ ফোনে নিজে থেকেই চালু — সমন্বয়কের সিদ্ধান্ত, ১৩ অক্টোবর ২০২৬ (মালিক: "app e baki module & menu")।
 *
 * দাবি: যে কোম্পানিতে কেউ সুইচ ছোঁয়নি, সেখানে চাবিওয়ালা দুই মডিউলের মেনু আর দরজা পান; চাবি ছাড়া মানুষ মেনুতে কিছুই পান
 * না আর দরজায় ৪০৩ (সুইচ চালু মানে কেবল "দেখানো যায়", দরজা চাবিতেই); কোম্পানি সুইচ বন্ধ করলে চাবিওয়ালাও পান না।
 */
final class TheOwnersCentreAndCreditDeskAreOnThePhoneByDefaultTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_a_fresh_company_shows_both_to_the_keyholder_and_hides_them_from_anyone_else(): void
    {
        $this->assertSame(0, Setting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereIn('key', [PhoneModules::key('executive'), PhoneModules::key('credit_control')])->count(),
            'দাবির ভিত্তি নেই — কোম্পানিতে আগেই সুইচের সারি আছে, ডিফল্ট যাচাই হয় না।');

        $keyholder = $this->staff(['executive.view', 'credit_control.view']);
        $me = $this->phone($keyholder, '/api/v1/me')->assertOk()->json();

        $this->assertContains('executive', $me['phoneModules'], '⛔ নতুন কোম্পানিতে মালিকের কেন্দ্র ফোনে বন্ধ।');
        $this->assertContains('credit_control', $me['phoneModules'], '⛔ নতুন কোম্পানিতে বাকি নিয়ন্ত্রণ ফোনে বন্ধ।');
        $this->assertContains('executive', array_column($me['menu'], 'code'));
        $this->assertContains('credit_control', array_column($me['menu'], 'code'));
        $this->phone($keyholder, '/api/v1/executive/today')->assertOk();
        $this->phone($keyholder, '/api/v1/credit-control/dashboard')->assertOk();

        $plain = $this->staff(['customer.view']);
        $menu = array_column($this->phone($plain, '/api/v1/me')->assertOk()->json('menu'), 'code');

        $this->assertNotContains('executive', $menu, '⛔ চাবি ছাড়াই মালিকের কেন্দ্র মেনুতে।');
        $this->assertNotContains('credit_control', $menu, '⛔ চাবি ছাড়াই বাকি নিয়ন্ত্রণ মেনুতে।');
        $this->phone($plain, '/api/v1/executive/today')->assertForbidden();
        $this->phone($plain, '/api/v1/credit-control/dashboard')->assertForbidden();
    }

    public function test_the_company_can_still_switch_them_off(): void
    {
        app(SettingsService::class)->set(PhoneModules::key('executive'), false);
        app(SettingsService::class)->set(PhoneModules::key('credit_control'), false);

        $keyholder = $this->staff(['executive.view', 'credit_control.view']);
        $me = $this->phone($keyholder, '/api/v1/me')->assertOk()->json();

        $this->assertNotContains('executive', $me['phoneModules']);
        $this->assertNotContains('credit_control', array_column($me['menu'], 'code'));
        $this->phone($keyholder, '/api/v1/credit-control/dashboard')->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
    }

    /** @param list<string> $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function phone(User $user, string $url): TestResponse
    {
        app(SettingsService::class)->flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
