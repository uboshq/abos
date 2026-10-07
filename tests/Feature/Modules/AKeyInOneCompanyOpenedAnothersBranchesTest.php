<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A কোম্পানির চাবিতে B কোম্পানির শাখা আর কোম্পানি বদলানো যেত।
 *
 * ── ⛔ কী দেখা গিয়েছিল, ২৯ সেপ্টেম্বর ২০২৬ (নিরাপত্তা-অডিট, খোঁজ ৬) ──────────
 * কোম্পানি আর শাখার পর্দা চাবি দেখত **চলতি** কোম্পানিতে
 * (`can:system_admin.company.manage`), আর অন্য কোম্পানির পাতায় দেখত কেবল
 * **সদস্যপদ** ([[User::canAccessCompany()]])। ফলে A-তে অ্যাডমিন আর B-তে কেবল
 * সাধারণ সদস্য — A-তে বসে B-র শাখার নাম বদলাতে, বন্ধ করতে, মুছতে পারতেন।
 * লাইভে প্রোব চালিয়ে প্রমাণিত (৩০২, নাম বদলে গেল)।
 *
 * ⚠️ ABOS অনেক ব্যবসার কাছে বিক্রি হয়; একই ইনস্টলে অসম্পর্কিত দুই ব্যবসা
 * এলে এটাই এক ব্যবসার লোকের হাতে অন্যটার শাখা তুলে দিত।
 *
 * ── ⭐ কীভাবে মাপা ──────────────────────────────────────────────────────
 * একই মানুষ, একই শাখা, একই অনুরোধ: B-তে চাবি নেই → ৪০৪ আর নাম অক্ষত;
 * B-তে একই চাবি দিলে → বদলায়। ⚠️ মালিক (দুই কোম্পানিতেই super_admin)
 * কখনো আটকান না — মালিকের শর্ত, ৩০ সেপ্টেম্বর।
 */
final class AKeyInOneCompanyOpenedAnothersBranchesTest extends TestCase
{
    use RefreshDatabase;

    private Company $depot;

    private Company $mart;

