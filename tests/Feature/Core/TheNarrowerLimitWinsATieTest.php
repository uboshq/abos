<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\AuthorityService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalLimit;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ সমান নির্দিষ্ট দুই সীমায় কড়াটা জেতে, আর কাজের সারি মডিউলের সারির চেয়ে নির্দিষ্ট — Approval লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[AuthorityService::limitFor()]]; [[ApprovalLimit::weight()]])।
 *
 * ⓘ একটা ভূমিকায় আগে "বিক্রয়, ৫ লাখ" বসানো ছিল, পরে "ছাড় (sales.discount), ১০ হাজার" — মডিউলের ঘর ফাঁকা রেখে। দুটোর ওজন সমান (১),
 * তাই আগে বানানোটা জিতত, আর ছাড়ের সরু সীমা কোনোদিন খাটত না: ৫ লাখ পর্যন্ত ছাড়ে সই।
 */
final class TheNarrowerLimitWinsATieTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_action_limit_beats_its_module_limit_and_a_tie_takes_the_lower_amount(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $role = Role::query()->create(['name' => 'Sales Desk', 'guard_name' => 'web', 'company_id' => $company->id]);
        $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $user->assignRole($role));
        $user = $user->fresh();

        ApprovalLimit::query()->create(['company_id' => $company->id, 'role_id' => $role->id, 'module' => 'sales', 'max_amount' => '500000']);
        ApprovalLimit::query()->create(['company_id' => $company->id, 'role_id' => $role->id, 'action' => 'sales.discount', 'max_amount' => '10000']);

        $this->assertSame('10000.0000', app(AuthorityService::class)->limitFor($user, 'sales', 'discount', null),
            '⛔ আগে বানানো মডিউলের সীমা ছাড়ের সরু সীমাকে হারাল');
        $this->assertSame('500000.0000', app(AuthorityService::class)->limitFor($user, 'sales', 'invoice', null), 'অন্য কাজে মডিউলের সীমা খাটে');

        // ⓘ একেবারে সমান দুই সারিতে কড়াটা — ক্রম যাই হোক
        $other = Role::query()->create(['name' => 'Branch Desk', 'guard_name' => 'web', 'company_id' => $company->id]);
        $second = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $second->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $second->assignRole($other));
        ApprovalLimit::query()->create(['company_id' => $company->id, 'role_id' => $other->id, 'module' => 'purchase', 'max_amount' => '90000']);
        ApprovalLimit::query()->create(['company_id' => $company->id, 'role_id' => $other->id, 'module' => 'purchase', 'max_amount' => '20000']);
        $this->assertSame('20000.0000', app(AuthorityService::class)->limitFor($second->fresh(), 'purchase', 'payment', null));

        // ⓘ কাজের সারি বেশি নির্দিষ্ট — উদার হলেও সেটাই খাটে (কাজের সীমা নিজের মডিউলের সীমার চেয়ে বড় হতে পারে)
        $third = Role::query()->create(['name' => 'Discount Desk', 'guard_name' => 'web', 'company_id' => $company->id]);
        $giver = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $giver->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $giver->assignRole($third));
        ApprovalLimit::query()->create(['company_id' => $company->id, 'role_id' => $third->id, 'module' => 'sales', 'max_amount' => '10000']);
        ApprovalLimit::query()->create(['company_id' => $company->id, 'role_id' => $third->id, 'action' => 'sales.discount', 'max_amount' => '500000']);
        $this->assertSame('500000.0000', app(AuthorityService::class)->limitFor($giver->fresh(), 'sales', 'discount', null),
            '⛔ মডিউল ফাঁকা রাখা কাজের সারি মডিউলের সারির সমান ধরা হল');
    }
}
