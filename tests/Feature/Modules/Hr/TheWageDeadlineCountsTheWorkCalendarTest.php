<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\Company;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Support\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ আটকে থাকা মজুরির আইনের শেষ দিন ছুটির পঞ্জিকায় গোনা — মেশানোর পরের দাবি (শ্রম আইন ২০০৬ ধারা ১২৩; a6, ২১ অক্টোবর ২০২৬;
 * [[PayrollService::wageDeadline()]], [[WorkCalendar::addWorkingDays()]])।
 *
 * ⓘ সই-শাখা শেষ দিন গুনত কেবল শুক্রবার বাদ দিয়ে; ছুটি-শাখা একই সময়ে কোম্পানির ছুটির তালিকা আর শাখার নিজের সাপ্তাহিক ছুটি আনল।
 * দুটো একসাথে হলে শেষ দিন পঞ্জিকা মানবে — নইলে ছুটির সপ্তাহে কর্মীর পাওনা আইনের আগেই "পেরিয়ে গেছে" (লাল) দেখাত।
 *
 * ── দাবি (সেপ্টেম্বর ২০২৬-এর রান; মাস শেষ বুধবার ৩০; কোনো দিন বাদ না গেলে ৩০ + ৭ = ৭ অক্টোবর) ──────────────────────────
 *  · কোম্পানি শুক্রবার; ৫ অক্টোবর (সোম) সব শাখার ছুটি; নেত্রকোনার সাপ্তাহিক ছুটি শনিবার আর ৮ অক্টোবর (বৃহঃ) কেবল নেত্রকোনার ছুটি।
 *  · তালিকায় ছুটি না থাকলে কেবল শুক্র ২ বাদ → ৮ অক্টোবর (আগের সই-শাখার উত্তরই)।
 *  · ঢাকা ও শাখাহীন কর্মী: শুক্র ২, সোম ৫, শুক্র ৯ বাদ → ঠিক তিন দিন পিছিয়ে ১০ অক্টোবর; নেত্রকোনার নিজের ছুটি তাঁদের ছোঁয় না।
 *  · নেত্রকোনা: শনি ৩, সোম ৫, বৃহঃ ৮, শনি ১০ বাদ, শুক্রবার কাজের দিন → ঠিক চার দিন পিছিয়ে ১১ অক্টোবর।
 *  · রানের পাতার "বাদ পড়া" তালিকা প্রতিটা কর্মীর নিজের শেষ দিন দেখায়, আর ড্যাশবোর্ডের পাওনা-তালিকাও।
 */
final class TheWageDeadlineCountsTheWorkCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $dhaka;

    private Branch $netrokona;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HRWD', 'name_en' => 'Wage Deadline Co']);
        CompanyContext::set($this->company->id);
        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $this->netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        $this->run = PayrollRun::query()->create([
            'company_id' => $this->company->id, 'branch_id' => null, 'document_no' => 'PRL-WD-1', 'run_type' => PayrollRun::SALARY,
            'month' => '2026-09-01', 'trx_date' => '2026-09-30', 'status' => DocumentStatus::CONFIRMED,
        ]);

        // ⓘ আজ ৯ অক্টোবর — ঢাকার শেষ দিন (১০) আর নেত্রকোনার (১১) এখনো আসেনি; শুক্রবার-শুধু গোনায় শেষ দিন ছিল ৮, অর্থাৎ "লাল"
        $this->travelTo('2026-10-09 10:00:00');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_with_no_listed_holiday_the_deadline_is_seven_working_days_after_the_month(): void
    {
        $this->assertSame('2026-10-08', PayrollService::wageDeadline($this->run)->toDateString(),
            '⛔ ছুটি নেই, শুক্রবার ২ বাদে ৩০ সেপ্টেম্বর + ৭ কর্মদিবস = ৮ অক্টোবর হওয়ার কথা।');
        $this->assertSame('2026-10-08', PayrollService::wageDeadline($this->run, $this->employee('E-D', $this->dhaka))->toDateString());
    }

    public function test_a_listed_holiday_and_the_branchs_saturday_off_push_the_deadline_by_exactly_those_days(): void
    {
        $this->listTheHolidays();

        $dhaka = $this->employee('E-D', $this->dhaka);
        $headOffice = $this->employee('E-H', null);
        $netrokona = $this->employee('E-N', $this->netrokona);

        foreach (['Dhaka' => $dhaka, 'head office' => $headOffice] as $who => $employee) {
            $this->assertSame('2026-10-10', PayrollService::wageDeadline($this->run, $employee)->toDateString(),
                "⛔ [{$who}] শুক্র ২, সোম ৫ (তালিকার ছুটি), শুক্র ৯ বাদে শেষ দিন ১০ অক্টোবর — ঠিক তিন দিন পিছিয়ে; নেত্রকোনার ছুটি এখানে নয়।");
        }

        $this->assertSame('2026-10-11', PayrollService::wageDeadline($this->run, $netrokona)->toDateString(),
            '⛔ নেত্রকোনা: শনি ৩, সোম ৫, বৃহঃ ৮ (শাখার ছুটি), শনি ১০ বাদ, শুক্রবার কাজের দিন — শেষ দিন ১১ অক্টোবর।');

        // ⓘ কর্মী ছাড়া — কোম্পানির পঞ্জিকা, কেবল সব-শাখার ছুটি
        $this->assertSame('2026-10-10', PayrollService::wageDeadline($this->run)->toDateString());
    }

    public function test_the_run_page_and_the_dashboard_list_show_each_persons_own_deadline_and_are_not_late_before_it(): void
    {
        $this->listTheHolidays();

        foreach (['E-D' => $this->dhaka, 'E-N' => $this->netrokona] as $code => $branch) {
            $employee = $this->employee($code, $branch);
            Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $this->run->id, 'employee_id' => $employee->id,
                'gross' => '15000', 'deductions' => '0', 'net' => '15000', 'payment_method' => PayrollService::HELD]);
        }

        $rows = collect(app(PayrollService::class)->leftOutOfTheBankFile($this->run->fresh()))->keyBy('code');
        $this->assertSame('2026-10-10', $rows['E-D']['deadline'], '⛔ রানের পাতায় ঢাকার কর্মীর শেষ দিন পঞ্জিকা মানে না।');
        $this->assertSame('2026-10-11', $rows['E-N']['deadline'], '⛔ রানের পাতায় নেত্রকোনার কর্মীর শেষ দিন শাখার সাপ্তাহিক ছুটি মানে না।');
        $this->assertFalse($rows['E-D']['late'], '⛔ ৯ অক্টোবর, শেষ দিন ১০ — অথচ "পেরিয়ে গেছে"।');
        $this->assertFalse($rows['E-N']['late']);

        $owed = app(PayrollService::class)->heldWagesOwed()->keyBy('employee');
        $this->assertSame('2026-10-10', $owed['Person E-D']['deadline'], '⛔ ড্যাশবোর্ডে ঢাকার কর্মীর শেষ দিন পঞ্জিকা মানে না।');
        $this->assertSame('2026-10-11', $owed['Person E-N']['deadline'], '⛔ ড্যাশবোর্ডে নেত্রকোনার শেষ দিন শাখার ছুটি মানে না।');
        $this->assertFalse($owed['Person E-D']['late']);

        // ⓘ শেষ দিন পেরোলে লাল — ঢাকার ১১ তারিখে, নেত্রকোনার তখনো নয়
        $this->travelTo('2026-10-11 10:00:00');
        $rows = collect(app(PayrollService::class)->leftOutOfTheBankFile($this->run->fresh()))->keyBy('code');
        $this->assertTrue($rows['E-D']['late'], '⛔ ঢাকার শেষ দিন ১০, আজ ১১ — তবু লাল নয়।');
        $this->assertFalse($rows['E-N']['late'], '⛔ নেত্রকোনার শেষ দিন আজই — আগেই লাল।');
    }

    /** ⓘ কোম্পানি শুক্রবার (ডিফল্ট); ৫ অক্টোবর সব শাখার ছুটি; নেত্রকোনার সাপ্তাহিক ছুটি শনিবার, আর ৮ অক্টোবর কেবল নেত্রকোনার */
    private function listTheHolidays(): void
    {
        Holiday::query()->create(['company_id' => $this->company->id, 'holiday_date' => '2026-10-05', 'name' => 'Listed for all']);
        Holiday::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->netrokona->id,
            'holiday_date' => '2026-10-08', 'name' => 'Netrokona only']);
        BranchSetting::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->netrokona->id,
            'key' => 'hr.weekly_off', 'value' => 'saturday']);

        $this->assertSame(WorkCalendar::DEFAULT_WEEKLY_OFF, app(WorkCalendar::class)->weeklyOffKey(), 'প্রস্তুতি — কোম্পানির সাপ্তাহিক ছুটি শুক্রবার নয়।');
        $this->assertSame(Carbon::SATURDAY, Carbon::parse('2026-10-03')->dayOfWeek, 'প্রস্তুতি — তারিখের হিসাব ভুল।');
    }

    private function employee(string $code, ?Branch $branch): Employee
    {
        return Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch?->id, 'code' => $code,
            'name_en' => 'Person '.$code, 'joining_date' => '2026-01-01', 'payment_method' => 'bank']);
    }
}
