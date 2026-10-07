<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কর্মী ও বেতনের কাঠামো।
 *
 * ── কেন এখানকার পরীক্ষাগুলো তারিখ ধরে ধরে ───────────────────────────
 * বেতনের ভুল সবচেয়ে দেরিতে ধরা পড়ে, আর সবচেয়ে বেশি ক্ষতি করে: একজন
 * মানুষ কম টাকা পেলেন, আর সেটা তিনি মাসের শেষে টের পেলেন। তাই এখানে
 * দেখা হয় গত মাসের কাঠামো আজও গত মাসের অঙ্কেই দাঁড়ায় কি না।
 */
class HrTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
    }

    private function salaries(): SalaryStructureService
    {
        return app(SalaryStructureService::class);
    }

    private function salaryHeadNamed(string $code): SalaryHead
    {
        return SalaryHead::query()->where('code', $code)->firstOrFail();
    }

    private function employee(array $overrides = []): Employee
    {
        return app(EmployeeService::class)->create([
            'code' => 'EMP-001',
            'name_en' => 'Rafiq Islam',
            'name_bn' => 'রফিক ইসলাম',
            'department_id' => Department::query()->where('code', 'SALES')->value('id'),
            'designation_id' => Designation::query()->where('code', 'SR')->value('id'),
            'joining_date' => '2026-01-15',
            'payment_method' => 'cash',
            ...$overrides,
        ]);
    }

    // ── বেতনের অঙ্ক ───────────────────────────────────────────────────

    /**
     * শতাংশের ভাতা মূল বেতনের উপর দাঁড়ায়।
     *
     * বাড়িভাড়া টাকায় লিখে রাখলে বেতন বাড়ার দিনে ভাতাগুলো পুরনো থেকে
     * যেত, আর কেউ খেয়াল না করলে বছরের পর বছর কম দেওয়া হত।
     */
    public function test_an_allowance_set_as_a_percentage_follows_the_basic(): void
    {
        $employee = $this->employee();

        $this->salaries()->set($employee, $this->salaryHeadNamed('BASIC'), '2026-01-15', '20000');
        $this->salaries()->set($employee, $this->salaryHeadNamed('HRA'), '2026-01-15', '50');
        $this->salaries()->set($employee, $this->salaryHeadNamed('MEDICAL'), '2026-01-15', '1500');
        $this->salaries()->set($employee, $this->salaryHeadNamed('PF'), '2026-01-15', '10');

        $totals = $this->salaries()->totalsOn($employee, Carbon::parse('2026-02-01'));

        $this->assertSame(0, bccomp($totals['gross'], '31500', 4), 'মোট আয় ২০০০০ + ১০০০০ + ১৫০০ হওয়ার কথা');
        $this->assertSame(0, bccomp($totals['deductions'], '2000', 4), 'PF মূলের ১০% = ২০০০');
        $this->assertSame(0, bccomp($totals['net'], '29500', 4));
    }

    /**
     * বেতন বাড়ার পরেও গত মাসের কাঠামো গত মাসেরই থাকে।
     *
     * এটাই কাঠামোটা তারিখ ধরে রাখার একমাত্র কারণ — নাহলে কর্মীর সারিতে
     * একটা কলামই যথেষ্ট হত।
     */
    public function test_a_raise_does_not_rewrite_last_months_salary(): void
    {
        $employee = $this->employee();

        $this->salaries()->set($employee, $this->salaryHeadNamed('BASIC'), '2026-01-15', '20000');
        $this->salaries()->set($employee, $this->salaryHeadNamed('HRA'), '2026-01-15', '50');

        // জুলাই থেকে বেতন বেড়ে ২৫,০০০
        $this->salaries()->set($employee, $this->salaryHeadNamed('BASIC'), '2026-07-01', '25000');

        $june = $this->salaries()->totalsOn($employee, Carbon::parse('2026-06-30'));
        $july = $this->salaries()->totalsOn($employee, Carbon::parse('2026-07-31'));

        $this->assertSame(0, bccomp($june['gross'], '30000', 4), 'জুনে ২০০০০ + ৫০% = ৩০০০০');
        $this->assertSame(0, bccomp($july['gross'], '37500', 4), 'জুলাইয়ে ২৫০০০ + ৫০% = ৩৭৫০০');
    }

    /** কাঠামো বসার আগের তারিখে বেতন শূন্য — অনুমান নয়। */
    public function test_before_the_structure_starts_there_is_no_salary(): void
    {
        $employee = $this->employee();

        $this->salaries()->set($employee, $this->salaryHeadNamed('BASIC'), '2026-01-15', '20000');

        $this->assertSame([], $this->salaries()->componentsOn($employee, Carbon::parse('2026-01-01')));
    }

    /** একই তারিখে দ্বিতীয়বার বসানো মানে সংশোধন, দ্বিতীয় সারি নয়। */
    public function test_the_same_date_twice_corrects_instead_of_duplicating(): void
    {
        $employee = $this->employee();

        $this->salaries()->set($employee, $this->salaryHeadNamed('BASIC'), '2026-01-15', '20000');
        $this->salaries()->set($employee, $this->salaryHeadNamed('BASIC'), '2026-01-15', '21000');

        $this->assertSame(1, $employee->structures()->count());
        $this->assertSame(0, bccomp(
            $this->salaries()->totalsOn($employee, Carbon::parse('2026-02-01'))['gross'], '21000', 4));
    }

    public function test_a_percentage_over_a_hundred_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->salaries()->set($this->employee(), $this->salaryHeadNamed('HRA'), '2026-01-15', '120');
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->salaries()->set($this->employee(), $this->salaryHeadNamed('BASIC'), '2026-01-15', '-1');
    }

    // ── কর্মী ─────────────────────────────────────────────────────────

    /**
     * ব্যাংকে বেতন মানে হিসাব নম্বর লাগবেই।
     *
     * খালি রাখলে বেতনের দিনে ব্যাংক পুরো ফাইলটাই ফেরত দিত — একজনের
     * ভুলে সবার বেতন আটকে যেত।
     */
    public function test_paying_into_a_bank_needs_an_account_number(): void
    {
        $this->expectException(ValidationException::class);

        $this->employee(['payment_method' => 'bank']);
    }

    public function test_paying_by_mobile_banking_needs_a_number(): void
    {
        $this->expectException(ValidationException::class);

        $this->employee(['payment_method' => 'mfs']);
    }

    public function test_two_employees_cannot_share_a_code(): void
    {
        $this->employee();

        $this->expectException(ValidationException::class);

        $this->employee(['name_en' => 'Someone Else']);
    }

    public function test_leaving_before_joining_is_refused(): void
    {
        $employee = $this->employee();

        $this->expectException(ValidationException::class);

        app(EmployeeService::class)->endEmployment($employee, '2025-12-31');
    }

    /**
     * চাকরির অবসানে নামটা থেকে যায়।
     *
     * মুছে ফেললে গত বছরের বেতনশিট আর মিলত না, আর "কাকে কত দেওয়া
     * হয়েছিল" প্রশ্নের উত্তর হারাত।
     */
    public function test_ending_employment_keeps_the_record(): void
    {
        $employee = $this->employee();

        app(EmployeeService::class)->endEmployment($employee, '2026-06-30');

        $employee->refresh();

        $this->assertFalse($employee->is_active);
        $this->assertSame('2026-06-30', $employee->leaving_date->toDateString());
        $this->assertNotNull(Employee::query()->find($employee->id), 'রেকর্ডটা মুছে যাওয়ার কথা নয়');
    }

    /**
     * ছাড়ার মাসেও বেতন হয়।
     *
     * ওই মাসের কিছু দিন কাজ করা হয়েছে। "নিষ্ক্রিয় মানেই বাদ" ধরলে
     * শেষ মাসের বেতনটাই কেউ পেত না।
     */
    public function test_the_month_someone_leaves_is_still_a_paid_month(): void
    {
        $employee = $this->employee();
        app(EmployeeService::class)->endEmployment($employee, '2026-06-10');

        $onPayroll = fn (string $monthEnd) => Employee::query()
            ->onPayrollFor(Carbon::parse($monthEnd))
            ->where('id', $employee->id)
            ->exists();

        // নিষ্ক্রিয় হয়ে গেছে বলে সক্রিয়-ফিল্টারে আর আসে না, কিন্তু
        // wasEmployedOn সত্যিটা বলে — জুনের ১ তারিখে সে কর্মরত ছিল
        $this->assertTrue($employee->fresh()->wasEmployedOn(Carbon::parse('2026-06-01')));
        $this->assertFalse($employee->fresh()->wasEmployedOn(Carbon::parse('2026-07-01')));
        $this->assertFalse($onPayroll('2026-07-31'), 'জুলাইয়ের তালিকায় আর থাকার কথা নয়');
    }

    // ── খাত ───────────────────────────────────────────────────────────

    /** মূল বেতন একটাই — নতুনটা এলে পুরনোটার পতাকা নামে। */
    public function test_only_one_head_can_be_the_basic(): void
    {
        app(SalaryHeadService::class)->create([
            'code' => 'BASIC2',
            'name_en' => 'Another basic',
            'kind' => SalaryHead::EARNING,
            'calculation' => SalaryHead::FIXED,
            'is_basic' => true,
        ]);

        $this->assertSame(1, SalaryHead::query()->where('is_basic', true)->count());
        $this->assertSame('BASIC2', SalaryHead::query()->where('is_basic', true)->value('code'));
    }

    public function test_the_basic_head_cannot_be_a_deduction(): void
    {
        $this->expectException(ValidationException::class);

        app(SalaryHeadService::class)->create([
            'code' => 'ODD',
            'name_en' => 'Odd one',
            'kind' => SalaryHead::DEDUCTION,
            'calculation' => SalaryHead::FIXED,
            'is_basic' => true,
        ]);
    }

    /**
     * মূল বেতনের খাত বন্ধ করা যায় না।
     *
     * বন্ধ হলে শতাংশের ভাতাগুলো শূন্যের শতাংশ হয়ে যেত, আর বেতনশিট
     * চুপচাপ ছোট হয়ে বেরোত — কোনো বার্তা ছাড়াই।
     */
    public function test_the_basic_head_cannot_be_switched_off(): void
    {
        $this->expectException(ValidationException::class);

        app(SalaryHeadService::class)->deactivate($this->salaryHeadNamed('BASIC'));
    }

    /** নিষ্ক্রিয় খাত আর বেতনে যোগ হয় না। */
    public function test_a_switched_off_head_leaves_the_salary(): void
    {
        $employee = $this->employee();

        $this->salaries()->set($employee, $this->salaryHeadNamed('BASIC'), '2026-01-15', '20000');
        $this->salaries()->set($employee, $this->salaryHeadNamed('MEDICAL'), '2026-01-15', '1500');

        app(SalaryHeadService::class)->deactivate($this->salaryHeadNamed('MEDICAL'));

        $this->assertSame(0, bccomp(
            $this->salaries()->totalsOn($employee, Carbon::parse('2026-02-01'))['gross'], '20000', 4));
    }

    // ── পর্দা ─────────────────────────────────────────────────────────

    public function test_the_screens_open(): void
    {
        $employee = $this->employee();

        $this->get(route('hr.employee.index'))->assertOk()->assertSee($employee->code);
        $this->get(route('hr.employee.show', $employee))->assertOk();
        $this->get(route('hr.employee.create'))->assertOk();
        $this->get(route('hr.employee.edit', $employee))->assertOk();
        $this->get(route('hr.employee.salary', $employee))->assertOk();
        $this->get(route('hr.salary_head.index'))->assertOk()->assertSee('BASIC');
    }

    /**
     * ছেড়ে যাওয়া কর্মী ডিফল্টে তালিকায় নেই, কিন্তু চেকবক্সে আছে।
     */
    public function test_those_who_left_are_out_of_the_list_but_not_gone(): void
    {
        $employee = $this->employee();
        app(EmployeeService::class)->endEmployment($employee, '2026-06-30');

        $this->get(route('hr.employee.index'))->assertOk()->assertDontSee($employee->code);
        $this->get(route('hr.employee.index', ['left' => 1]))->assertOk()->assertSee($employee->code);
    }

    /**
     * বেতনের অঙ্ক দেখার অনুমতি আলাদা।
     *
     * হিসাবরক্ষককে বেতনশিট দেখতে হয়, কিন্তু গুদামের কেরানিকে নয় —
     * আর কর্মীর পাতায় সেটা ফাঁস হওয়া উচিত নয়।
     */
    public function test_salary_figures_need_their_own_permission(): void
    {
        $employee = $this->employee();
        $this->salaries()->set($employee, $this->salaryHeadNamed('BASIC'), '2026-01-15', '20000');

        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company->id);
        $clerk->givePermissionTo('hr.employee.view');

        $this->actingAs($clerk)
            ->get(route('hr.employee.show', $employee))
            ->assertOk()
            ->assertDontSee('20,000.00');

        $this->actingAs($clerk)
            ->get(route('hr.employee.salary', $employee))
            ->assertForbidden();
    }
}
