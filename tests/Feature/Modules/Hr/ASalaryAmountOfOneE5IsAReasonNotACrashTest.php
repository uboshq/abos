<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\SalaryHeadService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বেতন-কাঠামোর অঙ্কে "1e5" — কারণসহ ফেরত, ৫০০ নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ১; [[SalaryStructureController::store()]])।
 *
 * ⓘ `numeric` নিয়ম "1e5" মেনে নিত, আর [[SalaryStructureService]]-এর bcmath সেটা পড়তে না পেরে পাতাটা ভেঙে দিত।
 */
final class ASalaryAmountOfOneE5IsAReasonNotACrashTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private SalaryHead $basic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SalaryHeadService::class)->installDefaults();

        $this->employee = app(EmployeeService::class)->create(['code' => 'EMP-1E5', 'name_en' => 'Typed Fast', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $this->basic = SalaryHead::query()->where('code', 'BASIC')->firstOrFail();
    }

    public function test_a_badly_written_amount_gets_a_reason(): void
    {
        foreach (['1e5', '0x10', '100.55555', '1000000000000000'] as $amount) {
            $this->post(route('hr.employee.salary.store', $this->employee), [
                'effective_from' => '2026-08-01',
                'amounts' => [$this->basic->id => $amount],
            ])->assertSessionHasErrors('amounts.'.$this->basic->id);
        }

        $this->assertSame(0, SalaryStructure::query()->where('employee_id', $this->employee->id)->count(), '⛔ ভুল লেখা অঙ্ক কাঠামোয় বসল');

        $this->post(route('hr.employee.salary.store', $this->employee), [
            'effective_from' => '2026-08-01',
            'amounts' => [$this->basic->id => '25000.5'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, SalaryStructure::query()->where('employee_id', $this->employee->id)->count(), 'ঠিক অঙ্ক আগের মতোই যায়');
    }
}
