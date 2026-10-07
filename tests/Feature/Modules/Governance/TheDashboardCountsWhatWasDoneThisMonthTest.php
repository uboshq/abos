<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Governance\Dashboard\GovernanceDashboard;
use App\Modules\Hr\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিরীক্ষা কার্যকলাপ — এ মাসে কী ধরনের কাজ কতবার (নতুন ড্যাশবোর্ড, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ খাতার আসল দাগ থেকে, খাতার ভাষায় নাম; যোগফল = এ মাসের দাগ। ⛔ আগের মাসের দাগ এ মাসে গোনা নয়।
 * ⛔ সুইচ বন্ধে চার্টই নেই।
 */
final class TheDashboardCountsWhatWasDoneThisMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_this_months_trail_is_counted_by_kind_and_last_month_is_not(): void
    {
        $company = Company::create(['code' => 'AUD', 'name_en' => 'Audit Co']);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B1', 'name_en' => 'Main', 'is_active' => true]);
        CompanyContext::set($company->id, $branch->id);
        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $owner->companies()->attach($company->id);
        $this->actingAs($owner);

        config(['abos.dashboards_v2' => true]);
        $before = $this->parts();

        $employee = Employee::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'E-1', 'name_en' => 'Karim', 'joining_date' => '2024-01-01',
        ]);
        $employee->update(['mobile' => '01700000000']);
        $employee->update(['mobile' => '01800000000']);

        // ⛔ আগের মাসের একটা দাগ — এ মাসে গোনা চলবে না
        AuditTrail::query()->latest('id')->firstOrFail()->replicate()->forceFill([
            'created_at' => now()->subMonthNoOverflow()->startOfMonth(),
            // ⓘ নীরব সংরক্ষণে নতুন public_id বসে না — নকলটার নিজের একটা লাগে
            'public_id' => (string) \Illuminate\Support\Str::uuid7(),
        ])->saveQuietly();

        $after = $this->parts();
        $grew = fn (string $action) => (int) ($after[AuditTrail::actionInWords($action)] ?? 0) - (int) ($before[AuditTrail::actionInWords($action)] ?? 0);

        $this->assertSame(2, $grew(AuditTrail::UPDATED), '⛔ এ মাসের বদলের গোনা ভুল — আগের মাসেরটাও গোনা, বা বাদ।');
        $this->assertSame(1, $grew(AuditTrail::CREATED), 'কর্মী তৈরির দাগ গোনা হয়নি।');
        $this->assertSame(
            AuditTrail::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            array_sum(array_map('intval', $after)),
            'যোগফল এ মাসের দাগের সমান নয়।',
        );

        config(['abos.dashboards_v2' => false]);
        $this->assertSame([], GovernanceDashboard::dashboard()->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');
    }

    /**
     * ⭐ লগইন নিরাপত্তা — আজ। ⛔ একই মানুষ চাবি ছাড়া → চার্টই নেই; চাবিসহ → এই কোম্পানির সংখ্যা।
     * ⛔ অন্য কোম্পানির চেষ্টা, আর অচেনা নামের চেষ্টা (সুপার অ্যাডমিন নন বলে) গোনায় নেই; গতকালেরটাও নয়।
     */
    public function test_todays_logins_stay_behind_the_login_logs_wall(): void
    {
        $company = Company::create(['code' => 'LOG', 'name_en' => 'Login Co']);
        $other = Company::create(['code' => 'OTH', 'name_en' => 'Other Co']);
        CompanyContext::set($company->id);

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'email' => 'clerk@login.test']);
        $clerk->companies()->attach($company->id);
        $this->actingAs($clerk);

        $try = fn (?int $companyId, string $who, bool $ok, ?string $reason, $at = null) => \App\Models\LoginAttempt::query()->forceCreate([
            'company_id' => $companyId, 'user_id' => null, 'identifier' => $who, 'succeeded' => $ok, 'reason' => $reason,
            'ip_address' => '10.0.0.1', 'created_at' => $at ?? now(),
        ]);
        $try($company->id, 'clerk@login.test', true, null);
        $try($company->id, 'clerk@login.test', false, \App\Models\LoginAttempt::WRONG_PASSWORD);
        $try($company->id, 'clerk@login.test', false, \App\Models\LoginAttempt::WRONG_PASSWORD, now()->subDay());
        $try($other->id, 'someone@other.test', false, \App\Models\LoginAttempt::WRONG_PASSWORD);
        $try(null, 'made-up@nowhere.test', false, \App\Models\LoginAttempt::UNKNOWN);

        config(['abos.dashboards_v2' => true]);
        $label = __('governance::dashboard.logins_today');
        $this->assertNull(collect(GovernanceDashboard::dashboard()->panels)->firstWhere('label', $label), '⛔ লগইন খাতার চাবি ছাড়াই লগইনের সংখ্যা দেখা গেছে।');

        \Spatie\Permission\Models\Permission::findOrCreate('governance.login.view', 'web');
        $clerk->givePermissionTo('governance.login.view');
        $this->actingAs($clerk->fresh());

        $panel = collect(GovernanceDashboard::dashboard()->panels)->firstWhere('label', $label);
        $this->assertNotNull($panel, 'চাবি থাকা সত্ত্বেও লগইনের চার্ট নেই।');
        $parts = array_column($panel->parts, 'value', 'label');

        $this->assertSame('1', $parts[__('governance::dashboard.login_ok')]);
        $this->assertSame('1', $parts[__('governance::dashboard.login_wrong_password')], '⛔ ভুল পাসওয়ার্ডের গোনায় অন্য কোম্পানি বা গতকাল ঢুকেছে।');
        $this->assertSame('0', $parts[__('governance::dashboard.login_unknown')], '⛔ অচেনা নামের চেষ্টা সুপার অ্যাডমিন ছাড়া কারও গোনায় এসেছে।');
    }

    /** @return array<string, string> খাতা খালি হলে চার্টই নেই — তখন খালি */
    private function parts(): array
    {
        $panel = collect(GovernanceDashboard::dashboard()->panels)->firstWhere('label', __('governance::dashboard.this_month_actions'));

        return $panel === null ? [] : array_column($panel->parts, 'value', 'label');
    }
}
