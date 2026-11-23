<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ অর্জিত ছুটির "বছরে দিন" শূন্যে — `abos:hr-earned-days-zero` (fe-র উত্তর ৪, ২১ অক্টোবর ২০২৬; [[EarnedLeaveDaysToZero]]; a6)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · `--dry` কোন কোম্পানি বদলাবে তা বলে (১৫ → ০), কিছু লেখে না, অডিটেও কিছু নয়।
 *  · আসল চালানো কেবল সেই ধরনটা শূন্য করে — শূন্য থাকা কোম্পানি আর অন্য ধরন অক্ষত — আর আগের মান (১৫) অডিটে।
 *  · দ্বিতীয়বার চালালে কিছুই বদলায় না, অডিটে নতুন সারি নেই।
 */
final class EarnedLeaveDaysGoToZeroOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_lists_then_the_real_run_writes_the_old_value_to_the_audit_once(): void
    {
        $old = Company::create(['code' => 'OLDEL', 'name_en' => 'Old Earned']);
        $done = Company::create(['code' => 'ZERO', 'name_en' => 'Already Zero']);

        $earned = $this->type($old, 'EARNED', '15');
        $casual = $this->type($old, 'CASUAL', '10');
        $zero = $this->type($done, 'EARNED', '0');

        $audits = fn (): int => AuditTrail::query()->withoutGlobalScopes()->where('auditable_type', $earned->getMorphClass())
            ->where('action', AuditTrail::UPDATED)->count();

        $this->artisan('abos:hr-earned-days-zero', ['--dry' => true])
            ->expectsOutputToContain('OLDEL · EARNED: 15 → 0 (dry)')
            ->doesntExpectOutputToContain('ZERO · EARNED')
            ->assertSuccessful();
        $this->assertSame('15.0', (string) $this->fresh($earned)->days_per_year, '⛔ --dry লিখে ফেলল');
        $this->assertSame(0, $audits());

        $this->artisan('abos:hr-earned-days-zero')->assertSuccessful();
        $this->assertSame('0.0', (string) $this->fresh($earned)->days_per_year);
        $this->assertSame('10.0', (string) $this->fresh($casual)->days_per_year, '⛔ অন্য ধরন ছুঁয়ে ফেলল');
        $this->assertSame('0.0', (string) $this->fresh($zero)->days_per_year);

        $trail = AuditTrail::query()->withoutGlobalScopes()->forRecord(LeaveType::class, (int) $earned->id)
            ->where('action', AuditTrail::UPDATED)->with('changes')->sole();
        $change = $trail->changes->firstWhere('field', 'days_per_year');
        $this->assertNotNull($change, '⛔ বদলটা অডিটে নেই');
        $this->assertSame(0, bccomp('15', (string) $change->old_value, 1), '⛔ আগের মান অডিটে নেই');

        $this->artisan('abos:hr-earned-days-zero')->expectsOutputToContain('0')->assertSuccessful();
        $this->assertSame(1, $audits(), '⛔ দ্বিতীয়বারে আবার লেখা হল');
    }

    private function type(Company $company, string $code, string $days): LeaveType
    {
        return CompanyContext::forCompany($company->id, fn () => LeaveType::query()->create(['code' => $code, 'name_en' => $code,
            'days_per_year' => $days, 'is_paid' => true, 'is_active' => true]));
    }

    private function fresh(LeaveType $type): LeaveType
    {
        return LeaveType::query()->withoutGlobalScopes()->findOrFail($type->id);
    }
}
