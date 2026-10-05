<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নতুন ড্যাশবোর্ডের সুইচ নিয়ন্ত্রণ প্যানেলে — মালিক, ৬ অক্টোবর ২০২৬: সুইচটা কেবল সার্ভারের .env-এ ছিল (ABOS_DASHBOARDS_V2)।
 *
 * দাবি — একই কোম্পানি, একই ব্যবহারকারী, .env বন্ধ:
 *  · সুইচ বন্ধ → পুরনো মডিউল ড্যাশবোর্ড।
 *  · সুইচ চালু → নতুন (dashboard.module-v2)।
 *  · আবার বন্ধ → আবার পুরনো।
 *  · .env চালু থাকলে প্যানেলের বন্ধ সেটা বন্ধ করে না।
 */
final class TheNewDashboardsHaveASwitchOnTheControlPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_control_panel_switch_opens_and_closes_the_new_dashboards(): void
    {
        config(['abos.dashboards_v2' => false]);

        $this->assertSame('dashboard.module', $this->viewName(), 'সুইচ বন্ধ, তবু নতুন ড্যাশবোর্ড।');

        app(SettingsService::class)->set('system.dashboards_v2', true);
        $this->assertSame('dashboard.module-v2', $this->viewName(), '⛔ প্যানেলে চালু, তবু নতুন ড্যাশবোর্ড খোলেনি।');

        app(SettingsService::class)->set('system.dashboards_v2', false);
        $this->assertSame('dashboard.module', $this->viewName(), '⛔ আবার বন্ধ করেও নতুন ড্যাশবোর্ড থেকে গেছে।');

        // ⓘ .env চালু থাকলে প্যানেলের বন্ধ কিছু বন্ধ করে না
        config(['abos.dashboards_v2' => true]);
        $this->assertSame('dashboard.module-v2', $this->viewName());
    }

    private function viewName(): string
    {
        $env = (bool) config('abos.dashboards_v2');
        app(SettingsService::class)->flush();
        $response = $this->get('/dashboard/sales');
        $response->assertOk();
        $name = $response->original->name();
        // ⓘ মিডলওয়্যার এক অনুরোধে config বদলায় — পরের অনুরোধ .env-এর মান থেকে শুরু হোক, যেমন আসল সার্ভারে
        config(['abos.dashboards_v2' => $env]);

        return $name;
    }
}
