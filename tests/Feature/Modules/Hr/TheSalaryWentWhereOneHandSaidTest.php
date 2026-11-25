<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PaymentChange;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PaymentChanges;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ একজনই কর্মীর বেতনের ঠিকানা বদলাতেন, আর পরের বেতন সেখানেই যেত — আন্তর্জাতিক মানের ফাঁক #৮ (গুরুতর), ২০ অক্টোবর ২০২৬
 * (fe; gaps-core-hr.md #৯; cb ee99f3eaa; [[PaymentChanges]])। ⭐ সই এখন ইঞ্জিনে, মালিকের ব্যতিক্রমে — সব ছক বন্ধ থাকলেও (a6, ২১ অক্টোবর ২০২৬)।
 *
 * দাবি (UB-র মতো সব ছক বন্ধ কোম্পানিতে; কর্মীর সম্পাদনার দরজা, পাতার সই-বোতাম, ওয়েবের ইনবক্স আর ফোন দিয়ে):
 *  - চাবিওয়ালা কেরানি নম্বর বদলালে কর্মীর সারি বদলায় না — "অপেক্ষার বদল" আর যন্ত্রের "দ্বিতীয় সই"-এর অনুরোধ; পরিচয়ের চাবি ছাড়া
 *    পাতায় নম্বর ঢাকা; সইকারী আর কর্মী খবর পান;
 *  - অপেক্ষার সময়ের বেতনের রানে স্লিপ পুরনো হিসাবের;
 *  - লেখক নিজে সই দিতে পারেন না (সব চাবি থাকলেও); চাবিহীন অন্যজনও না; ফোন এটা দেখায়ও না, সইও নেয় না; চাবিওয়ালা অন্যজন দিলে
 *    বসে — আগেরটা `previous`-এ, ইনবক্স থেকেও একই;
 *  - ডাটাবেজে আর নিরীক্ষার খাতায় নতুন নম্বর খোলা লেখা নেই;
 *  - "না" দিলে বা লেখক ফিরিয়ে নিলে কর্মীর সারি যেমন ছিল; মালিক নিজে বদলালেও অপেক্ষায়, নিজে সই দিলে বসে আর অডিটে
 *    `owner_self_signed`; নিয়মটা বন্ধের কোনো সুইচ নেই; কোনো ছক বসে না।
 */
final class TheSalaryWentWhereOneHandSaidTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\PutsMoneyInTheTill;
    use \Tests\Concerns\SeesTheOwnersOwnMark;
    use \Tests\SignsTheSecondHand;

    private const OLD = '1234567890123';

    private const NEW = '5555555555555';

    /** ⓘ বদলটা লেখার চাবি — আর সেই চাবিই দ্বিতীয় হাতের ([[ApprovalRules::secondHand()]]), সাথে সই দেওয়ার দরজা */
    private const HANDS = ['hr.employee.view', 'hr.employee.manage', 'hr.identity.view', 'approval.decide'];

    private Company $company;

    private User $owner;

    private User $clerk;

    private User $signer;

    private User $worker;

    /** ⓘ কর্মী দেখেন, বদলাতে পারেন না — সইকারী নন */
    private User $looker;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ UB-র মতো — ছক আছে, একটাও চালু নয়
        // ⓘ the demo already has this row since 1.3.10 — make it exist and stay off, never a second copy (unique flow scope)
        ApprovalFlow::query()->updateOrCreate(['company_id' => $this->company->id, 'module' => 'sales', 'action' => 'discount',
            'document_type' => ''], ['threshold_amount' => null, 'is_active' => false]);
        app()->forgetInstance(ApprovalEngine::class);

        $this->clerk = $this->person(self::HANDS);
        $this->signer = $this->person(self::HANDS);
        $this->worker = $this->person([]);
        $this->looker = $this->person(['hr.employee.view']);

        app(SalaryHeadService::class)->installDefaults();
        $this->employee = $this->employeeWithSignedDetails([
            'code' => 'EMP-PAY', 'name_en' => 'Paid Person', 'joining_date' => '2026-01-15', 'user_id' => $this->worker->id,
            'payment_method' => 'bank', 'bank_account_no' => self::OLD, 'bank_name' => 'Sonali', 'bank_routing_no' => '200270001',
        ]);
        app(SalaryStructureService::class)->set($this->employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        // ⓘ প্রস্তুতির প্রথম তথ্য দ্বিতীয় সইয়ে গেছে (নিচের দাবি দেখে); তার খবরগুলো এই ফাইলের গোনায় না আসুক
        Notification::query()->withoutGlobalScopes()->delete();
    }

    /**
     * ⛔ নতুন কর্মীর প্রথম ব্যাংক তথ্যও দ্বিতীয় সইয়ে, আর সই না পড়া পর্যন্ত বেতন সেখানে নয় — বেতনশিট "আটকে", ব্যাংক ফাইলে নাম নেই,
     * রানের পাতা আর সঙ্গী-তালিকায় বাংলা কারণ; সই পড়লে পরের ব্যাংক ফাইলে বকেয়া মজুরি হয়ে (নিচের দাবি; fe, ২১ অক্টোবর ২০২৬)।
     */
    public function test_a_new_employees_first_bank_details_wait_and_the_salary_is_held_until_signed(): void
    {
        $this->as($this->clerk);
        $fresh = app(EmployeeService::class)->create([
            'code' => 'EMP-NEW', 'name_en' => 'New Joiner', 'joining_date' => '2026-01-15',
            'payment_method' => 'bank', 'bank_account_no' => self::NEW, 'bank_name' => 'Sonali', 'bank_routing_no' => '200270001',
        ]);
        app(SalaryStructureService::class)->set($fresh, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '15000');

        $this->assertNull($fresh->fresh()->bank_account_no, '⛔ নতুন কর্মীর প্রথম ব্যাংক নম্বর একজনের হাতেই বসল');
        $change = app(PaymentChanges::class)->pendingFor($fresh);
        $this->assertNotNull($change, '⛔ প্রথম তথ্যের সইয়ের অনুরোধ নেই');
        $this->assertSame(self::NEW, $change->proposed['bank_account_no']);

        $this->as($this->owner);
        $run = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $slip = $run->payslips()->where('employee_id', $fresh->id)->firstOrFail();
        $this->assertSame(PayrollService::HELD, (string) $slip->payment_method, '⛔ সই-না-পড়া হিসাবের বেতন আটকে নয়');

        $file = (string) app(PayrollService::class)->bankFile($run->fresh())['content'];
        $this->assertStringNotContainsString('EMP-NEW', $file, '⛔ সই-না-পড়া কর্মী ব্যাংক ফাইলে');
        $this->assertStringContainsString('EMP-PAY', $file, 'প্রস্তুতি — সই-পড়া কর্মী ফাইলে নেই');

        $reason = (string) __('hr::payroll.held_waiting', [], 'bn');
        $this->assertStringContainsString('দ্বিতীয় অনুমোদনের অপেক্ষায়', $reason);
        $this->as($this->owner)->get(route('hr.payroll.show', $run->id))->assertOk()
            ->assertSee('data-bank-left-out', false)->assertSee('EMP-NEW', false)->assertSee(__('hr::payroll.held_waiting'), false);
        $report = $this->as($this->owner)->get(route('hr.payroll.bank_file_left_out', $run->id))->assertOk();
        $this->assertStringContainsString('EMP-NEW', (string) $report->getContent());
        $this->assertStringContainsString((string) __('hr::payroll.held_waiting'), (string) $report->getContent());

        // ⓘ সই পড়ল — এখন সারিতে; পরের মাসের রান ব্যাংকে পাঠায়
        $this->as($this->signer)->post(route('hr.employee.payment_change.approve', [$fresh, $change]))->assertSessionHasNoErrors();
        $this->assertSame(self::NEW, (string) $fresh->fresh()->bank_account_no);
        $this->assertNull(PayrollService::heldReason($fresh->fresh()));
    }

    /** ⭐ অনুমোদনের কেন্দ্রের সাধারণ "ফিরিয়ে নিন" — অপেক্ষার বদলটাও বন্ধ, অডিটে; আগের হিসাব থাকে (fe, ২১ অক্টোবর ২০২৬) */
    public function test_withdrawing_from_the_approval_centre_closes_the_waiting_change_too(): void
    {
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]));
        $change = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();
        $approval = app(PaymentChanges::class)->approvalOf($change);

        $this->give($this->clerk, 'approval.view');
        $this->as($this->clerk)->post(route('approval.inbox.withdraw', $approval->id))->assertRedirect();

        $this->assertSame(Approval::CANCELLED, (string) $approval->fresh()->status);
        $this->assertSame(PaymentChange::CANCELLED, $change->fresh()->status, '⛔ অনুরোধ ফিরল, অথচ বদলটা "অপেক্ষায়" রয়ে গেল');
        $this->assertSame((string) __('hr::payment_change.withdrawn_note'), (string) $change->fresh()->note);
        $this->assertTrue(AuditTrail::query()->forRecord(PaymentChange::class, (int) $change->id)
            ->where('action', 'payment_change_withdrawn')->exists(), '⛔ ফেরানো অডিটে নেই');
        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no, '⛔ ফেরানোর পরে হিসাব বদলাল');
        $this->assertNull(app(PaymentChanges::class)->pendingFor($this->employee));
    }

    /**
     * ⛔ "আটকে" মানে কেবল ব্যাংক ফাইলে নেই — পাওনা নয় এমন নয় (শ্রম আইন ধারা ১২৩; fe, ২১ অক্টোবর ২০২৬):
     *  - রান পোস্ট হলে আটকে থাকা নিটও প্রদেয় বেতনে, পুরো;
     *  - রানের পাতা আর HR-এর ড্যাশবোর্ডে আইনের শেষ দিন (মাস শেষ + ৭ কর্মদিবস, শুক্রবার বাদ), পেরোলে লাল;
     *  - সই পড়লে আবার বানানো ছাড়াই পরের ব্যাংক ফাইলে "বকেয়া মজুরি" — ঠিক একবার, তার পরের ফাইলে আর নয়;
     *  - সব ফাইলের টাকা দিলে প্রদেয় বেতন পয়সায় পয়সায় শূন্য।
     */
    public function test_a_held_wage_stays_owed_and_is_paid_once_in_the_next_bank_file(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        $this->as($this->clerk);
        $fresh = app(EmployeeService::class)->create([
            'code' => 'EMP-NEW', 'name_en' => 'New Joiner', 'joining_date' => '2026-01-15',
            'payment_method' => 'bank', 'bank_account_no' => self::NEW, 'bank_name' => 'Sonali', 'bank_routing_no' => '200270001',
        ]);
        app(SalaryStructureService::class)->set($fresh, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '15000');

        $this->as($this->owner);
        $august = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $held = $august->payslips()->where('employee_id', $fresh->id)->firstOrFail();
        $this->assertSame(PayrollService::HELD, (string) $held->payment_method);

        // ── পাওনা, পুরো: প্রদেয় বেতনে সব স্লিপের নিট, আটকে থাকাটাসহ
        $payable = (int) \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::SALARY_PAYABLE)->id;
        $credited = fn (int $runId) => (string) DB::table('ledger_entries')->where('source_type', \App\Modules\Hr\Models\PayrollRun::SOURCE_TYPE)
            ->where('source_id', $runId)->where('account_id', $payable)->sum(DB::raw('credit - debit'));
        $nets = (string) $august->payslips()->sum('net');
        $this->assertSame(0, bccomp($nets, $credited($august->id), 4), '⛔ আটকে থাকা মজুরি প্রদেয় বেতনে নেই — পাওনাই মুছে গেল');
        $this->assertSame(1, bccomp((string) $held->net, '0', 4));

        // ── আইনের শেষ দিন: ৩১ আগস্ট ২০২৬ (সোম) + ৭ কর্মদিবস, শুক্রবার বাদ = ৮ সেপ্টেম্বর; আজ ৫ ডিসেম্বর — লাল
        $this->assertSame('2026-09-08', PayrollService::wageDeadline($august)->toDateString());
        $this->get(route('hr.payroll.show', $august->id))->assertOk()
            ->assertSee('data-held-late', false)->assertSee(\App\Core\Support\DateFormat::format('2026-09-08'), false)->assertSee(__('hr::payroll.held_late'), false);
        // ⓘ "রান আবার বানান" নির্দেশটা আর নেই — বকেয়া পরের ফাইলে যায়
        $this->assertSame('hr::payroll.held_rebuild', __('hr::payroll.held_rebuild'));
        $this->get(route('module.dashboard', ['module' => 'hr']))->assertOk()
            ->assertSee(__('hr::payroll.held_owed_title'), false)->assertSee('New Joiner', false)->assertSee(__('hr::payroll.held_late'), false);

        // ── সই পড়ার আগে পরের মাসের ফাইলেও বকেয়া নয় — হিসাব এখনো সই-না-পড়া
        $september = $this->confirmedPayroll(app(PayrollService::class)->build('2026-09-01'));
        $augustArrears = (string) __('hr::payroll.arrears_reference', ['no' => $august->document_no, 'month' => 'Aug 2026']);
        $septemberArrears = (string) __('hr::payroll.arrears_reference', ['no' => $september->document_no, 'month' => 'Sep 2026']);
        $this->assertStringNotContainsString($augustArrears, (string) app(PayrollService::class)->bankFile($september->fresh())['content'],
            '⛔ সই-না-পড়া হিসাবে বকেয়া মজুরি গেল');
        $this->assertSame(0, \App\Modules\Hr\Models\HeldWagePayment::query()->count());

        // ── সই পড়ল — পোস্ট-হওয়া রান ছোঁয়া হয় না
        $change = app(PaymentChanges::class)->pendingFor($fresh);
        $this->as($this->signer)->post(route('hr.employee.payment_change.approve', [$fresh, $change]))->assertSessionHasNoErrors();
        $this->assertSame(PayrollService::HELD, (string) $held->fresh()->payment_method, '⛔ পোস্ট-হওয়া রানের স্লিপ বদলাল');

        $this->as($this->owner);
        // ⓘ আগের মাসের ফাইল আবার নামালে পরের রানের আটকে থাকা মজুরি সেখানে যায় না — বকেয়া কেবল পরের ফাইলে
        $this->assertStringNotContainsString($septemberArrears, (string) app(PayrollService::class)->bankFile($august->fresh())['content']);
        $this->assertSame(0, \App\Modules\Hr\Models\HeldWagePayment::query()->count(), '⛔ আগের ফাইল পরের মাসের বকেয়া নিল');
        $october = $this->confirmedPayroll(app(PayrollService::class)->build('2026-10-01'));
        $first = (string) app(PayrollService::class)->bankFile($october->fresh())['content'];
        $again = (string) app(PayrollService::class)->bankFile($october->fresh())['content'];
        foreach ([$augustArrears, $septemberArrears] as $arrears) {
            $this->assertSame(1, substr_count($first, $arrears), '⛔ বকেয়া মজুরি পরের ফাইলে একবার নয়: '.$arrears);
            $this->assertSame(1, substr_count($again, $arrears), '⛔ একই ফাইল আবার নামালে বকেয়া দুইবার');
        }
        $this->assertSame(1, \App\Modules\Hr\Models\HeldWagePayment::query()->where('payslip_id', $held->id)->count());
        $this->assertStringNotContainsString($augustArrears, (string) app(PayrollService::class)->bankFile($september->fresh())['content'],
            '⛔ সেপ্টেম্বরের ফাইল (আবার নামানো) অক্টোবরের বকেয়া কেড়ে নিল');

        // ── তিন ফাইলের টাকা দিলে প্রদেয় বেতন শূন্য, পয়সায় পয়সায়
        $paid = '0';
        foreach ([app(PayrollService::class)->bankFile($august->fresh())['content'], app(PayrollService::class)->bankFile($september->fresh())['content'], $first] as $file) {
            foreach (array_slice(array_values(array_filter(explode("\r\n", (string) $file))), 1) as $line) {
                $paid = bcadd($paid, (string) str_getcsv($line)[2], 4);
            }
        }
        app(\App\Core\Engines\Posting\PostingEngine::class)->post(sourceType: 'test.salary_paid', sourceId: 1, trxDate: '2026-11-01', lines: [
            ['account_id' => $payable, 'debit' => $paid, 'credit' => '0'],
            ['account_id' => (int) \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => $paid],
        ], documentNo: 'SAL-PAID', branchId: $this->company->defaultBranch()?->id);
        $this->assertSame(0, bccomp('0', (string) DB::table('ledger_entries')->where('account_id', $payable)->sum(DB::raw('credit - debit')), 4),
            '⛔ তিন ফাইলের টাকা দিয়েও প্রদেয় বেতন শূন্য হলো না');

        $this->get(route('hr.payroll.show', $august->id))->assertOk()->assertDontSee('data-held-late', false)
            ->assertSee(__('hr::payroll.held_paid_later', ['no' => $october->document_no]), false);
        $this->assertCount(0, app(PayrollService::class)->heldWagesOwed());

        // ── তার পরের ফাইলে আর নয়
        $november = $this->confirmedPayroll(app(PayrollService::class)->build('2026-11-01'));
        $later = (string) app(PayrollService::class)->bankFile($november->fresh())['content'];
        $this->assertStringNotContainsString($augustArrears, $later, '⛔ বকেয়া মজুরি আবার পরের ফাইলে — দুইবার টাকা');
        $this->assertStringNotContainsString($septemberArrears, $later, '⛔ বকেয়া মজুরি আবার পরের ফাইলে — দুইবার টাকা');
    }

    /**
     * ⭐ MFS-এর কর্মীর আটকে থাকা মজুরি — নম্বর সই পড়ার দিনই "দেওয়ার অপেক্ষায়", একবারই দেওয়া যায়, দ্বিতীয় চাপ ফেরে, আর ভাউচার পাকা
     * হলে প্রদেয় বেতন পয়সায় পয়সায় শূন্য (fe, ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা ১২৩; [[HeldWages]])।
     */
    public function test_an_mfs_employees_held_wage_is_ready_the_day_it_is_signed_and_is_paid_once(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        $this->as($this->clerk);
        $mfs = app(EmployeeService::class)->create([
            'code' => 'EMP-MFS', 'name_en' => 'Wallet Worker', 'joining_date' => '2026-01-15',
            'payment_method' => 'mfs', 'mfs_number' => '01712345678',
        ]);
        app(SalaryStructureService::class)->set($mfs, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '12000');

        $this->as($this->owner);
        $august = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $held = $august->payslips()->where('employee_id', $mfs->id)->firstOrFail();
        $this->assertSame(PayrollService::HELD, (string) $held->payment_method);
        $this->assertCount(0, app(\App\Modules\Hr\Services\HeldWages::class)->ready(), '⛔ সই-না-পড়া নম্বরে দেওয়ার অপেক্ষায়');

        // ── নম্বর সই পড়ল — সেদিনই তালিকায়, পথ MFS
        $change = app(PaymentChanges::class)->pendingFor($mfs);
        $this->as($this->signer)->post(route('hr.employee.payment_change.approve', [$mfs, $change]))->assertSessionHasNoErrors();
        $ready = app(\App\Modules\Hr\Services\HeldWages::class)->ready();
        $this->assertCount(1, $ready, '⛔ সইয়ের দিনই বকেয়া দেওয়ার অপেক্ষায় নয়');
        $this->assertSame('mfs', $ready->first()['channel']);
        $this->as($this->owner)->get(route('hr.payroll.held.index'))->assertOk()
            ->assertSee('data-held-line="EMP-MFS"', false)->assertSee(__('hr::kind.mfs'), false);
        $this->as($this->owner)->get(route('module.dashboard', ['module' => 'hr']))->assertOk()
            ->assertSee(__('hr::payroll.held_ready_on', ['way' => __('hr::kind.mfs')]), false);

        // ── দেওয়া — MFS-এর খাত থেকে, খসড়া ভাউচার আর তালিকা
        $wallet = (int) \App\Modules\Accounts\Models\Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1105-HLD', 'name_en' => 'bKash Payroll', 'name_bn' => 'বিকাশ বেতন',
            'parent_id' => \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::MOBILE_MONEY)->id,
            'type' => \App\Modules\Accounts\Models\Account::ASSET, 'nature' => \App\Modules\Accounts\Models\Account::DEBIT,
            'money_kind' => \App\Modules\Accounts\Models\Account::MFS, 'is_active' => true, 'status' => \App\Core\Support\DocumentStatus::CONFIRMED,
        ])->id;
        // ⓘ খাতে টাকা — মোবাইলের জের শূন্যের নিচে নামে না, নইলে ভাউচার অনুমোদনে নয়, টাকার অভাবে থামত
        $this->putMoneyIn(\App\Modules\Accounts\Models\Account::query()->findOrFail($wallet), '50000');
        // ⛔ ব্যাংকের পথে MFS খাত — ফেরে, কিছুই লেখা হয় না
        $this->as($this->owner)->post(route('hr.payroll.held.pay', ['channel' => 'bank']), ['payslips' => [$held->id], 'account_id' => $wallet])
            ->assertStatus(422);
        $this->assertSame(0, \App\Modules\Hr\Models\HeldWagePayment::query()->count(), '⛔ ভুল পথের চাপে দেওয়া লেখা হলো');

        $paper = $this->as($this->owner)->post(route('hr.payroll.held.pay', ['channel' => 'mfs']), ['payslips' => [$held->id], 'account_id' => $wallet])->assertOk();
        $this->assertStringContainsString('01712345678', (string) $paper->getContent());
        $this->assertStringContainsString(\App\Core\Support\Money::round((string) $held->net, 2), (string) $paper->getContent());

        $payment = \App\Modules\Hr\Models\HeldWagePayment::query()->where('payslip_id', $held->id)->sole();
        $this->assertSame('mfs', (string) $payment->channel);
        $this->assertNull($payment->paid_in_run_id);
        $this->assertCount(0, app(\App\Modules\Hr\Services\HeldWages::class)->ready(), '⛔ দেওয়ার পরেও তালিকায়');

        // ⛔ দ্বিতীয় চাপ — ফেরে, কিছুই লেখা হয় না
        $this->as($this->owner)->from(route('hr.payroll.held.index'))
            ->post(route('hr.payroll.held.pay', ['channel' => 'mfs']), ['payslips' => [$held->id], 'account_id' => $wallet])
            ->assertSessionHasErrors('payslips');
        $this->assertSame(1, \App\Modules\Hr\Models\HeldWagePayment::query()->where('payslip_id', $held->id)->count());
        $this->assertSame(1, \App\Modules\Accounts\Models\Voucher::query()->where('id', $payment->voucher_id)->count());

        // ── ভাউচার পাকা (হিসাবের পথ) + আগস্টের ব্যাংক ফাইলের টাকা — প্রদেয় বেতন শূন্য
        app(\App\Modules\Accounts\Services\VoucherService::class)->post(\App\Modules\Accounts\Models\Voucher::query()->findOrFail($payment->voucher_id));
        $payable = (int) \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::SALARY_PAYABLE)->id;
        $bankPaid = '0';
        foreach (array_slice(array_values(array_filter(explode("\r\n", (string) app(PayrollService::class)->bankFile($august->fresh())['content']))), 1) as $line) {
            $bankPaid = bcadd($bankPaid, (string) str_getcsv($line)[2], 4);
        }
        app(\App\Core\Engines\Posting\PostingEngine::class)->post(sourceType: 'test.salary_paid', sourceId: 2, trxDate: '2026-09-02', lines: [
            ['account_id' => $payable, 'debit' => $bankPaid, 'credit' => '0'],
            ['account_id' => (int) \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => $bankPaid],
        ], documentNo: 'SAL-PAID-2', branchId: $this->company->defaultBranch()?->id);
        $this->assertSame(0, bccomp('0', (string) DB::table('ledger_entries')->where('account_id', $payable)->sum(DB::raw('credit - debit')), 4),
            '⛔ বকেয়া দিয়েও প্রদেয় বেতন শূন্য নয়');

        $this->get(route('hr.payroll.show', $august->id))->assertOk()->assertDontSee('data-held-late', false);
    }

    /** ⭐ ব্যাংকের বকেয়া ফাইল যেকোনো দিন — লাইনটা একবার, আর পরের রানের ফাইল তখন সেটা বাদ দেয় */
    public function test_the_bank_arrears_file_carries_a_line_once_and_the_next_runs_file_skips_it(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        $this->as($this->clerk);
        $fresh = app(EmployeeService::class)->create([
            'code' => 'EMP-ARR', 'name_en' => 'Arrears Bank', 'joining_date' => '2026-01-15',
            'payment_method' => 'bank', 'bank_account_no' => self::NEW, 'bank_name' => 'Sonali', 'bank_routing_no' => '200270001',
        ]);
        app(SalaryStructureService::class)->set($fresh, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '15000');

        $this->as($this->owner);
        $august = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $held = $august->payslips()->where('employee_id', $fresh->id)->firstOrFail();
        $change = app(PaymentChanges::class)->pendingFor($fresh);
        $this->as($this->signer)->post(route('hr.employee.payment_change.approve', [$fresh, $change]))->assertSessionHasNoErrors();

        $bank = (int) \App\Modules\Accounts\Models\Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-HLD', 'name_en' => 'Payroll Bank', 'name_bn' => 'বেতনের ব্যাংক',
            'parent_id' => \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::BANK)->id,
            'type' => \App\Modules\Accounts\Models\Account::ASSET, 'nature' => \App\Modules\Accounts\Models\Account::DEBIT,
            'money_kind' => \App\Modules\Accounts\Models\Account::BANK, 'is_active' => true, 'status' => \App\Core\Support\DocumentStatus::CONFIRMED,
        ])->id;
        $arrears = (string) __('hr::payroll.arrears_reference', ['no' => $august->document_no, 'month' => 'Aug 2026']);

        $this->as($this->owner);
        $file = (string) $this->post(route('hr.payroll.held.pay', ['channel' => 'bank']), ['payslips' => [$held->id], 'account_id' => $bank])
            ->assertOk()->getContent();
        $this->assertSame(1, substr_count($file, $arrears), '⛔ বকেয়ার ফাইলে লাইনটা একবার নয়');
        $this->assertStringContainsString(self::NEW, $file);

        $payment = \App\Modules\Hr\Models\HeldWagePayment::query()->where('payslip_id', $held->id)->sole();
        $again = (string) $this->get(route('hr.payroll.held.paper', $payment->voucher_id))->assertOk()->getContent();
        $this->assertSame(1, substr_count($again, $arrears), '⛔ আবার নামালে একই কাগজ নয়');

        // ⛔ পরের রানের ফাইল আর বহন করে না — এক খাতা
        $september = $this->confirmedPayroll(app(PayrollService::class)->build('2026-09-01'));
        $this->assertStringNotContainsString($arrears, (string) app(PayrollService::class)->bankFile($september->fresh())['content'],
            '⛔ বকেয়ার ফাইলে দেওয়া লাইন পরের রানের ফাইলেও — দুইবার টাকা');
        $this->assertSame(1, \App\Modules\Hr\Models\HeldWagePayment::query()->count());
        $this->assertNull($payment->fresh()->paid_in_run_id);
    }

    /**
     * ⭐ মজুরি চিরকাল আটকে থাকে না (ধারা ১২৩; fe, ২১ অক্টোবর ২০২৬): বকেয়ার খসড়া ভাউচার বাতিল হলে বা সইয়ে "না" পেলে মজুরি আবার
     * "দেওয়ার অপেক্ষায়", কর্মীর অডিটে কারণসহ, আর আবার দেওয়া যায় — একবারই; পাকা ভাউচার বাতিল হলেও কখনো ছাড়া হয় না।
     */
    public function test_a_cancelled_or_rejected_arrears_voucher_releases_the_wage_and_a_posted_one_never_does(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        $this->as($this->clerk);
        $mfs = app(EmployeeService::class)->create([
            'code' => 'EMP-REL', 'name_en' => 'Released Wage', 'joining_date' => '2026-01-15', 'payment_method' => 'mfs', 'mfs_number' => '01799999999',
        ]);
        app(SalaryStructureService::class)->set($mfs, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '12000');
        $this->as($this->owner);
        $august = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $held = $august->payslips()->where('employee_id', $mfs->id)->firstOrFail();
        $this->as($this->signer)->post(route('hr.employee.payment_change.approve', [$mfs, app(PaymentChanges::class)->pendingFor($mfs)]));

        $wallet = (int) \App\Modules\Accounts\Models\Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1105-REL', 'name_en' => 'Nagad Payroll', 'name_bn' => 'নগদ বেতন',
            'parent_id' => \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::MOBILE_MONEY)->id,
            'type' => \App\Modules\Accounts\Models\Account::ASSET, 'nature' => \App\Modules\Accounts\Models\Account::DEBIT,
            'money_kind' => \App\Modules\Accounts\Models\Account::MFS, 'is_active' => true, 'status' => \App\Core\Support\DocumentStatus::CONFIRMED,
        ])->id;
        // ⓘ খাতে টাকা — মোবাইলের জের শূন্যের নিচে নামে না, নইলে ভাউচার অনুমোদনে নয়, টাকার অভাবে থামত
        $this->putMoneyIn(\App\Modules\Accounts\Models\Account::query()->findOrFail($wallet), '50000');
        $held_ = fn () => app(\App\Modules\Hr\Services\HeldWages::class)->ready()->pluck('payslip.id')->map(fn ($id) => (int) $id)->all();
        $pay = function () use ($held, $wallet): \App\Modules\Accounts\Models\Voucher {
            $this->as($this->owner)->post(route('hr.payroll.held.pay', ['channel' => 'mfs']), ['payslips' => [$held->id], 'account_id' => $wallet])->assertOk();

            return \App\Modules\Accounts\Models\Voucher::query()->findOrFail(
                \App\Modules\Hr\Models\HeldWagePayment::query()->where('payslip_id', $held->id)->sole()->voucher_id);
        };
        $vouchers = app(\App\Modules\Accounts\Services\VoucherService::class);

        // ── ১. খসড়া বাতিল — আবার তালিকায়, অডিটে কারণ
        $first = $pay();
        $this->assertNotContains((int) $held->id, $held_());
        $vouchers->cancel($first->fresh(), 'wrong wallet');
        $this->assertSame(0, \App\Modules\Hr\Models\HeldWagePayment::query()->where('payslip_id', $held->id)->count(), '⛔ বাতিল ভাউচারে মজুরি আটকে রইল');
        $this->assertContains((int) $held->id, $held_(), '⛔ বাতিলের পরে মজুরি দেওয়ার অপেক্ষায় ফেরেনি');
        $this->assertTrue(AuditTrail::query()->forRecord(Employee::class, (int) $mfs->id)->where('action', 'held_wage_released')
            ->where('reason', 'like', '%'.$first->document_no.'%')->exists(), '⛔ ছাড়ার কথা কর্মীর অডিটে নেই');
        $this->as($this->owner)->get(route('hr.payroll.held.index'))->assertOk()->assertSee('data-held-line="EMP-REL"', false);

        // ── ২. সইয়ে "না" — আবার তালিকায়
        $checker = $this->person(['approval.decide']);
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'payment',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        \App\Models\ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => \App\Models\ApprovalFlowStep::BY_USER, 'approver_id' => $checker->id]);
        app()->forgetInstance(ApprovalEngine::class);

        $second = $pay();
        // ⓘ অনুমোদন চায় পোস্টের দরজা ([[VoucherApproval::stopping()]]), সেবার post() নিজে নয় — তাই আসল দরজা দিয়ে
        $this->as($this->owner)->post(route('accounts.voucher.post', $second));
        $this->assertTrue($second->fresh()->isDraft(), 'প্রস্তুতি — ভাউচার অনুমোদনের অপেক্ষায় থামেনি');
        $asked = Approval::query()->where('approvable_type', $second->getMorphClass())->where('approvable_id', $second->id)
            ->where('status', Approval::PENDING)->latest('id')->firstOrFail();
        app(ApprovalEngine::class)->reject($asked, $checker->fresh(), 'not this month');
        $this->assertContains((int) $held->id, $held_(), '⛔ "না" পাওয়া ভাউচারে মজুরি আটকে রইল');
        $this->assertTrue($second->fresh()->isCancelled(), '⛔ "না" পাওয়া খসড়া রয়ে গেল — পরে পাকা হলে একই মজুরি দুইবার');

        // ── ৩. আবার দেওয়া — একবারই; পাকা হলো, তারপর বাতিল — ছাড়া হয় না
        $flow->update(['is_active' => false]);
        app()->forgetInstance(ApprovalEngine::class);
        $third = $pay();
        $this->as($this->owner)->from(route('hr.payroll.held.index'))
            ->post(route('hr.payroll.held.pay', ['channel' => 'mfs']), ['payslips' => [$held->id], 'account_id' => $wallet])
            ->assertSessionHasErrors('payslips');
        $vouchers->post($third->fresh());
        $vouchers->cancel($third->fresh(), 'reversed after posting');
        $this->assertSame(1, \App\Modules\Hr\Models\HeldWagePayment::query()->where('payslip_id', $held->id)->count(),
            '⛔ পাকা ভাউচার বাতিলে মজুরি ছাড়া পেল — টাকা হয়তো সত্যিই গেছে, দুইবার দেওয়ার পথ খুলল');
        $this->assertNotContains((int) $held->id, $held_());
    }

    /** ⛔ খালি থেকে ব্যাংক — আগের পথ (নগদ) চলে, ব্যাংকে নয়, সই না পড়া পর্যন্ত */
    public function test_bank_details_set_from_empty_fall_back_to_the_previous_method_until_signed(): void
    {
        $cash = app(EmployeeService::class)->create([
            'code' => 'EMP-CASH', 'name_en' => 'Cash Person', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($cash, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '15000');

        $this->as($this->clerk)->put(route('hr.employee.update', $cash), [
            'name_en' => 'Cash Person', 'joining_date' => '2026-01-15', 'payment_method' => 'bank', 'bank_name' => 'Sonali',
            'bank_account_no' => self::NEW, 'bank_routing_no' => '200270001', 'photo' => $this->png(),
        ])->assertSessionHasNoErrors();
        $this->assertSame('cash', (string) $cash->fresh()->payment_method, '⛔ খালি থেকে ব্যাংক একজনের হাতেই বসল');

        $this->as($this->owner);
        $run = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $this->assertSame('cash', (string) $run->payslips()->where('employee_id', $cash->id)->firstOrFail()->payment_method,
            '⛔ সই-না-পড়া ব্যাংক হিসাবে বেতন — আগের পথ (নগদ) নয়');
        $this->assertStringNotContainsString('EMP-CASH', (string) app(PayrollService::class)->bankFile($run->fresh())['content']);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_one_hand_asks_another_signs_and_the_salary_waits_at_the_old_account(): void
    {
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]))
            ->assertSessionHasNoErrors()->assertSessionHas('saved', __('hr::payment_change.waiting_saved'));

        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no, '⛔ সব ছক বন্ধ, আর একজনের হাতেই বেতনের ঠিকানা বদলাল');
        $change = PaymentChange::query()->where('employee_id', $this->employee->id)->where('status', PaymentChange::PENDING)->firstOrFail();
        $this->assertSame(self::NEW, $change->proposed['bank_account_no']);

        $approval = app(PaymentChanges::class)->approvalOf($change);
        $this->assertNotNull($approval, '⛔ বদলের সইয়ের অনুরোধ বসেনি');
        $this->assertSame(Approval::PENDING, (string) $approval->status);
        $this->assertTrue(app(ApprovalEngine::class)->isSecondHand($approval), '⛔ অনুরোধটা যন্ত্রের "দ্বিতীয় সই" ছকে নয়');
        $this->assertSame(0, ApprovalFlow::query()->where('module', 'hr')->count(), '⛔ দ্বিতীয় সইয়ের জন্য ছক বসল');
        $this->assertStringNotContainsString(self::NEW, (string) DB::table('approvals')->where('id', $approval->id)->value('payload')
            .DB::table('approvals')->where('id', $approval->id)->value('requested_reason'), '⛔ অনুরোধে নতুন নম্বর খোলা');

        // ⓘ সইকারী আর কর্মী খবর পান — কর্মী জানেন কেউ তাঁর ঠিকানা ছুঁতে চাইছেন
        $this->assertSame(1, $this->notices($this->signer, PaymentChanges::WAITING), '⛔ সইকারী জানলেন না');
        $this->assertSame(1, $this->notices($this->worker, PaymentChanges::TOLD), '⛔ কর্মী জানলেন না তাঁর বেতনের ঠিকানা বদলাতে চাওয়া হয়েছে');
        $this->assertSame(0, $this->notices($this->clerk, PaymentChanges::WAITING), 'লেখক নিজের সইয়ের খবর পেলেন');
        $this->assertSame(0, $this->notices($this->looker, PaymentChanges::WAITING), '⛔ সইকারী নন এমন মানুষ সইয়ের খবর পেলেন');

        // ⓘ পাতায় অপেক্ষার বদল — সইকারীর চোখে পুরো নম্বর আর বোতাম; পরিচয়ের চাবি ছাড়া কেবল শেষ চার অঙ্ক, বোতাম নেই
        $this->as($this->signer)->get(route('hr.employee.show', $this->employee))->assertOk()
            ->assertSee('data-payment-change', false)
            ->assertSee(route('hr.employee.payment_change.approve', [$this->employee, $change]), false)
            ->assertSee(self::NEW);
        $this->as($this->looker)->get(route('hr.employee.show', $this->employee))->assertOk()
            ->assertSee('data-payment-change', false)
            ->assertSee('•••••••••5555')
            ->assertDontSee(self::NEW)
            ->assertDontSee(route('hr.employee.payment_change.approve', [$this->employee, $change]), false);

        // ⓘ অপেক্ষার সময়ের বেতন পুরনো হিসাবে
        $this->as($this->owner);
        $run = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $this->assertSame(self::OLD, (string) $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail()->bank_account_no,
            '⛔ সইয়ের আগেই বেতন নতুন হিসাবে গেল');

        // ⛔ লেখক নিজে — সব চাবি থাকলেও না; পাতার বোতামে, আর ইনবক্সে
        $this->as($this->clerk)->post(route('hr.employee.payment_change.approve', [$this->employee, $change]))
            ->assertSessionHasErrors(['payment_change' => __('hr::payment_change.another_hand')]);
        $this->as($this->clerk)->post(route('approval.inbox.approve', $approval->id));
        $this->assertSame(Approval::PENDING, (string) $approval->fresh()->status, '⛔ লেখকের নিজের সই পড়ল');

        // ⛔ চাবিহীন অন্যজন — না
        $this->as($this->looker)->post(route('hr.employee.payment_change.approve', [$this->employee, $change]))
            ->assertSessionHasErrors(['payment_change' => __('hr::payment_change.no_right')]);
        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no);

        // ⛔ ফোন — HR-এর সই ফোনে নয় (চুক্তি §৪): তালিকায় নেই, সইও পড়ে না
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->signer, [AuthController::APP]);
        $ids = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows') ?? [])->pluck('id')->all();
        $this->assertNotContains((string) $approval->public_id, $ids, '⛔ বেতনের ঠিকানা বদল ফোনের ইনবক্সে');
        $this->assertNotSame(200, $this->postJson('/api/v1/approvals/'.$approval->public_id.'/approve')->status(), '⛔ ফোন থেকে সই পড়ল');
        $this->assertSame(Approval::PENDING, (string) $approval->fresh()->status);

        // ⓘ চাবিওয়ালা অন্যজন — বসে
        $this->as($this->signer)->post(route('hr.employee.payment_change.approve', [$this->employee, $change]))
            ->assertSessionHasNoErrors()->assertSessionHas('saved', __('hr::payment_change.applied'));

        $this->assertSame(self::NEW, (string) $this->employee->fresh()->bank_account_no, 'সইয়ের পরেও বসল না');
        $change = $change->fresh();
        $this->assertSame(PaymentChange::APPLIED, $change->status);
        $this->assertSame((int) $this->signer->id, (int) $change->decided_by);
        $this->assertSame(self::OLD, $change->previous['bank_account_no'], '⛔ আগের হিসাব কোথাও রইল না');
        $this->assertSame(Approval::APPROVED, (string) $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->isUsed(), '⛔ সইটা খাটানো হয়েছে বলে লেখা নেই');
        $this->assertSame(1, $this->notices($this->worker, PaymentChanges::TOLD, 'applied'), '⛔ কর্মী জানলেন না যে ঠিকানা বদলেছে');
        $this->assertFalse(AuditTrail::query()->forRecord(Employee::class, (int) $this->employee->id)
            ->where('action', SalaryStructureService::OWNER_SELF_SIGNED)->exists(), '⛔ আরেকজনের সইয়ে মালিকের নিজে-সইয়ের চিহ্ন');

        // ⛔ খোলা লেখা কোথাও নেই — অপেক্ষার সারিতে নয়, নিরীক্ষার খাতায়ও নয়
        $this->assertSame(0, DB::table('hr_payment_changes')->where('proposed', 'like', '%'.self::NEW.'%')
            ->orWhere('previous', 'like', '%'.self::OLD.'%')->count(), '⛔ ব্যাংক নম্বর ডাটাবেজে খোলা');
        $this->assertSame(0, DB::table('audit_field_changes')->where(fn ($q) => $q->where('new_value', 'like', '%'.self::NEW.'%')
            ->orWhere('old_value', 'like', '%'.self::OLD.'%'))->count(), '⛔ নিরীক্ষার খাতায় ব্যাংক নম্বর খোলা');
    }

    public function test_the_web_inbox_signs_the_same_request(): void
    {
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]));
        $change = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();
        $approval = app(PaymentChanges::class)->approvalOf($change);

        $this->as($this->signer)->get(route('approval.inbox.index'))->assertOk()->assertSee('Paid Person', false);
        $this->as($this->signer)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();

        $this->assertSame(self::NEW, (string) $this->employee->fresh()->bank_account_no, '⛔ ইনবক্সের সইয়ে বদলটা বসল না');
        $this->assertSame(PaymentChange::APPLIED, $change->fresh()->status);
    }

    public function test_a_refusal_or_a_withdrawal_leaves_the_account_and_the_owner_signs_his_own_with_the_mark(): void
    {
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]));
        $first = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();

        $this->as($this->signer)->post(route('hr.employee.payment_change.reject', [$this->employee, $first]))->assertSessionHasNoErrors();
        $this->assertSame(PaymentChange::REJECTED, $first->fresh()->status);
        $this->assertSame(Approval::REJECTED, (string) app(PaymentChanges::class)->approvalOf($first)->status);
        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no, '⛔ "না"-র পরেও বদলাল');
        $this->assertSame(1, $this->notices($this->worker, PaymentChanges::TOLD, 'rejected'));

        // ⓘ লেখক নিজে ফিরিয়ে নেন — বাতিল, মোছা নয়; অনুরোধও বাতিল
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['payment_method' => 'mfs', 'mfs_number' => '01711111111']));
        $second = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();
        $this->assertSame('bank', $this->employee->fresh()->payment_method, '⛔ পথ বদলও একজনের হাতে বসল');
        $this->as($this->clerk)->post(route('hr.employee.payment_change.reject', [$this->employee, $second]))->assertSessionHasNoErrors();
        $this->assertSame(PaymentChange::CANCELLED, $second->fresh()->status);
        $this->assertSame(Approval::CANCELLED, (string) app(PaymentChanges::class)->approvalOf($second)->status);

        // ⓘ নতুন চাওয়া পুরনোটা আর তার অনুরোধ ফিরিয়ে নেয়
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => '7777777777777']));
        $replaced = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => '8888888888888']));
        $this->assertSame(PaymentChange::CANCELLED, $replaced->fresh()->status);
        $this->assertSame(Approval::CANCELLED, (string) app(PaymentChanges::class)->approvalOf($replaced)->status,
            '⛔ পুরনো বদলের অনুরোধ ইনবক্সে রয়ে গেল — সই দিলে কী বসত?');
        $this->as($this->clerk)->post(route('hr.employee.payment_change.reject',
            [$this->employee, PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail()]));

        // ⭐ মালিক নিজে — অপেক্ষায়, তারপর নিজের অনুমোদনে বসে, দাগ আর রিপোর্টসহ (মালিক, ২০ নভেম্বর ২০২৬)
        $this->as($this->owner)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]))
            ->assertSessionHasNoErrors()->assertSessionHas('saved', __('hr::payment_change.waiting_saved'));
        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no, '⛔ মালিকের বদল সই ছাড়াই বসল');
        $own = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();

        $this->as($this->owner)->post(route('hr.employee.payment_change.approve', [$this->employee, $own]))
            ->assertSessionHasNoErrors();
        $this->assertSame(self::NEW, (string) $this->employee->fresh()->bank_account_no, '⛔ মালিকের নিজের অনুমোদনে বদলটা বসল না');
        $this->assertOwnerSelfMarked(app(PaymentChanges::class)->approvalOf($own));

        // ⛔ বন্ধের সুইচ নেই — মালিক বলেছেন "সবসময়"
        $this->assertNotContains('hr.payment_change_needs_second_hand',
            array_column(app(ModuleRegistry::class)->get('hr')->settings, 'key'), '⛔ দ্বিতীয় সই বন্ধের সুইচ ফিরে এল');

        // ⓘ ঠিকানা না ছুঁলে কোনো বদল নয় — নাম বদলালে নতুন সারি হয় না
        $count = PaymentChange::query()->count();
        $this->as($this->clerk)->put(route('hr.employee.update', $this->employee), $this->form(['name_en' => 'Paid Person Renamed', 'bank_account_no' => self::NEW]))
            ->assertSessionHas('saved', __('hr::message.employee_updated'));
        $this->assertSame($count, PaymentChange::query()->count(), 'ঠিকানা না ছুঁলেও অপেক্ষার বদল হলো');
    }

    // ── যন্ত্রপাতি ──

    /** @param array<string, mixed> $over */
    private function form(array $over): array
    {
        return [
            'name_en' => 'Paid Person', 'joining_date' => '2026-01-15', 'payment_method' => 'bank', 'bank_name' => 'Sonali',
            'bank_account_no' => self::OLD, 'bank_routing_no' => '200270001', 'photo' => $this->png(),
            ...$over,
        ];
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            $this->give($user, $key);
        }

        return $user->fresh();
    }

    private function notices(User $user, string $type, ?string $what = null): int
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', $type)
            ->when($what, fn ($q, $w) => $q->where('title', __('hr::payment_change.told_'.$w.'_title')))->count();
    }

    private function png(): \Illuminate\Http\UploadedFile
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'pic');
        imagepng($image, $path);
        imagedestroy($image);

        return new \Illuminate\Http\UploadedFile($path, 'face.png', 'image/png', null, true);
    }

    private function give(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FieldSecurity::forget();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();

        return $this->actingAs($user->fresh());
    }
}
