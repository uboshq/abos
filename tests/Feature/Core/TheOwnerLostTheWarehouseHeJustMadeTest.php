<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মালিক নতুন গুদাম চারবার যোগ করলেন, তালিকায় একটাও নেই — মালিক, ৩ অক্টোবর ২০২৬ (ডেমো)।
 *
 * ⓘ কারণ: ২৯ সেপ্টেম্বর তিনি নিজের নামে তখনকার একমাত্র গুদামটা টিক দিয়েছিলেন। পরে বানানো প্রতিটা গুদাম
 * সেই তালিকার বাইরে, আর হেডারের শাখার গুদামের সাথে মিলিয়ে কিছুই বাকি থাকত না। ⭐ সুপার অ্যাডমিনের
 * কোনো সীমা নেই — বাকিদের সীমা আগের মতোই কামড়ায়।
 */
final class TheOwnerLostTheWarehouseHeJustMadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_sees_a_new_warehouse_even_with_an_old_warehouse_tick_while_a_clerk_stays_limited(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = $company->defaultBranch();
        CompanyContext::set($company->id, $branch?->id);

        $old = Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->firstOrFail();

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $clerk = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);

        foreach ([$owner, $clerk] as $user) {
            UserDataScope::query()->create([
                'company_id' => $company->id, 'user_id' => $user->id,
                'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $old->id,
            ]);
        }

        $new = Warehouse::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'branch_id' => $old->branch_id,
            'code' => 'WH-NEW-3OCT', 'name_en' => 'Mymensingh',
        ]);

        $this->actingAs($owner);
        app()->forgetInstance(DataScope::class);
        $this->assertNull(app(DataScope::class)->idsFor($owner, UserDataScope::WAREHOUSE), '⛔ সুপার অ্যাডমিন গুদামের সীমায় আটকা।');
        $this->assertTrue(Warehouse::query()->whereKey($new->id)->exists(), '⛔ মালিক নিজের বানানো গুদাম দেখেন না।');

        $this->actingAs($clerk);
        app()->forgetInstance(DataScope::class);
        $this->assertSame([$old->id], app(DataScope::class)->idsFor($clerk, UserDataScope::WAREHOUSE), 'সাধারণ কর্মীর সীমা হারিয়ে গেছে।');
        $this->assertFalse(Warehouse::query()->whereKey($new->id)->exists(), '⛔ সীমাবদ্ধ কর্মী নিজের তালিকার বাইরের গুদাম দেখছেন।');
    }
}
