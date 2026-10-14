<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ইউজার-অ্যাডমিন মালিকের ওপর দুই ধাপ চাপাতেন — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[UserController::setTwoStep()]]-এ নীতির পাহারা ([[UserPolicy::update()]]) কেবল "বন্ধ" পথে ছিল; "চালু" তার আগেই ফিরত।
 * ⓘ তাই যাঁর খাতা বদলানোর অধিকার নেই, তাঁকেও পরের অনুরোধে বসানোর পর্দায় আটকে দেওয়া যেত। সাধারণ কর্মীর বেলায় আগের মতোই চলে।
 */
final class TheUserAdminForcedTheOwnerIntoTwoStepTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\TakesTheDealerWallOffTheDemoSalesman;

    public function test_two_step_is_switched_on_only_for_someone_the_admin_may_edit(): void
    {
        $this->seed(DemoSeeder::class);
        $this->takeTheDealerWallOffTheDemoSalesman();
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['two_step_required' => false])->save();

        $admin = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertFalse($admin->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        CompanyContext::forCompany($company->id,
            fn () => $admin->givePermissionTo(Permission::findOrCreate('system_admin.user.manage', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin->fresh())
            ->put(route('system_admin.user.two_step.set', $owner), ['required' => 1])
            ->assertForbidden();
        $this->assertFalse((bool) $owner->fresh()->two_step_required, 'ইউজার-অ্যাডমিন মালিকের ওপর দুই ধাপ চাপালেন।');

        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);

        $this->actingAs($admin->fresh())
            ->put(route('system_admin.user.two_step.set', $clerk), ['required' => 1])
            ->assertRedirect();
        $this->assertTrue((bool) $clerk->fresh()->two_step_required, 'সাধারণ কর্মীর বেলাতেও আটকে গেল — বেশি বন্ধ হয়েছে।');
    }
}
