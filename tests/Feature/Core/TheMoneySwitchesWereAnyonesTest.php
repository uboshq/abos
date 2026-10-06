<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ টাকার নিয়ম শিথিল করার সুইচ যে কেউ বদলাতে পারতেন — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬ (SystemAdmin ⛔৪)।
 *
 * মালিক-কেবল সুইচ ছিল মোটে ছয়টা; বাকিগুলো "সেটিং রক্ষক" (মালিকের ভূমিকা নয়) বদলাতে পারতেন — তার মধ্যে নিজের অনুরোধ
 * নিজে মঞ্জুরের সীমা, ছাড়ের সর্বোচ্চ, ঋণাত্মক মজুদ, মার্জিন, কমিশনের সীমা, পেছনের তারিখ, নগদের সীমা, দর-অমিল আটকানো,
 * বদলিতে দুই-মানুষ আর পাকা কাগজ সম্পাদনা।
 *
 * দাবি: ঐ তেরোটা সুইচই "কেবল মালিক"; একই মানুষ — সেটিংয়ের চাবি আছে কিন্তু মালিক নন → বদলায় না; মালিক হলে → বদলায়।
 */
final class TheMoneySwitchesWereAnyonesTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ONLY = [
        'approval.self_limit',
        'sales.discount_cap_bill_percent', 'sales.discount_cap_line_percent', 'sales.allow_negative_stock',
        'sales.margin.action', 'sales.margin.floor_percent', 'sales.commission_max_amount', 'sales.commission_max_percent',
        'accounts.backdate_days', 'accounts.cash_ceiling_blocks',
        'purchase.block_price_mismatch',
        'inventory.transfer_two_people',
        'system.edit_posted_papers',
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_every_switch_that_relaxes_a_money_rule_is_the_owners(): void
    {
        $definitions = app(SettingsService::class)->definitions();

        foreach (self::OWNER_ONLY as $key) {
            $this->assertArrayHasKey($key, $definitions, "সুইচ {$key} আর নেই — দাবিটা হালনাগাদ করুন।");
            $this->assertTrue((bool) ($definitions[$key]['super_admin_only'] ?? false), "⛔ {$key} মালিক ছাড়াও বদলানো যায়।");
        }
    }

    public function test_a_settings_keeper_cannot_relax_them_until_made_owner(): void
    {
        $keeper = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $keeper->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id,
            fn () => $keeper->givePermissionTo(Permission::findOrCreate('system_admin.settings.manage', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $before = [$this->fresh()->get('approval.self_limit'), $this->fresh()->get('sales.discount_cap_bill_percent')];

        $this->actingAs($keeper->fresh())->put(route('system_admin.settings.update'), ['settings' => [
            'approval.self_limit' => '500000', 'sales.discount_cap_bill_percent' => '90',
        ]]);
        $this->assertSame($before, [$this->fresh()->get('approval.self_limit'), $this->fresh()->get('sales.discount_cap_bill_percent')],
            '⛔ মালিক নন, অথচ নিজের অনুরোধ নিজে মঞ্জুরের সীমা আর ছাড়ের সর্বোচ্চ বদলালেন।');

        // ⭐ একই মানুষ, এবার মালিক
        CompanyContext::forCompany($this->company->id, fn () => $keeper->unsetRelation('roles')->assignRole(
            Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($keeper->fresh())->put(route('system_admin.settings.update'), ['settings' => [
            'approval.self_limit' => '500000', 'sales.discount_cap_bill_percent' => '90',
        ]]);
        $this->assertSame(0, bccomp('500000', (string) $this->fresh()->get('approval.self_limit'), 2), 'মালিক, তবু বদলাল না।');
        $this->assertSame(0, bccomp('90', (string) $this->fresh()->get('sales.discount_cap_bill_percent'), 2));
    }

    /** ⓘ মান নতুন করে পড়া — সেবা এক অনুরোধ থেকে আরেকটায় ক্যাশ রাখে */
    private function fresh(): SettingsService
    {
        app()->forgetInstance(SettingsService::class);

        return app(SettingsService::class);
    }
}
