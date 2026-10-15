<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Models\BranchModule;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInPastTheSecondStep;
use Tests\TestCase;

/**
 * শাখায় বন্ধ মডিউল ফোনেও বন্ধ — ওয়েবের মতোই।
 *
 * ── ⛔ কী ভেঙেছিল (ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬) ──────────
 * [[PhoneModules::isOn()]] কোম্পানির সুইচ আর ফোনের সুইচ পড়ত, শাখার সারি
 * ([[BranchModule]]) নয়। "ডিপো ২"-তে একটা মডিউল বন্ধ, অথচ সেখানকার মানুষ
 * ফোন থেকে তার সিঙ্কে টানতে-পাঠাতে পারতেন, আর `/me` তাকে চালু বলত —
 * ওয়েবে একই কাজ ৪০৪।
 */
final class ThePhoneIgnoredTheBranchSwitchTest extends TestCase
{
    use RefreshDatabase;
    use SignsInPastTheSecondStep;

    private const DEVICE = 'handset-branch-switch';

    private string $token;

    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = (int) $company->defaultBranch()?->id;

        User::query()->where('email', 'owner@abos.test')->firstOrFail()
            ->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $this->branch])->save();

        $this->app['auth']->forgetGuards();
        $this->token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@abos.test',
            'password' => 'password',
            'code' => $this->secondStepCode('owner@abos.test'),
            'deviceId' => self::DEVICE,
            'appVersion' => '0.4.2',
            'platform' => 'android',
        ])->assertOk()->json('accessToken');
    }

    private function phone(string $method, string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->json($method, $uri);
    }

    public function test_a_module_switched_off_in_the_branch_is_off_on_the_phone_too(): void
    {
        $pull = '/api/v1/sync/hr/pull?deviceId='.self::DEVICE;

        $this->assertContains('hr', $this->phone('GET', '/api/v1/me')->assertOk()->json('phoneModules'),
            'পরীক্ষার আগেই HR ফোনে বন্ধ।');
        $this->phone('GET', $pull)->assertOk();

        BranchModule::query()->create([
            'company_id' => Company::query()->where('code', 'TDEPOT')->value('id'),
            'branch_id' => $this->branch,
            'module' => 'hr',
            'is_enabled' => false,
        ]);

        $this->assertNotContains('hr', $this->phone('GET', '/api/v1/me')->assertOk()->json('phoneModules'),
            'শাখায় HR বন্ধ, তবু ফোন তাকে চালু বলছে।');

        $this->phone('GET', $pull)
            ->assertForbidden()
            ->assertJsonPath('reason', PhoneModules::REASON)
            ->assertJsonPath('module', 'hr');
    }
}
