<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ব্যবহারকারীর তালিকায় ভূমিকার চিপ পর্দার বাইরে চলে যেত — মালিক, ২ অক্টোবর ২০২৬ (ছবি: "eta tik koro")।
 *
 * ⛔ "demo user"-এর এগারোটা ভূমিকা এক লাইনে ডানে বেরিয়ে যেত — শেষেরগুলো কাটা, আর পাশের "কোম্পানি" কলাম পর্দার
 * বাইরে। ⓘ টেবিলের ঘর লাইন ভাঙে না। ⭐ এখন চিপগুলো একটা ভাঙা-যোগ্য ঘরে (`flex-wrap`, `white-space: normal`,
 * সর্বোচ্চ চওড়া বাঁধা) — সবগুলো চিপ পাতায় থাকে, পরের লাইনে নেমে।
 */
final class TheRoleChipsRanOffTheScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_many_roles_wrap_inside_a_bounded_cell(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $busy = User::query()->where('email', '!=', 'owner@abos.test')
            ->whereHas('companies', fn ($q) => $q->whereKey($company->id))->firstOrFail();
        $roles = Role::query()->limit(8)->get();
        $this->assertGreaterThanOrEqual(5, $roles->count(), 'প্রস্তুতিটাই ভুল — অনেক ভূমিকা বসানোর মতো ভূমিকা নেই।');
        $busy->syncRoles($roles);

        $html = $this->actingAs($owner)->get(route('system_admin.user.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="flex flex-wrap gap-1" style="max-width: 34rem; white-space: normal" data-role-chips>/', $html,
            '⛔ ভূমিকার চিপগুলো ভাঙা-যোগ্য ঘরে নেই — অনেক ভূমিকা হলে পর্দার ডানে বেরিয়ে যায়।');
    }
}
