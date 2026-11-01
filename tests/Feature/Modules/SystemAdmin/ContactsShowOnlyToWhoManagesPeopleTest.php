<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Core\Support\ContactMask;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ ইমেইল আর ফোন পুরো কেবল মানুষ সামলানোর চাবিতে, বাকিদের আংশিক ঢাকা — কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর
 * ২০২৬, দফা ১২। ⛔ আগে সিস্টেমের হোম (সেটিংসের চাবিতে খোলে) আর রোলের পাতা (রোলের চাবিতে) সবার ইমেইল খোলা দেখাত।
 */
final class ContactsShowOnlyToWhoManagesPeopleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        // ⓘ সেটিংস আর রোলের চাবি আছে, মানুষ সামলানোর চাবি নেই
        $role = Role::query()->forceCreate(['name' => 'Settings And Roles Only', 'guard_name' => 'web', 'company_id' => $company->id]);
        $role->givePermissionTo(Permission::findOrCreate('system_admin.settings.manage', 'web'), Permission::findOrCreate('system_admin.role.manage', 'web'));
        $this->keeper = User::factory()->create(['email' => 'keeper-only@abos.test', 'current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id]);
        $this->keeper->companies()->attach($company->id, ['is_active' => true]);
        $this->keeper->assignRole($role);
    }

    public function test_the_mask_keeps_who_it_is_but_not_the_address(): void
    {
        $this->actingAs($this->keeper);
        $this->assertFalse($this->keeper->can(ContactMask::PERMISSION), 'ⓘ পরীক্ষার ভিত্তি: মানুষ সামলানোর চাবি নেই।');
        $this->assertSame('ow•••@abos.test', ContactMask::email('owner@abos.test'), '⛔ চাবি ছাড়া ইমেইল খোলা।');
        $this->assertSame('017•••••678', ContactMask::phone('01711 000678'), '⛔ চাবি ছাড়া ফোন খোলা।');

        $this->actingAs($this->owner);
        $this->assertSame('owner@abos.test', ContactMask::email('owner@abos.test'), '⛔ চাবিওয়ালার কাছেও ইমেইল ঢাকা।');
        $this->assertSame('01711 000678', ContactMask::phone('01711 000678'), '⛔ চাবিওয়ালার কাছেও ফোন ঢাকা।');
    }

    public function test_the_system_home_and_the_role_page_do_not_show_addresses_without_the_key(): void
    {
        $home = (string) $this->actingAs($this->keeper)->get(route('module.dashboard', 'system_admin'))->assertOk()->getContent();
        $this->assertStringNotContainsString('owner@abos.test', $home, '⛔ সিস্টেমের হোমে অন্যের ইমেইল খোলা।');
        $this->assertStringContainsString('ow•••@abos.test', $home, '⛔ সিস্টেমের হোমে নতুন মানুষদের ঢাকা ঠিকানাও নেই।');

        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $role = Role::query()->whereKey(\Illuminate\Support\Facades\DB::table('model_has_roles')->where('model_id', $accountant->id)->pluck('role_id'))
            ->where('company_id', CompanyContext::id())->where('name', '!=', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $page = (string) $this->get(route('system_admin.role.edit', $role))->assertOk()->getContent();
        $this->assertStringNotContainsString('accounts@abos.test', $page, '⛔ রোলের পাতায় সদস্যের ইমেইল খোলা — খোঁজার লেখাতেও।');
        $this->assertStringContainsString('ac•••@abos.test', $page, '⛔ রোলের পাতায় সদস্যের ঢাকা ঠিকানাও নেই।');

        $full = (string) $this->actingAs($this->owner)->get(route('system_admin.role.edit', $role))->assertOk()->getContent();
        $this->assertStringContainsString('accounts@abos.test', $full, '⛔ চাবিওয়ালাও রোলের পাতায় পুরো ইমেইল দেখেন না।');
    }
}
