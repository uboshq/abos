<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Dashboard\SalesBoard;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Models\SalesTarget;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ নিরাপত্তা অডিট, ১২ নভেম্বর ২০২৬ (মালিক: "যাকে যে পরিমাণ পারমিশন দেওয়া আছে সেই পারমিশন অনুযায়ী যাতে ব্যারিকেডটা ঠিক থাকে"):
 *   বিক্রয়ের ড্যাশবোর্ডে দেয়ালের ভেতরের মানুষ (ফিল্ড ফোর্স) গোটা কোম্পানির লক্ষ্য আর প্রত্যেক বিক্রয়কর্মীর অর্জন দেখতেন; আর মালিকের
 *   কমিশনের তালিকা দশ মিনিট তাঁর পর্দায় থাকত (ক্যাশের চাবিতে মানুষ ছিল না)।
 */
final class AWalledSalesmanSeesOnlyHisOwnTeamsTargetsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(SettingsService::class)->set('customer.dealer_scope_enabled', true);
        Cache::flush();
    }

    public function test_the_target_and_the_team_rows_are_only_his_and_the_owners_cached_list_never_reaches_him(): void
    {
        $sr = $this->person('SR Karim', ['customer.view', 'customer.dealers.own', 'sales.invoice.view']);
        $other = $this->person('SR Rahim', ['customer.view', 'customer.dealers.own', 'sales.invoice.view']);
        $month = Carbon::today()->startOfMonth()->toDateString();
        foreach ([[$sr, '100000'], [$other, '900000']] as [$who, $amount]) {
            SalesTarget::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'user_id' => $who->id, 'month' => $month, 'amount' => $amount]);
        }

        // ⓘ মালিক আগে খোলেন — গোটা কোম্পানির লক্ষ্য, দুইজনই, আর ক্যাশে মালিকের কমিশনের তালিকা
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->assertSame(0, bccomp((string) app(SalesAnalytics::class)->targetAchievement(Carbon::today())['target'], '1000000', 4), 'প্রস্তুতিটাই ভুল — মালিকের লক্ষ্য দুইজনের যোগ নয়।');
        app(SalesBoard::class)->srCommission();
        $ownerKey = $this->commissionKey().'.all';
        $this->assertTrue(Cache::has($ownerKey), 'প্রস্তুতিটাই ভুল — মালিকের তালিকা ক্যাশে নেই।');

        $this->actingAs($sr);
        $target = app(SalesAnalytics::class)->targetAchievement(Carbon::today())['target'];
        $this->assertSame(0, bccomp((string) $target, '100000', 4), '⛔ দেয়ালের ভেতরের মানুষ গোটা কোম্পানির লক্ষ্য দেখেন।');
        $names = array_column(app(SalesBoard::class)->srTargets()['rows'], 'name');
        $this->assertNotContains('SR Rahim', $names, '⛔ সহকর্মীর লক্ষ্য আর অর্জন দেখা যায়।');

        app(SalesBoard::class)->srCommission();
        $this->assertTrue(Cache::has($this->commissionKey().'.u'.$sr->id), '⛔ দেয়ালের ভেতরের মানুষ মালিকের ক্যাশের তালিকা পেলেন — নিজের চাবিতে আলাদা নয়।');
    }

    /** ⓘ ক্যাশের চাবির মাথা — যে মানুষ এখন, তার দেখার শাখা ধরে (বোর্ডের একই হিসাব) */
    private function commissionKey(): string
    {
        return 'abos.sales-board-sr-commission.'.CompanyContext::id().'.'.(\App\Core\Support\ViewedBranch::one() ?? 'all').'.'.Carbon::today()->toDateString();
    }

    /** @param list<string> $keys */
    private function person(string $name, array $keys): User
    {
        $u = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id]);
        $u->companies()->attach($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $u->givePermissionTo($keys);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }
}
