<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ এক কোম্পানির অ্যাডমিন অন্য কোম্পানির মালিকের খাতা নিতে পারতেন — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬ (SystemAdmin ⛔২)।
 *
 * ইমেইল, পাসওয়ার্ড আর সচল-অবস্থা একটাই সারিতে, সব কোম্পানির জন্য; অথচ যাচাই হত কেবল চলতি কোম্পানিতে।
 *
 * দাবি — একই মানুষ দুইবার:
 *   · A-তে ইউজার-অ্যাডমিন (লক্ষ্যের A-র সব ক্ষমতা তাঁরও), কিন্তু লক্ষ্য B-তে super_admin → খাতা বদলানো আর তালা
 *     খোলা দুটোই ৪০৩; একই অ্যাডমিন B-তেও মালিক হলে → দুটোই হয়
 *   · A-র মালিকও B-র মালিকের খাতায় নয়, যতক্ষণ না তিনি B-রও মালিক
 */
final class AnAdminInOneCompanyTookAnOwnerOfAnotherTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->a = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->b = Company::query()->where('is_active', true)->where('id', '<>', $this->a->id)->orderBy('id')->firstOrFail();

        // ⓘ লক্ষ্য: A-তে সামান্য (আদেশ দেখা), B-তে মালিক
        $this->target = $this->member([$this->a, $this->b]);
        CompanyContext::forCompany($this->a->id, fn () => $this->target->givePermissionTo('sales.order.view'));
        $this->superAdminIn($this->target, $this->b);

        CompanyContext::set($this->a->id, $this->a->defaultBranch()?->id);
    }

    public function test_an_admin_of_one_company_cannot_change_an_owner_of_another_until_owning_it_too(): void
    {
        $admin = $this->member([$this->a]);
        CompanyContext::forCompany($this->a->id, fn () => $admin->givePermissionTo(['system_admin.user.manage', 'sales.order.view']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->mayChange($admin), '⛔ A-র অ্যাডমিন B-র মালিকের পাসওয়ার্ড-ইমেইল বদলাতে পারেন।');
        $this->turnOffTwoStep($admin)->assertForbidden();
        $this->target->forceFill(['two_step_required' => true])->save();

        // ⭐ একই অ্যাডমিন, এবার B-রও মালিক — তাঁকে ঢাকেন
        $this->superAdminIn($admin, $this->b);
        $this->assertTrue($this->mayChange($admin), 'দুই কোম্পানিতেই ঢাকেন, তবু ফেরানো হলো।');
        $this->turnOffTwoStep($admin)->assertRedirect();
        $this->assertFalse((bool) $this->target->fresh()->two_step_required);
    }

    public function test_even_the_owner_of_one_company_cannot_take_the_owner_of_another(): void
    {
        $owner = $this->member([$this->a]);
        $this->superAdminIn($owner, $this->a);

        $this->assertFalse($this->mayChange($owner), '⛔ A-র মালিক B-র মালিকের খাতা নিতে পারেন।');

        $this->superAdminIn($owner, $this->b);
        $this->assertTrue($this->mayChange($owner));
    }

    private function mayChange(User $actor): bool
    {
        return CompanyContext::forCompany($this->a->id, fn () => Gate::forUser($actor->fresh())->allows('update', $this->target->fresh()));
    }

    private function turnOffTwoStep(User $actor): \Illuminate\Testing\TestResponse
    {
        $this->target->forceFill(['two_step_required' => true])->save();

        return $this->actingAs($actor->fresh())->put(route('system_admin.user.two_step.set', $this->target), [
            'required' => 0, 'reason' => 'ফোন হারিয়েছে, নতুন করে বসাবেন',
        ]);
    }

    /** @param  list<Company>  $companies */
    private function member(array $companies): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $companies[0]->id]);
        foreach ($companies as $company) {
            $user->companies()->attach($company->id, ['is_active' => true]);
        }

        return $user;
    }

    private function superAdminIn(User $user, Company $company): void
    {
        $role = Role::query()->where('company_id', $company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        CompanyContext::forCompany($company->id, fn () => $user->unsetRelation('roles')->assignRole($role));
        $user->companies()->syncWithoutDetaching([$company->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
