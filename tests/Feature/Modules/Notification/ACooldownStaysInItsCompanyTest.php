<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ "একই খবর এত মিনিটে আবার নয়" নিয়ম নিজের কোম্পানির খবরই গোনে — Notification লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[NotificationService]] `toldRecently`)।
 *
 * ⓘ দুই কোম্পানির একজন মানুষ: এক কোম্পানিতে একটা খবর এলে অন্য কোম্পানির নিয়মের সময়ের মধ্যে সেই কোম্পানির একই ধরনের খবর চাপা পড়ত।
 */
final class ACooldownStaysInItsCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_notice_in_one_company_does_not_silence_the_same_kind_in_another(): void
    {
        $this->seed(DemoSeeder::class);
        $depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mart = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $both = User::factory()->create(['is_active' => true, 'current_company_id' => $depot->id]);
        $both->companies()->attach($depot->id, ['is_active' => true]);
        $both->companies()->attach($mart->id, ['is_active' => true]);

        CompanyContext::forCompany($mart->id, fn () => NotificationRule::query()->create([
            'name' => 'আবার নয়', 'module' => 'approval', 'event' => 'approval.rejected', 'is_active' => true, 'version' => 1, 'cooldown_minutes' => 60,
        ]));

        CompanyContext::set($depot->id, $depot->defaultBranch()?->id);
        app(NotificationService::class)->send($both, 'approval.rejected', 'ডিপোর খবর');

        CompanyContext::set($mart->id, $mart->defaultBranch()?->id);
        app(NotificationService::class)->send($both, 'approval.rejected', 'মার্টের খবর');

        $this->assertTrue(Notification::query()->withoutGlobalScopes()->where('user_id', $both->id)->where('title', 'ডিপোর খবর')->exists(),
            'দৃশ্যটাই বানানো যায়নি — ডিপোর খবরই আসেনি');
        $this->assertTrue(Notification::query()->withoutGlobalScopes()->where('user_id', $both->id)->where('title', 'মার্টের খবর')->exists(),
            '⛔ এক কোম্পানির খবর অন্য কোম্পানির নিয়মে একই খবর চেপে দিল');
    }
}
