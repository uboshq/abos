<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পুরনো কপি থেকে "আবার বানান" নিশ্চিত রানের বেতনশিট মুছত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⚠️৫)।
 *
 * ⓘ [[PayrollService::rebuild()]] খসড়া কি না দেখত হাতের কপিতে, তালা ছাড়া। ততক্ষণে আরেকজন নিশ্চিত করে বেতন খাতায় বসালে,
 * আবার-বানানো শিটগুলো মুছে নতুন বসাত — খাতার অঙ্ক এক, শিট আরেক। এখন লেনদেনের ভেতরে সারিতে তালা দিয়ে তাজা অবস্থা।
 */
final class ARebuildCannotEraseAConfirmedRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create(['code' => 'EMP-RB', 'name_en' => 'Rebuilt', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
    }

    public function test_a_rebuild_from_a_stale_copy_leaves_the_confirmed_slips_alone(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $stale = PayrollRun::query()->findOrFail($run->id);
        $slips = $run->payslips()->orderBy('id')->pluck('id')->all();

        app(PayrollService::class)->confirm($run);
        $this->assertSame(DocumentStatus::DRAFT, $stale->status, 'প্রস্তুতিটাই ভুল — হাতের কপি খসড়া থাকার কথা');

        $said = null;

        try {
            app(PayrollService::class)->rebuild($stale);
        } catch (ValidationException $e) {
            $said = $e->errors()['status'][0] ?? null;
        }

        $this->assertSame(__('hr::validation.only_a_draft_can_change'), $said, '⛔ নিশ্চিত রান পুরনো কপি থেকে আবার বানানো গেল');
        $this->assertSame($slips, $run->payslips()->orderBy('id')->pluck('id')->all(), '⛔ নিশ্চিত রানের বেতনশিট মুছে নতুন বসল');
        $this->assertSame(DocumentStatus::CONFIRMED, $run->fresh()->status);
        $this->assertSame(
            bcadd((string) $run->fresh()->net_total, '0', 2),
            bcadd((string) LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
                ->where('account_id', \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::SALARY_PAYABLE)->id)
                ->sum('credit'), '0', 2),
            '⛔ খাতার বেতন-দেনা আর রানের নিট আলাদা হয়ে গেল',
        );
    }

    public function test_a_draft_still_rebuilds(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $before = $run->payslips()->pluck('id')->all();

        $rebuilt = app(PayrollService::class)->rebuild($run);

        $this->assertSame(DocumentStatus::DRAFT, $rebuilt->status);
        $this->assertSame(count($before), $rebuilt->payslips()->count());
        $this->assertSame([], array_values(array_intersect($before, $rebuilt->payslips()->pluck('id')->all())), 'খসড়া আবার বানালে শিট নতুন বসার কথা');
    }
}
