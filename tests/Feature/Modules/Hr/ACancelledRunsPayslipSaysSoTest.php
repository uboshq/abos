<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Print\PrintableDocument;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ⛔ বাতিল রানের বেতনশিট "বাতিল" লিখে ছাপে — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৪; [[PayslipPrintController::documentFor()]])।
 *
 * ⓘ সতর্কবার্তা ছিল কেবল খসড়ার। রান বাতিল হলে (খাতায় উল্টানো) শিটটা পরিষ্কার, "যিনি দিলেন / যিনি পেলেন" সইয়ের ঘরসহ ছাপা
 * হত — খসড়ার সতর্কবার্তা যে ভুল ঠেকাতে, ঠিক সেই ভুল: কাগজটা নিয়ে টাকা চাওয়া যেত।
 */
final class ACancelledRunsPayslipSaysSoTest extends TestCase
{
    use RefreshDatabase;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create([
            'code' => 'CNP-EMP', 'name_en' => 'Cancelled Pay', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        $this->run = app(PayrollService::class)->build('2026-08-01');
    }

    public function test_a_payslip_of_a_cancelled_run_prints_with_the_cancelled_notice(): void
    {
        app(PayrollService::class)->cancel($this->run, 'Wrong month');

        $notice = $this->printedNotice();

        $this->assertNotNull($notice, '⛔ বাতিল রানের বেতনশিট কোনো সতর্কবার্তা ছাড়া, পরিষ্কার ছাপা হল');
        $this->assertTrue(
            str_contains($notice, __('hr::message.cancelled_payslip', [], 'bn')) || str_contains($notice, __('hr::message.cancelled_payslip', [], 'en')),
            "⛔ বাতিল রানের শিটে বাতিলের কথা নেই: {$notice}",
        );
    }

    public function test_a_draft_still_says_draft(): void
    {
        $notice = (string) $this->printedNotice();

        $this->assertTrue(
            str_contains($notice, __('hr::message.draft_payslip', [], 'bn')) || str_contains($notice, __('hr::message.draft_payslip', [], 'en')),
            'খসড়ার সতর্কবার্তা আগের মতোই',
        );
    }

    /** ছাপার পাতায় যাওয়া কাগজটার সতর্কবার্তা — ব্লেডে যা পৌঁছায় */
    private function printedNotice(): ?string
    {
        $documents = null;
        View::composer('*', function ($view) use (&$documents): void {
            $data = $view->getData();

            if ($documents === null && isset($data['documents']) && is_array($data['documents'])) {
                $documents = $data['documents'];
            }
        });

        $this->get(route('hr.payslip.print', $this->run->payslips()->firstOrFail()))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertIsArray($documents, 'দৃশ্যটাই বানানো যায়নি — ছাপার পাতায় কাগজ পৌঁছায়নি');
        $this->assertInstanceOf(PrintableDocument::class, $documents[0]);

        return $documents[0]->notice;
    }
}
