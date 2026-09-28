<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ব্যবহারকারীর পর্দা অন্য কোম্পানির সদস্যপদে হাত দিত — নিরীক্ষা §১.৭, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * কোম্পানি ক-এর প্রশাসক দুই-কোম্পানির একজন কর্মীকে সম্পাদনা করলে
 * `companies()->sync()` ফর্মে না-আসা খ-এর সদস্যপদটা মুছে দিত, আর
 * `model_has_roles`-এর মোছা খ-এর ভূমিকাও নিয়ে যেত — খ-এর প্রশাসক কিছুই
 * দেখতেন না, কর্মী কেবল পরদিন তালাবন্ধ হতেন।
 *
 * ⚠️ উল্টো দিকেও দরজা খোলা: প্রশাসক খ-তে সাধারণ সদস্য হলেও খ-কে টিক
 * দিয়ে সেখানে ভূমিকা বসাতে পারতেন, আর খ-এর নিজের বানানো ভূমিকা নাম
 * ধরে ক-তে নকল হয়ে যেত।
 *
 * ── ⭐ নিয়ম ────────────────────────────────────────────────────────────
 * যে কোম্পানিতে আপনি দাঁড়িয়ে, কেবল সেখানকার সদস্যপদ, ভূমিকা আর দেখার
 * সীমা। সুপার অ্যাডমিন আজকের মতোই একাধিক কোম্পানি টিক দেন — তবে কেবল
 * সেগুলো, যেখানে তিনি নিজেও সুপার অ্যাডমিন। বাকি সব সারি অক্ষত।
 */
final class TheUserScreenReachedAnotherCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $aBranch;

    private Branch $bBranch;

    private User $admin;

    private User $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->a = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->b = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->aBranch = CompanyContext::forCompany($this->a->id, fn () => Branch::query()->where('code', 'NTK')->firstOrFail());
        $this->bBranch = CompanyContext::forCompany($this->b->id, fn () => Branch::query()->where('code', 'MAIN')->firstOrFail());

        /* ⓘ খ-এর নিজের বানানো ভূমিকা — ক-তে এই নামে কিছুই নেই */
        CompanyContext::forCompany($this->b->id, function () {
            Role::findOrCreate('b_only_cashier', 'web')->syncPermissions(['customer.report']);
        });

        CompanyContext::set($this->a->id, $this->a->defaultBranch()?->id);

        /* ⓘ চাবিটা ইচ্ছা করে শুরুতে নেই — একই মানুষকে পরে দেওয়া হয় */
        Role::findOrCreate('a_user_admin', 'web')->syncPermissions(['customer.view', 'customer.report']);
        Role::findOrCreate('a_clerk', 'web')->syncPermissions(['customer.view']);

        $this->admin = $this->person('Rina Admin', 'rina@abos.test');
        $this->admin->companies()->attach([$this->a->id => ['is_active' => true], $this->b->id => ['is_active' => true]]);
        $this->admin->assignRole('a_user_admin');
        CompanyContext::forCompany($this->b->id, fn () => $this->admin->unsetRelation('roles')->assignRole('b_only_cashier'));

        $this->shared = $this->person('Karim Shared', 'karim@abos.test');
        $this->shared->companies()->attach([
            $this->a->id => ['is_active' => true, 'default_branch_id' => $this->aBranch->id],
            $this->b->id => ['is_active' => true, 'default_branch_id' => $this->bBranch->id],
        ]);
        $this->shared->assignRole('a_clerk');
        CompanyContext::forCompany($this->b->id, fn () => $this->shared->unsetRelation('roles')->assignRole('b_only_cashier'));

        foreach ([[$this->a->id, $this->aBranch->id], [$this->b->id, $this->bBranch->id]] as [$companyId, $branchId]) {
            DB::table('user_data_scopes')->insert([
                'public_id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'user_id' => $this->shared->id,
                'scope_type' => UserDataScope::BRANCH,
                'scope_id' => $branchId,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
        }

        CompanyContext::set($this->a->id, $this->a->defaultBranch()?->id);
        $this->forgetPermissions();
        $this->actingAs($this->admin);
    }

    /**
     * ⭐ একই প্রশাসক — চাবি ছাড়া ৪০৩, চাবি পেলে সেভ; আর খ অক্ষত দুইবারই।
     */
    public function test_editing_a_shared_user_from_a_leaves_b_membership_roles_and_scopes_identical(): void
    {
        $bBefore = $this->snapshotIn($this->b->id);

        $this->put(route('system_admin.user.update', $this->shared), $this->form(['companies' => [$this->a->id]]))
            ->assertForbidden();

        $this->assertSame($bBefore, $this->snapshotIn($this->b->id));

        $this->grantTheKey();

        $this->put(route('system_admin.user.update', $this->shared), $this->form([
            'name' => 'Karim Renamed',
            'companies' => [$this->a->id],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        // ⓘ ক-তে কাজটা সত্যিই হয়েছে — নাহলে "খ অক্ষত" মানে কেবল "কিছুই হয়নি"
        $this->assertSame('Karim Renamed', $this->shared->fresh()->name);

        $this->assertSame($bBefore, $this->snapshotIn($this->b->id),
            'ক থেকে সম্পাদনার পর খ-এর সদস্যপদ, ভূমিকা বা দেখার সীমা বদলে গেছে।');
    }

    /**
     * ⛔ খ-কে টিক দিয়ে পাঠালে ৪২২ — আর কোথাও কিছু লেখা হয় না।
     */
    public function test_a_smuggled_company_b_is_refused_and_nothing_is_written(): void
    {
        $this->grantTheKey();
        $before = $this->everything();

        $this->put(route('system_admin.user.update', $this->shared), $this->form([
            'companies' => [$this->a->id, $this->b->id],
        ]))->assertSessionHasErrors('companies.1');

        $this->assertSame($before, $this->everything());
    }

    public function test_creating_a_user_in_company_b_from_a_is_refused(): void
    {
        $this->grantTheKey();

        $this->post(route('system_admin.user.store'), $this->form([
            'email' => 'newcomer@abos.test',
            'password' => 'Plenty0fLetters2026',
            'companies' => [$this->a->id, $this->b->id],
        ]))->assertSessionHasErrors('companies.1');

        $this->assertFalse(User::query()->where('email', 'newcomer@abos.test')->exists());
    }

    public function test_a_b_branch_in_the_default_branch_slot_is_refused(): void
    {
        $this->grantTheKey();
        $before = $this->everything();

        $this->put(route('system_admin.user.update', $this->shared), $this->form([
            'default_branch' => [$this->a->id => $this->bBranch->id],
        ]))->assertSessionHasErrors('default_branch.'.$this->a->id);

        $this->assertSame($before, $this->everything());
    }

    public function test_a_b_branch_in_the_branch_scope_is_refused(): void
    {
        $this->grantTheKey();
        $before = $this->everything();

        $this->put(route('system_admin.user.update', $this->shared), $this->form([
            'branch_scope' => [$this->a->id => [$this->aBranch->id, $this->bBranch->id]],
        ]))->assertSessionHasErrors('branch_scope.'.$this->a->id);

        $this->assertSame($before, $this->everything());
    }

    public function test_a_scope_keyed_to_company_b_is_refused(): void
    {
        $this->grantTheKey();
        $before = $this->everything();

        $this->put(route('system_admin.user.update', $this->shared), $this->form([
            'branch_scope' => [$this->b->id => [$this->bBranch->id]],
        ]))->assertSessionHasErrors('branch_scope.'.$this->b->id);

        $this->assertSame($before, $this->everything());
    }

    /**
     * ⛔ খ-এর নিজের ভূমিকা নাম ধরে ক-তে নকল হয় না।
     */
    public function test_a_role_that_exists_only_in_b_is_refused(): void
    {
        $this->grantTheKey();
        $before = $this->everything();

        $this->put(route('system_admin.user.update', $this->shared), $this->form([
            'roles' => ['a_clerk', 'b_only_cashier'],
        ]))->assertSessionHasErrors('roles.1');

        $this->assertSame($before, $this->everything());
        $this->assertFalse(
            Role::query()->where('name', 'b_only_cashier')->where('company_id', $this->a->id)->exists(),
            'খ-এর ভূমিকা ক-তে নকল হয়ে গেছে।',
        );
    }

    public function test_the_create_and_edit_forms_offer_nothing_of_company_b(): void
    {
        $this->grantTheKey();

        foreach ([route('system_admin.user.create'), route('system_admin.user.edit', $this->shared)] as $url) {
            $response = $this->get($url)->assertOk();

            $this->assertSame([$this->a->id], $response->viewData('companies')->pluck('id')->map(fn ($id) => (int) $id)->all(), $url);
            $this->assertSame([$this->a->id], collect($response->viewData('branches'))->keys()->map(fn ($id) => (int) $id)->all(), $url);
            $this->assertSame([$this->a->id], collect($response->viewData('scopeChoices'))->keys()->map(fn ($id) => (int) $id)->all(), $url);
            $this->assertSame([], $response->viewData('roles')->reject(fn (Role $r) => (int) $r->company_id === $this->a->id)->pluck('name')->all(), $url);

            $response->assertDontSee('b_only_cashier')->assertDontSee($this->bBranch->name_en);
        }
    }

    /**
     * ⓘ সুপার অ্যাডমিন দুই কোম্পানিতেই মালিক — আজকের আচরণ থাকে:
     * দুইটাই দেখেন, দুইটাই টিক দেন, ভূমিকা দুইটাতেই বসে।
     */
    public function test_an_owner_of_both_companies_still_assigns_across_both(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $ids = $this->get(route('system_admin.user.edit', $this->shared))->assertOk()
            ->viewData('companies')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->a->id, $ids);
        $this->assertContains($this->b->id, $ids);

        $this->put(route('system_admin.user.update', $this->shared), $this->form([
            'companies' => [$this->a->id, $this->b->id],
        ]))->assertSessionHasNoErrors();

        $this->assertTrue(DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_id', $this->shared->id)
            ->where('model_has_roles.company_id', $this->b->id)
            ->where('roles.name', 'a_clerk')
            ->exists(), 'মালিকের দুই-কোম্পানির বরাদ্দ আর খ-তে পৌঁছায় না।');
    }

    /**
     * ⛔ ক-তে সুপার অ্যাডমিন, খ-তে সাধারণ সদস্য — খ তাঁর নাগালে নয়।
     */
    public function test_a_super_admin_of_a_who_is_plain_in_b_cannot_reach_b(): void
    {
        $this->admin->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE);
        $this->forgetPermissions();
        $before = $this->everything();

        $this->put(route('system_admin.user.update', $this->shared), $this->form([
            'companies' => [$this->a->id, $this->b->id],
        ]))->assertSessionHasErrors('companies.1');

        $this->assertSame($before, $this->everything());
    }

    private function grantTheKey(): void
    {
        Role::query()->where('name', 'a_user_admin')->where('company_id', $this->a->id)->firstOrFail()
            ->givePermissionTo('system_admin.user.manage');
        $this->forgetPermissions();
    }

    private function forgetPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin->unsetRelation('roles')->unsetRelation('permissions');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_replace([
            'name' => $this->shared->name,
            'email' => $this->shared->email,
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => ['a_clerk'],
            'companies' => [$this->a->id],
            'default_branch' => [$this->a->id => $this->aBranch->id],
            'branch_scope' => [$this->a->id => [$this->aBranch->id]],
        ], $overrides);
    }

    /**
     * এক কোম্পানিতে মানুষটার সব সারি — পিভট, ভূমিকা, দেখার সীমা, হুবহু।
     *
     * @return array<string, mixed>
     */
    private function snapshotIn(int $companyId): array
    {
        return json_decode((string) json_encode([
            'pivot' => DB::table('company_user')->where('user_id', $this->shared->id)->where('company_id', $companyId)->get()->all(),
            'roles' => DB::table('model_has_roles')->where('model_id', $this->shared->id)->where('company_id', $companyId)->orderBy('role_id')->get()->all(),
            'scopes' => DB::table('user_data_scopes')->where('user_id', $this->shared->id)->where('company_id', $companyId)->orderBy('id')->get()->all(),
        ]), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function everything(): array
    {
        return [
            'user' => json_decode((string) json_encode(DB::table('users')->where('id', $this->shared->id)->first()), true),
            'a' => $this->snapshotIn($this->a->id),
            'b' => $this->snapshotIn($this->b->id),
            'roles_in_a' => Role::query()->where('company_id', $this->a->id)->orderBy('name')->pluck('name')->all(),
        ];
    }

    private function person(string $name, string $email): User
    {
        // ⓘ `current_company_id` ইচ্ছা করে fillable নয় — তাই forceFill, ফর্মের পথ নয়
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret-for-a-test'),
            'is_active' => true,
            'locale' => 'bn',
            'current_company_id' => $this->a->id,
        ])->save();

        return $user;
    }
}
