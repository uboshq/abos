<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\BranchModule;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ এক শাখায় বন্ধ মডিউল ফোনে সিঙ্ক হতেই থাকত (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[PhoneModules::isOn()]], [[SyncController]])।
 *
 * ⚠️ ওয়েব শাখার সুইচ মানে, ফোন মানত কেবল কোম্পানিরটা — আর সিঙ্কের দরজা কোনো সুইচই দেখত না: বিক্রি বন্ধ শাখার বিক্রয়কর্মী
 * ফোন থেকে অর্ডার পাঠাতেন। ⭐ এখন শাখায় বন্ধ হলে পুশ আর পুল ৪০৩, কারণসহ।
 */
final class ASwitchedOffModuleStillSyncedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_module_switched_off_in_the_branch_does_not_sync(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(SettingsService::class)->set(PhoneModules::key('sales'), true);
        /*
         * ⓘ বিক্রয়কর্মী এক শাখায় বসা — শাখার সুইচ পড়া হয় তাঁর বসা শাখা থেকে (`current_branch_id`; ওয়েবেও তাই,
         * [[RefuseSwitchedOffScreens]])। ডেমোতে তিনি "সব শাখা"-য় (null), তখন কোনো এক শাখার সুইচ খাটে না।
         */
        $branchId = (int) $company->defaultBranch()?->id;
        User::query()->where('email', 'sales@abos.test')->update(['current_company_id' => $company->id, 'current_branch_id' => $branchId]);

        $this->app['auth']->forgetGuards();
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sales@abos.test', 'password' => 'password', 'deviceId' => 'branch-off-device',
            'appVersion' => '0.1.0', 'platform' => 'android',
        ])->json('accessToken');
        $this->assertNotSame('', $token, 'লগইনই হয়নি');
        $this->app['auth']->forgetGuards();

        BranchModule::query()->create(['company_id' => $company->id, 'branch_id' => $branchId, 'module' => 'sales', 'is_enabled' => false]);

        $this->withToken($token)->postJson('/api/v1/sync/sales/push?deviceId=branch-off-device', [])
            ->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
        $this->withToken($token)->getJson('/api/v1/sync/sales/pull?deviceId=branch-off-device')
            ->assertForbidden();
    }
}
