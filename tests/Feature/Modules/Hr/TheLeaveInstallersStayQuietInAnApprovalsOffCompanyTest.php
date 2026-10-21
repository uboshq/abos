<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\LeaveTypeDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ প্রমিত ছুটির ধরন একবারই, আর সই বন্ধ কোম্পানিতে কোনো চালু সইয়ের ছক জন্মায় না (fe-র উত্তর ৮, ২১ অক্টোবর ২০২৬; UB-র মতো
 * সব অনুমোদন বন্ধ রাখা কোম্পানি — [[a-seeded-flow-must-respect-approvals-off]]; [[LeaveTypeDefaults]]; a6)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · কোম্পানি খোলার পথ (provisionCompany) আর কনসোলের বসানো দুইবার করে চালালেও কোম্পানিতে ঠিক ৫টা প্রমিত ধরন, প্রতিটা একবার।
 *  · এই শাখার সব কাজ — ধরন বসানো, অর্জিত ছুটির জের, "বছরে দিন" শূন্য — চালিয়েও সই বন্ধ কোম্পানিতে চালু ছক ০, আর বন্ধ ছক বন্ধই।
 */
final class TheLeaveInstallersStayQuietInAnApprovalsOffCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_types_once_and_no_live_approval_flow(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));

        $ub = Company::create(['code' => 'UBOFF', 'name_en' => 'Approvals Off Co']);
        // ⓘ মালিক সব অনুমোদন বন্ধ রেখেছেন — একটা বন্ধ ছক, চালু একটাও নয়
        CompanyContext::forCompany($ub->id, fn () => ApprovalFlow::query()->create(['code' => 'PAY-OFF', 'module' => 'hr', 'action' => 'payroll',
            'threshold_amount' => 0, 'is_active' => false]));

        // ⓘ কোম্পানি ইচ্ছে করে মুছে ফেলা প্রমিত ধরন — আবার জন্মায় না
        CompanyContext::forCompany($ub->id, fn () => LeaveType::query()->create(['code' => 'SICK', 'name_en' => 'Sick',
            'days_per_year' => '14', 'is_paid' => true, 'is_active' => true])->delete());

        CompanyContext::forCompany($ub->id, function (): void {
            app(LeaveTypeDefaults::class)->provisionCompany();
            app(LeaveTypeDefaults::class)->provisionCompany();
        });
        $this->artisan('abos:hr-install-leave-types')->assertSuccessful();
        $this->artisan('abos:hr-install-leave-types')->assertSuccessful();
        $this->artisan('abos:hr-carry-earned-leave', ['--year' => 2025])->assertSuccessful();
        $this->artisan('abos:hr-earned-days-zero')->assertSuccessful();

        $codes = LeaveType::query()->withoutGlobalScopes()->withTrashed()->where('company_id', $ub->id)->pluck('code')->sort()->values()->all();
        $this->assertSame(['CASUAL', 'EARNED', 'MATERNITY', 'SICK', 'UNPAID'], $codes, '⛔ প্রমিত ধরন দুইবার বসল, বা কোনোটা বাকি');
        $this->assertTrue(LeaveType::query()->withoutGlobalScopes()->withTrashed()->where('company_id', $ub->id)->where('code', 'SICK')->sole()->trashed(),
            '⛔ মুছে ফেলা প্রমিত ধরন আবার বসল');

        $flows = ApprovalFlow::query()->withoutGlobalScopes()->where('company_id', $ub->id);
        $this->assertSame(0, (clone $flows)->where('is_active', true)->count(), '⛔ সই বন্ধ কোম্পানিতে চালু ছক জন্মাল');
        $this->assertSame(1, (clone $flows)->count(), '⛔ নতুন ছক (চালু বা বন্ধ) বসল');
    }
}
