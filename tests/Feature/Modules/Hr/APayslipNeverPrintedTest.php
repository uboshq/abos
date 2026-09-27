<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * একটা বেতনশিটও কোনোদিন ছাপা হয়নি — ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ `3312f74b` (২০ সেপ্টেম্বর) ছাপার খাতা যোগ করার সময় `pdf()`-এ `(int) $id`
 * লিখেছিল, অথচ ঐ পদ্ধতিতে `$id` বলে কিছু নেই। ফল: একটা বেতনশিট হোক বা
 * রানের সবগুলো — প্রতিটা ছাপা ৫০০। ⓘ কোনো পরীক্ষা বেতনশিট ছাপত না; ধরা পড়ল
 * ফোনের §১০ পরীক্ষায়, যেটা "ওয়েবে তো খোলে" প্রমাণ করতে গিয়ে খুলতে পারেনি।
 *
 * ⭐ দাবিগুলো একই মানুষকে দুইবার ([[same-user-key-off-then-on]]), আর খাতার সারি
 * কোন নথির নামে বসল সেটাও — ভুল আইডি বসলে "কয়টা কাগজ ছাপা হলো" ভুল গুনত।
 */
final class APayslipNeverPrintedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private PayrollRun $run;

    private Payslip $slip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create([
            'code' => 'PSP-EMP', 'name_en' => 'Rafiq Islam', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        $this->run = app(PayrollService::class)->build('2026-08-01');
        $this->slip = $this->run->payslips()->firstOrFail();

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
    }

    /** ⭐ একটা বেতনশিট — চাবি ছাড়া ৪০৩, চাবি দিলে সত্যিকারের PDF আর বেতনশিটের নামে খাতা। */
    public function test_one_payslip_prints_for_the_key_and_is_logged_against_that_payslip(): void
    {
        $url = route('hr.payslip.print', $this->slip);

        $this->as()->get($url)->assertForbidden();

        $this->give('hr.payroll.view');
        $this->assertIsPdf($this->as()->get($url), 'একটা বেতনশিট');

        $this->assertLogged('hr_payslip', (int) $this->slip->id);
    }

    /** ⭐ রানের সবগুলো — চাবি ছাড়া ৪০৩, চাবি দিলে PDF আর রানের নামে খাতা। */
    public function test_the_whole_run_prints_for_the_key_and_is_logged_against_the_run(): void
    {
        $url = route('hr.payroll.payslips', $this->run);

        $this->as()->get($url)->assertForbidden();

        $this->give('hr.payroll.view');
        $this->assertIsPdf($this->as()->get($url), 'রানের সব বেতনশিট');

        $this->assertLogged('hr_payroll_run', (int) $this->run->id);
    }

    private function assertIsPdf(TestResponse $response, string $what): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent(), "⛔ {$what} ছাপা হয়নি।");
    }

    private function assertLogged(string $kind, int $id): void
    {
        $row = DocumentDelivery::query()->withoutGlobalScopes()->latest('id')->first();

        $this->assertNotNull($row, '⛔ ছাপাটা খাতায় ওঠেনি।');
        $this->assertSame($kind, $row->document_type);
        $this->assertSame($id, (int) $row->document_id, '⛔ খাতার সারি ভুল নথির নামে বসেছে।');
    }

    private function as(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->clerk->fresh());
    }

    private function give(string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
