<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Dashboard\ApprovalDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ ড্যাশবোর্ডের নিরীক্ষা, ২৩ নভেম্বর ২০২৬ (দফা ৬): অনুমোদনের ড্যাশবোর্ডের "এখন যা আটকে আছে" তালিকায় অঙ্কের ঘর বেতনের রানের
 * অঙ্কও দেখাত — বেতনের চাবি (`hr.payroll.view`) ছাড়াই। বেতনের অঙ্ক বেতনের খবর; বাকি সব পর্দায় সে ঐ চাবির পেছনে।
 *
 * দাবি, একই মানুষ দুইবার (চাবি ছাড়া, তারপর চাবিসহ): নিজের জমা দেওয়া বেতনের রানের অঙ্ক চাবি ছাড়া ঢাকা (••••), চাবিসহ পুরো অঙ্ক;
 * পাশের অন্য কাজের (বেতন নয়) অঙ্ক দুইবারই খোলা — ঢাকনা কেবল বেতনের রানে।
 */
final class APayrollAmountStaysBehindThePayrollKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_payroll_amount_shows_only_with_the_payroll_key(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id);
        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('approval.view', 'web')));

        $ask = fn (string $module, string $action, string $amount): Approval => Approval::query()->create([
            'company_id' => $company->id, 'approvable_type' => $company->getMorphClass(), 'approvable_id' => $company->id,
            'module' => $module, 'action' => $action, 'amount' => $amount, 'status' => Approval::PENDING,
            'current_level' => 1, 'requested_by' => $clerk->id, 'requested_at' => now()->subHour(),
        ]);
        $payroll = $ask('hr', 'payroll', '734519.25');
        $other = $ask('approval', 'spec_check', '1250.50');

        $this->actingAs($clerk->fresh());
        $hidden = $this->amounts();
        $this->assertArrayHasKey($payroll->id, $hidden, 'দাবির ভিত্তি নেই — নিজের জমা দেওয়া বেতনের রান তালিকায় নেই।');
        $this->assertSame(Stat::HIDDEN, $hidden[$payroll->id], '⛔ বেতনের চাবি ছাড়াই বেতনের রানের অঙ্ক দেখা গেছে।');
        $this->assertSame(Money::format('1250.50'), $hidden[$other->id], '⛔ বেতন নয় এমন কাজের অঙ্কও ঢাকা পড়েছে।');

        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('hr.payroll.view', 'web')));
        $this->actingAs($clerk->fresh());
        $shown = $this->amounts();
        $this->assertSame(Money::format('734519.25'), $shown[$payroll->id], '⛔ বেতনের চাবি থাকতেও অঙ্ক ঢাকা।');
        $this->assertSame(Money::format('1250.50'), $shown[$other->id]);
    }

    /** @return array<int, string> অনুমোদনের id => তালিকার অঙ্কের ঘর */
    private function amounts(): array
    {
        $list = collect(ApprovalDashboard::dashboard()->listings)->firstWhere('label', __('approval::dashboard.waiting_now'));
        $this->assertInstanceOf(Listing::class, $list, '"এখন যা আটকে আছে" তালিকা নেই।');
        $column = collect($list->columns)->firstWhere('key', 'amount');

        return $list->rows->mapWithKeys(fn (Approval $a) => [(int) $a->id => (string) ($column['render'])($a)])->all();
    }
}
