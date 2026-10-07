<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ ফোনের ড্যাশবোর্ড সুইচ ভুলে যেত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১০।
 *
 * আজকের পাতা মডিউলের ফোন-সুইচ দেখত না (হিসাব বন্ধেও হাতের নগদ আর দেনা যেত); আর HR-এর ড্যাশবোর্ড ফোনে HR চালু থাকলে মাসের
 * বেতন-খরচ দিত — চুক্তি §৪-এ HR ডেস্কের। এখন আজকের পাতার প্রতিটা ভাগ নিজের সুইচ মানে, আর HR-এর ড্যাশবোর্ড ফোনে কখনো নয়।
 */
final class TheDashboardForgotThePhonesSwitchesTest extends TestCase
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
    }

    public function test_today_drops_the_money_parts_when_accounts_is_off_on_the_phone_and_brings_them_back(): void
    {
        $today = $this->phone()->getJson('/api/v1/dashboard/today')->assertOk()->json();
        $this->assertArrayHasKey('cashInHand', $today, 'প্রস্তুতিটাই ভুল — মালিকের পাতায় হাতের নগদ নেই।');
        $this->assertArrayHasKey('sales', $today);

        $this->switch('accounts', false);
        $today = $this->phone()->getJson('/api/v1/dashboard/today')->assertOk()->json();
        foreach (['cashInHand', 'money', 'inflow', 'payable'] as $part) {
            $this->assertArrayNotHasKey($part, $today, "⛔ ফোনে হিসাব বন্ধ, তবু {$part} গেল।");
        }
        $this->assertArrayHasKey('sales', $today, 'বিক্রয় চালু — তার ভাগ থাকার কথা।');

        $this->switch('accounts', true);
        $this->assertArrayHasKey('cashInHand', $this->phone()->getJson('/api/v1/dashboard/today')->json());
    }

    public function test_the_hr_dashboard_never_reaches_the_phone_even_with_hr_on(): void
    {
        $this->switch('hr', true);

        $this->phone()->getJson('/api/v1/dashboard/hr')->assertForbidden();
        $modules = collect($this->phone()->getJson('/api/v1/dashboard')->assertOk()->json('modules'))->pluck('module');
        $this->assertNotContains('hr', $modules, '⛔ HR-এর ড্যাশবোর্ড ফোনের তালিকায়।');
        $this->assertNotEmpty($modules, 'প্রস্তুতিটাই ভুল — তালিকা খালি।');
    }

    private function switch(string $module, bool $on): void
    {
        app(SettingsService::class)->set(PhoneModules::key($module), $on);
        app()->forgetInstance(SettingsService::class);
        app()->forgetInstance(PhoneModules::class);
    }

    private function phone(): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this;
    }
}
