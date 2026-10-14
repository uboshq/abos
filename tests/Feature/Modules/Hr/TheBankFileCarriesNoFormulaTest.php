<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ব্যাংকের ফাইলে সূত্র চলে না — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ৫; [[PayrollService::csvField()]])।
 *
 * ⓘ ঘর মোড়া হত কেবল কমা আর উদ্ধৃতিতে; "=HYPERLINK(...)"-এর মতো নাম ফাইলে যেমন তেমন যেত, আর এক্সেলে খোলামাত্র সূত্রটা চলত।
 */
final class TheBankFileCarriesNoFormulaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cell_that_starts_like_a_formula_is_written_as_text(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();

        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-CSV', 'name_en' => 'Formula Name', 'joining_date' => '2026-01-15', 'payment_method' => 'bank',
            'bank_name' => '@SUM(1+1)', 'bank_account_no' => '1234567890', 'bank_account_name' => '=HYPERLINK("http://evil.test","Pay")',
        ]);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        $payroll = app(PayrollService::class);
        $rows = array_map(str_getcsv(...), explode("\r\n", trim($payroll->bankFile($payroll->build('2026-08-01'))['content'])));

        $this->assertCount(2, $rows, 'দৃশ্যটাই বানানো যায়নি — ফাইলে কর্মীর সারি নেই');
        [$accountNo, $name, $amount, , $bank] = $rows[1];

        $this->assertSame('\'=HYPERLINK("http://evil.test","Pay")', $name, '⛔ নামের ঘরে সূত্র — এক্সেলে খুললে চলত');
        $this->assertSame("'@SUM(1+1)", $bank, '⛔ ব্যাংকের ঘরে সূত্র');
        $this->assertSame('1234567890', $accountNo, 'সাধারণ ঘর আগের মতোই');
        $this->assertSame('20000.00', $amount, 'অঙ্কের ঘর আগের মতোই');
    }
}
