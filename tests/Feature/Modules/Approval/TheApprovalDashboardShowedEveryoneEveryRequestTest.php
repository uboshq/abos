<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Approval\Dashboard\ApprovalDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * অনুমোদনের ড্যাশবোর্ড সবাইকে কোম্পানির সব অপেক্ষমাণ অনুরোধ দেখাত — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * পাতাটা কেবল `approval.view` চাইত, অথচ প্রতিটা সংখ্যা আর "এখন অপেক্ষায়"
 * তালিকা গোটা কোম্পানির। ⓘ বিপজ্জনক মানুষ দুইজন: কেবল `approval.view`
 * যাঁর (নিজের ছুটির খবর নিতে আসেন), আর রিপোর্টের চাবি থাকলেও এক শাখায়
 * আটকানো জন (অনুমোদনের রিপোর্ট তাঁকে ফেরায়, কারণ সারিতে শাখা নেই)।
 */
final class TheApprovalDashboardShowedEveryoneEveryRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, null);
        Approval::query()->delete();
    }

    public function test_a_person_with_only_the_view_key_sees_their_own_requests(): void
    {
        $clerk = $this->member(['approval.view']);
        $other = $this->member(['approval.view']);

        $this->request($clerk, 1, '100');
        $this->request($other, 2, '999999');
        $this->request($other, 3, '888888');

        $dashboard = $this->dashboardAs($clerk);

        $this->assertSame('1', $dashboard->stats[0]->value, '⛔ কেবল দেখার চাবির মানুষ কোম্পানির সব অপেক্ষমাণ অনুরোধ গুনলেন।');
        $this->assertSame(['100.0000'], $dashboard->listings[0]->rows->map(fn (Approval $a) => (string) $a->amount)->all(),
            '⛔ "এখন অপেক্ষায়" তালিকায় অন্যের অনুরোধ এল।');
    }

    public function test_the_reporting_key_without_a_branch_wall_sees_the_whole_company(): void
    {
        $clerk = $this->member(['approval.view']);
        $reporter = $this->member(['approval.view', 'approval.report']);

        $this->request($clerk, 1, '100');
        $this->request($clerk, 2, '200');

        $this->assertSame('2', $this->dashboardAs($reporter)->stats[0]->value, 'রিপোর্টের চাবির মানুষ গোটা কোম্পানি দেখেন না — দাবি অন্ধ।');
    }

    public function test_the_reporting_key_behind_a_branch_wall_sees_only_their_own(): void
    {
        $clerk = $this->member(['approval.view']);
        $walled = $this->member(['approval.view', 'approval.report']);
        $branch = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $walled->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branch->id,
        ]);

        $this->request($clerk, 1, '100');
        $this->request($walled, 2, '200');

        $this->assertSame('1', $this->dashboardAs($walled)->stats[0]->value,
            '⛔ শাখায় আটকানো মানুষ ড্যাশবোর্ড দিয়ে গোটা কোম্পানির অনুমোদন দেখলেন — রিপোর্ট যাঁকে ফেরায়।');
    }

    public function test_a_request_handed_to_me_is_in_my_count(): void
    {
        $clerk = $this->member(['approval.view']);
        $other = $this->member(['approval.view']);

        $this->request($other, 1, '100')->forceFill(['assigned_to' => $clerk->id])->save();
        $this->request($other, 2, '200');

        $this->assertSame('1', $this->dashboardAs($clerk)->stats[0]->value, 'আমার হাতে দেওয়া অনুরোধটা আমার সংখ্যায় নেই।');
    }

    private function dashboardAs(User $who): DashboardDefinition
    {
        CompanyContext::set($this->company->id, null);
        $this->actingAs($who->fresh());
        app(DataScope::class)->forget();

        return ApprovalDashboard::dashboard();
    }

    private function request(User $by, int $id, string $amount): Approval
    {
        return Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => 'test', 'approvable_id' => $id,
            'module' => 'sales', 'action' => 'discount', 'amount' => $amount, 'status' => Approval::PENDING,
            'current_level' => 1, 'requested_by' => $by->id, 'requested_at' => now(),
        ]);
    }

    /** @param list<string> $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
