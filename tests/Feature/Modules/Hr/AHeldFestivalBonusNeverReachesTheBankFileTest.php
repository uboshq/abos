<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HeldWagePayment;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\HeldWages;
use App\Modules\Hr\Services\PaymentChanges;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⛔ সই-না-পড়া প্রথম ব্যাংক নম্বরের কর্মীর উৎসব ভাতাও "আটকে" — ভাতার ব্যাংক ফাইলে খালি হিসাব-নম্বরের সারি নয়; মেশানোর পরের দাবি
 * (শ্রম আইন ধারা ১২৩; a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonusPayslip()]], [[PayrollService::heldReason()]])।
 *
 * ⓘ সই-শাখা: প্রথম নম্বর দ্বিতীয় সই না পাওয়া পর্যন্ত কর্মীর সারিতে খালি, বেতনের স্লিপ "আটকে"। পে-শাখা: ভাতার স্লিপ কর্মীর পথ সোজা
 * কপি করত (`bank`) — দুটো একসাথে হলে ভাতার ফাইলে নামহীন নম্বরে টাকা যাওয়ার সারি বসত (ব্যাংক পুরো ফাইলটাই ফেরাত, বা ভুল হাতে)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · নম্বর সইয়ের অপেক্ষায় — ভাতার স্লিপ `held`; ভাতার ব্যাংক ফাইলে কেবল সই-পড়া কর্মীর সারি, খালি নম্বরের সারি নেই।
 *  · রানের "বাদ পড়া" তালিকায় সে, কারণসহ; পাওনা পুরো — ৫২২৬ ডেবিট আর প্রদেয় বেতন ক্রেডিট দুজনের ভাতারই যোগ।
 *  · সই পড়লে ভাতাটা "দেওয়ার অপেক্ষায়" ([[HeldWages::ready()]]), আর পরের মাসের বেতনের ব্যাংক ফাইলে "বকেয়া মজুরি" হয়ে ঠিক একবার।
 */
final class AHeldFestivalBonusNeverReachesTheBankFileTest extends TestCase
{
    use RefreshDatabase;
    use SignsTheSecondHand;

    private const SIGNED_NO = '1111222233334';

    private const WAITING_NO = '9999888877776';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();
        $this->travelTo('2026-05-10 10:00:00');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_waiting_first_account_holds_the_bonus_until_signed_then_it_goes_once_as_arrears(): void
    {
        $signed = $this->employeeWithSignedDetails($this->bankPerson('FB-S', self::SIGNED_NO));
        $this->basic($signed, '30000');

        $waiting = app(EmployeeService::class)->create($this->bankPerson('FB-W', self::WAITING_NO));
        $this->basic($waiting, '25000.55');
        $this->assertNotNull(app(PaymentChanges::class)->pendingFor($waiting), 'প্রস্তুতি — প্রথম নম্বর সইয়ের অপেক্ষায় নয়।');
        $this->assertNotNull(PayrollService::heldReason($waiting->fresh()), 'প্রস্তুতি — কর্মী আটকে নন।');

        $bonus = $this->confirmedPayroll(app(PayrollService::class)->buildBonus('2026-04-10', 'Eid'));
        $slip = $bonus->payslips()->where('employee_id', $waiting->id)->firstOrFail();
        $this->assertSame(PayrollService::HELD, (string) $slip->payment_method, '⛔ সই-না-পড়া নম্বরের কর্মীর ভাতা ব্যাংকের পথে।');
        $this->assertSame('bank', (string) $bonus->payslips()->where('employee_id', $signed->id)->value('payment_method'));

        // ── ভাতার ব্যাংক ফাইল — কেবল সই-পড়া কর্মী; খালি নম্বরের সারি নেই
        $file = app(PayrollService::class)->bankFile($bonus->fresh());
        $this->assertSame(1, $file['rows'], '⛔ ভাতার ফাইলে আটকে থাকা কর্মীর সারি গেল।');
        $this->assertStringContainsString(self::SIGNED_NO, $file['content']);
        $this->assertStringNotContainsString('FB-W', $file['content'], '⛔ সই-না-পড়া কর্মী ভাতার ব্যাংক ফাইলে।');
        foreach (array_slice(array_values(array_filter(explode("\r\n", $file['content']))), 1) as $line) {
            $this->assertNotSame('', (string) str_getcsv($line)[0], '⛔ ভাতার ফাইলে খালি হিসাব-নম্বরের সারি।');
        }

        // ── পাওনা পুরো, আর "বাদ পড়া" তালিকায় কারণসহ
        $left = collect(app(PayrollService::class)->leftOutOfTheBankFile($bonus->fresh()))->keyBy('code');
        $this->assertTrue($left->has('FB-W'), '⛔ আটকে থাকা ভাতা রানের "বাদ পড়া" তালিকায় নেই।');
        $this->assertFalse($left['FB-W']['paid']);
        $this->assertSame(0, bccomp($this->posted($bonus->id, StandardChart::SALARY_PAYABLE, 'credit'), '55000.55', 4),
            '⛔ আটকে থাকা ভাতা প্রদেয় বেতনে নেই — পাওনাই মুছে গেল।');

        // ── সই পড়ল — দেওয়ার অপেক্ষায়, আর মে মাসের বেতনের ফাইলে বকেয়া হয়ে একবার
        $signer = $this->secondHandIn((int) $this->company->id, 'hr.employee.view', 'hr.employee.manage', 'hr.identity.view', 'approval.decide');
        app(PaymentChanges::class)->approve(app(PaymentChanges::class)->pendingFor($waiting), $signer);
        $this->assertNull(PayrollService::heldReason($waiting->fresh()), 'প্রস্তুতি — সইয়ের পরেও আটকে।');
        $this->assertContains((int) $slip->id, app(HeldWages::class)->ready()->map(fn ($r) => (int) $r['payslip']->id)->all(),
            '⛔ সই পড়ার পরেও আটকে থাকা ভাতা দেওয়ার অপেক্ষায় নেই।');

        $may = $this->confirmedPayroll(app(PayrollService::class)->build('2026-05-01'));
        $arrears = (string) __('hr::payroll.arrears_reference', ['no' => $bonus->document_no, 'month' => 'Apr 2026']);
        $content = (string) app(PayrollService::class)->bankFile($may->fresh())['content'];
        $this->assertSame(1, substr_count($content, $arrears), '⛔ ভাতার বকেয়া পরের বেতনের ফাইলে ঠিক একবার নয়।');
        $this->assertStringContainsString(self::WAITING_NO, $content);
        $this->assertSame(1, HeldWagePayment::query()->where('payslip_id', $slip->id)->count());
    }

    /** @return array<string, mixed> */
    private function bankPerson(string $code, string $account): array
    {
        return ['code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => '2025-01-15', 'payment_method' => 'bank',
            'bank_name' => 'Sonali', 'bank_account_no' => $account, 'bank_routing_no' => '200270001'];
    }

    private function basic(Employee $employee, string $amount): void
    {
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2025-01-15', $amount);
    }

    private function posted(int $runId, string $code, string $side): string
    {
        return bcadd((string) \App\Models\LedgerEntry::query()->where('source_type', \App\Modules\Hr\Models\PayrollRun::SOURCE_TYPE)
            ->where('source_id', $runId)->where('account_id', StandardChart::find($code)->id)->sum($side), '0', 4);
    }
}
