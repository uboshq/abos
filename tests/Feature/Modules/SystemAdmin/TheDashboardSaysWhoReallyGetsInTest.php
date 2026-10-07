<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\SystemAdmin\Dashboard\SystemAdminDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ব্যবহারকারীর অবস্থা — কে সত্যিই ঢোকেন (নতুন ড্যাশবোর্ড, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ প্রতিজন ঠিক একটা ভাগে; অন্য কোম্পানির মানুষ গোনায় নেই।
 * ⛔ একই মানুষ `system_admin.user.manage` ছাড়া → চার্টই নেই; চাবিসহ → আছে।
 */
final class TheDashboardSaysWhoReallyGetsInTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_user_of_this_company_lands_in_one_part_and_only_the_key_sees_it(): void
    {
        $company = Company::create(['code' => 'USR', 'name_en' => 'User Co']);
        $other = Company::create(['code' => 'OTH', 'name_en' => 'Other Co']);
        CompanyContext::set($company->id);

        $admin = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true, 'last_login_at' => now()]);
        $admin->companies()->attach($company->id);

        foreach ([
            [true, now()->subDays(3)],     // সক্রিয়
            [true, now()->subDays(45)],    // ৩০ দিন ঢোকেননি
            [true, null],                  // কখনো ঢোকেননি
            [false, now()->subDay()],      // বন্ধ — সম্প্রতি ঢুকলেও
        ] as [$active, $last]) {
            User::factory()->create(['is_active' => $active, 'last_login_at' => $last])->companies()->attach($company->id);
        }

        User::factory()->create(['is_active' => true, 'last_login_at' => null])->companies()->attach($other->id);

        config(['abos.dashboards_v2' => true]);
        $this->actingAs($admin);
        $label = __('system_admin::dashboard.who_gets_in');
        $this->assertNull(collect(SystemAdminDashboard::dashboard()->panels)->firstWhere('label', $label), '⛔ চাবি ছাড়াই ব্যবহারকারীর অবস্থা দেখা গেছে।');

        Permission::findOrCreate('system_admin.user.manage', 'web');
        CompanyContext::forCompany($company->id, fn () => $admin->givePermissionTo('system_admin.user.manage'));
        $this->actingAs($admin->fresh());

        $panel = collect(SystemAdminDashboard::dashboard()->panels)->firstWhere('label', $label);
        $this->assertNotNull($panel, 'চাবি থাকা সত্ত্বেও চার্ট নেই।');

        $this->assertSame([
            __('system_admin::dashboard.users_live') => '2',
            __('system_admin::dashboard.users_idle') => '1',
            __('system_admin::dashboard.users_never') => '1',
            __('system_admin::dashboard.users_off') => '1',
        ], array_column($panel->parts, 'value', 'label'), '⛔ ভাগ ভুল — কেউ দুই ভাগে, বন্ধ মানুষ সক্রিয়তে, বা অন্য কোম্পানির কেউ গোনায়।');
    }
}