    private Branch $martBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mart = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->martBranch = Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->mart->id)->where('code', 'MAIN')->firstOrFail();

        CompanyContext::set($this->depot->id, $this->depot->defaultBranch()?->id);
    }

    public function test_a_key_in_one_company_does_not_rename_anothers_branch(): void
    {
        $admin = $this->adminOfDepotMemberOfMart();

        $this->actingAs($admin)
            ->put(route('system_admin.branch.update', $this->martBranch->id), $this->branchForm('Taken over'))
            ->assertNotFound();
        $this->assertNotSame('Taken over', $this->martBranch->fresh()->name_en, '⛔ A-র চাবিতে B-র শাখার নাম বদলে গেল।');

        $this->actingAs($admin)->get(route('system_admin.branch.edit', $this->martBranch->id))->assertNotFound();
        $this->actingAs($admin)->post(route('system_admin.branch.toggle', $this->martBranch->id))->assertNotFound();
        $this->assertTrue((bool) $this->martBranch->fresh()->is_active, '⛔ A-র চাবিতে B-র শাখা বন্ধ হয়ে গেল।');

        /* ⭐ একই মানুষ, B-তে একই চাবি — এবার বদলায় */
        $this->grantIn($admin, $this->mart);

        $this->actingAs($admin->fresh())
            ->put(route('system_admin.branch.update', $this->martBranch->id), $this->branchForm('Renamed by B admin'))
            ->assertRedirect();
        $this->assertSame('Renamed by B admin', $this->martBranch->fresh()->name_en);
    }

    public function test_a_key_in_one_company_does_not_open_a_branch_in_another(): void
    {
        $admin = $this->adminOfDepotMemberOfMart();
        $before = $this->martBranchCount();

        $this->actingAs($admin)->post(route('system_admin.branch.store'), [
            'company_id' => $this->mart->id,
            ...$this->branchForm('Planted', 'PLT'),
        ])->assertSessionHasErrors('company_id');
        $this->assertSame($before, $this->martBranchCount(), '⛔ A-র চাবিতে B-তে নতুন শাখা খুলে গেল।');

        $this->grantIn($admin, $this->mart);

        $this->actingAs($admin->fresh())->post(route('system_admin.branch.store'), [
            'company_id' => $this->mart->id,
            ...$this->branchForm('Opened by B admin', 'OBB'),
        ])->assertSessionHasNoErrors();
        $this->assertSame($before + 1, $this->martBranchCount());
    }

    public function test_a_key_in_one_company_does_not_edit_another_company(): void
    {
        $admin = $this->adminOfDepotMemberOfMart();
        $name = $this->mart->name_en;

        $this->actingAs($admin)->get(route('system_admin.company.edit', $this->mart->id))->assertNotFound();
        $this->actingAs($admin)->post(route('system_admin.company.toggle', $this->mart->id))->assertNotFound();
        $this->assertTrue((bool) $this->mart->fresh()->is_active, '⛔ A-র চাবিতে B কোম্পানি বন্ধ হয়ে গেল।');
        $this->assertSame($name, $this->mart->fresh()->name_en);

        $this->grantIn($admin, $this->mart);

        $this->actingAs($admin->fresh())->get(route('system_admin.company.edit', $this->mart->id))->assertOk();
    }

    public function test_the_owner_who_is_super_admin_everywhere_is_never_stopped(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->assertTrue($owner->canAccessCompany($this->mart->id), 'মালিক FMART-এর সদস্য নন — দাবিটা কিছু মাপছে না।');

        $this->actingAs($owner)
            ->put(route('system_admin.branch.update', $this->martBranch->id), $this->branchForm('Owner renamed'))
            ->assertRedirect();
        $this->assertSame('Owner renamed', $this->martBranch->fresh()->name_en);

        $this->actingAs($owner)->get(route('system_admin.company.edit', $this->mart->id))->assertOk();
    }

    public function test_the_check_leaves_the_working_company_as_it_was(): void
    {
        /*
         * ⚠️ অন্য কোম্পানিতে চাবি মাপতে প্রসঙ্গ এক মুহূর্ত বদলায়। ফিরে না এলে
         * অনুরোধের বাকিটা ভুল কোম্পানিতে চলত — তার চেয়ে বড় ভুল আর নেই।
         */
        $admin = $this->adminOfDepotMemberOfMart();

        $admin->canInCompany($this->mart->id, 'system_admin.company.manage');

        $this->assertSame($this->depot->id, CompanyContext::id());
        $this->assertTrue($admin->fresh()->can('system_admin.company.manage'), 'চলতি কোম্পানির চাবি হারিয়ে গেল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** A (TDEPOT)-তে কোম্পানি চালানোর চাবি; B (FMART)-তে কেবল সদস্য, কোনো চাবি নেই। */
    private function adminOfDepotMemberOfMart(): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->depot->id,
            'current_branch_id' => $this->depot->defaultBranch()?->id,
            'is_active' => true,
        ]);
        $user->companies()->attach($this->depot->id, ['is_active' => true]);
        $user->companies()->attach($this->mart->id, ['is_active' => true]);

        $this->grantIn($user, $this->depot);

        $this->assertTrue($user->canAccessCompany($this->mart->id));

        return $user->fresh();
    }

    private function grantIn(User $user, Company $company): void
    {
        CompanyContext::forCompany($company->id, function () use ($user) {
            $user->unsetRelation('permissions');
            $user->givePermissionTo(Permission::findOrCreate('system_admin.company.manage', 'web'));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<string, mixed> */
    private function branchForm(string $name, ?string $code = null): array
    {
        return array_filter([
            'code' => $code ?? $this->martBranch->code,
            'name_en' => $name,
            'name_bn' => $this->martBranch->name_bn,
        ], fn ($v) => $v !== null);
    }

    private function martBranchCount(): int
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->mart->id)->count();
    }
}
