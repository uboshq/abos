<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\CreditControl\Dashboard\CreditDashboard;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Executive\Services\CreditHeadlines;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মালিকের কেন্দ্রে বাকির চার সংখ্যা — ব্যবহৃত, মেয়াদ পেরোনো, বাকি বন্ধ, সীমার অনুরোধ (বাকি নিয়ন্ত্রণ ধাপ ৩.৩)।
 *
 *   · প্রতিটা কোম্পানির সংখ্যা = ঐ কোম্পানির বাকির ড্যাশবোর্ডের যোগ, আর গ্রুপের মোট = কোম্পানিগুলোর যোগ — দ্বিতীয় হিসাব নয়।
 *   · "আজ" পাতায় চার সংখ্যা দেখায়, কোম্পানি ধরে চাপলে ঐ কোম্পানির বাকির ড্যাশবোর্ড।
 *   · যে কোম্পানিতে বাকির ড্যাশবোর্ডের চাবি নেই, সে যোগে নেই আর যোগটা "অসম্পূর্ণ"; কোথাও নেই তো অংশটাই নেই।
 */
final class TheOwnersCentreShowsTheCreditDesksFiguresTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $depot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->depot->id, $this->depot->defaultBranch()?->id);

        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        $shop = Customer::query()->create(['code' => 'CH-1', 'name_en' => 'CH-1', 'name_bn' => 'CH-1', 'is_active' => true,
            'credit_limit' => '1000', 'branch_id' => $this->depot->defaultBranch()?->id]);
        app(CustomerService::class)->blockCredit($shop, 'পরীক্ষা');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_each_company_is_its_own_credit_desk_and_the_total_is_their_sum(): void
    {
        $companies = $this->companies($this->owner);
        $headlines = app(CreditHeadlines::class)->for($this->owner, $companies);

        $this->assertNotNull($headlines);
        $this->assertFalse($headlines['partial']);
        $this->assertGreaterThanOrEqual(2, count($headlines['companies']), 'প্রস্তুতিটাই ভুল — দুই কোম্পানি দেখা যায়নি।');

        $sum = array_fill_keys(CreditHeadlines::KEYS, '0');
        foreach ($headlines['companies'] as $part) {
            $own = app(CompanyLens::class)->within($this->owner, $part['id'], $part['only'], fn () => CreditDashboard::figures()['totals']);
            $this->assertSame(0, bccomp($part['values']['exposure'], (string) $own['exposure'], 4), "⛔ {$part['name']}-এর ব্যবহৃত বাকি তার ড্যাশবোর্ড থেকে আলাদা।");
            $this->assertSame(0, bccomp($part['values']['overdue'], (string) $own['overdue'], 4));
            $this->assertSame((string) $own['blocked'], $part['values']['blocked']);
            foreach (CreditHeadlines::KEYS as $k) {
                $sum[$k] = bcadd($sum[$k], $part['values'][$k], 4);
            }
        }

        foreach (CreditHeadlines::KEYS as $k) {
            $this->assertSame(0, bccomp($sum[$k], $headlines['totals'][$k], 4), "⛔ গ্রুপের '{$k}' কোম্পানিগুলোর যোগ নয়।");
        }
        $this->assertGreaterThanOrEqual(1, (int) $headlines['totals']['blocked'], 'বন্ধ গ্রাহক গোনা হয়নি — প্রস্তুতিটাই ভুল।');

        $this->get(route('executive.today'))->assertOk()
            ->assertSee('data-credit-figure="blocked"', false)
            ->assertSee(Money::format($headlines['totals']['exposure'], 0));
    }

    public function test_a_company_without_the_credit_key_is_left_out_and_said_so(): void
    {
        $viewer = User::factory()->create(['is_active' => true, 'current_company_id' => $this->depot->id]);
        $viewer->companies()->attach($this->depot->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->depot->id, function () use ($viewer): void {
            $role = Role::create(['name' => 'exec-only-'.Str::random(5), 'guard_name' => 'web']);
            $role->givePermissionTo([Permission::findOrCreate('executive.view', 'web')]);
            $viewer->assignRole($role);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNull(app(CreditHeadlines::class)->for($viewer->fresh(), $this->companies($viewer->fresh())),
            '⛔ বাকির চাবি ছাড়াই মালিকের কেন্দ্রে বাকির সংখ্যা।');
    }

    /** @return list<array{id: int, name: string, only: ?int}> */
    private function companies(User $user): array
    {
        return array_map(fn (array $c) => ['id' => $c['id'], 'name' => $c['name'], 'only' => null],
            app(CompanyLens::class)->companies($user));
    }
}
