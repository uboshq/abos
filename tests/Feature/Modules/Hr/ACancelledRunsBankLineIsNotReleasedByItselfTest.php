<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\BankUnpaidLine;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HeldWagePayment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\HeldWages;
use App\Modules\Hr\Services\PaymentChanges;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⛔ বাতিল রানের ব্যাংক ফাইলে যাওয়া বকেয়া মজুরি নিজে নিজে ছাড়া হয় না — ব্যাংক হয়তো দিয়েই দিয়েছে (fe-র সিদ্ধান্ত ২, ২১ অক্টোবর ২০২৬;
 * শ্রম আইন ধারা ১২৩; [[HeldWages::awaitingBank()]], [[HeldWages::askBankUnpaid()]], [[HeldWages::finishBankUnpaid()]])।
 *
 * ⓘ ফাইল নামানোর মুহূর্তে বকেয়ার "দেওয়া হয়েছে" সারি বসে; ফাইলটা ব্যাংকে উঠে গেলে রান বাতিল হলেও টাকা গেছে। রান বাতিলে সারিটা ছাড়লে
 * মজুরি আবার দেওয়ার তালিকায় ফিরত — একই মজুরি দুইবার।
 *
 * দাবি (UB-র মতো সব ছক বন্ধ কোম্পানিতে, ওয়েবের দরজা দিয়ে):
 *  - সেপ্টেম্বরের ব্যাংক ফাইল আগস্টের আটকে থাকা মজুরি বহন করল, তারপর সেপ্টেম্বরের রান বাতিল — সারি থাকে, "দেওয়ার অপেক্ষায়" ফেরে না,
 *    বকেয়ার পাতা, HR-এর তালিকা আর আগস্টের রানের পাতা বলে "ব্যাংকে পাঠানো — নিশ্চিতের অপেক্ষায়"; অক্টোবরের ফাইলও বহন করে না;
 *  - "ব্যাংক এই লাইন দেয়নি" কারণ ছাড়া ফেরে, কিছুই লেখা হয় না;
 *  - কারণসহ চাইলে দ্বিতীয় সইয়ের অপেক্ষায়, কর্মীর অডিটে `bank_unpaid_asked`; মজুরি তখনো ছাড়া নয়;
 *  - যিনি চাইলেন তিনি সই দিতে পারেন না — পাতার বোতামে, ইনবক্সে — মালিকও নন;
 *  - আরেকজন চাবিধারী সই দিলে মজুরি দেওয়ার তালিকায় ঠিক একবার, অডিটে `bank_unpaid_released`; দ্বিতীয় সই বা শ্রোতার আবার-চলা আর
 *    কিছু ছাড়ে না — পরের রানের ফাইলে বকেয়াটা একবারই, আর তার নতুন সারি অক্ষত।
 */
final class ACancelledRunsBankLineIsNotReleasedByItselfTest extends TestCase
{
    use RefreshDatabase;
    use SignsTheSecondHand;

    private const NUMBER = '5555555555555';

    /** ⓘ বেতনের রানের চাবি — "ব্যাংক এই লাইন দেয়নি" চাওয়া আর তার দ্বিতীয় সই, দুটোরই ([[ApprovalRules::secondHand()]]) */
    private const PAYROLL = ['hr.payroll.view', 'hr.payroll.manage', 'hr.salary.view', 'approval.decide'];

    /** ⓘ কর্মীর ব্যাংক তথ্য লেখা আর সই */
    private const HANDS = ['hr.employee.view', 'hr.employee.manage', 'hr.identity.view', 'approval.decide'];

    private Company $company;

    private User $owner;

    private User $accountant;

    private User $checker;

    private Employee $employee;

    private Payslip $held;

    private PayrollRun $august;

    private PayrollRun $september;

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

        $this->accountant = $this->person(self::PAYROLL);
        $this->checker = $this->person(self::PAYROLL);
        $clerk = $this->person(self::HANDS);
        $signer = $this->person(self::HANDS);

        app(SalaryHeadService::class)->installDefaults();
        $this->travelTo('2026-12-05 10:00:00');

        // ── নতুন কর্মী, ব্যাংক তথ্য সইয়ের অপেক্ষায় — আগস্টের বেতন আটকে
        $this->as($clerk);
        $this->employee = app(EmployeeService::class)->create([
            'code' => 'EMP-BNK', 'name_en' => 'Bank Line', 'joining_date' => '2026-01-15',
            'payment_method' => 'bank', 'bank_account_no' => self::NUMBER, 'bank_name' => 'Sonali', 'bank_routing_no' => '200270001',
        ]);
        app(SalaryStructureService::class)->set($this->employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '15000');

        $this->as($this->owner);
        $this->august = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));
        $this->held = $this->august->payslips()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame(PayrollService::HELD, (string) $this->held->payment_method, 'প্রস্তুতি — আগস্টের বেতন আটকে নেই।');

        // ── তথ্যে সই পড়ল; সেপ্টেম্বরের ব্যাংক ফাইল আগস্টের বকেয়া বহন করল
        $this->as($signer)->post(route('hr.employee.payment_change.approve', [$this->employee, app(PaymentChanges::class)->pendingFor($this->employee)]))
            ->assertSessionHasNoErrors();
        $this->as($this->owner);
        $this->september = $this->confirmedPayroll(app(PayrollService::class)->build('2026-09-01'));
        $this->assertSame(1, substr_count((string) app(PayrollService::class)->bankFile($this->september->fresh())['content'], $this->arrears()),
            'প্রস্তুতি — সেপ্টেম্বরের ফাইল আগস্টের বকেয়া বহন করেনি।');
        $this->assertSame((int) $this->september->id, (int) $this->payment()?->paid_in_run_id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_cancelling_the_run_does_not_release_the_arrears_and_shows_them_awaiting_the_bank(): void
    {
        $this->as($this->owner)->post(route('hr.payroll.cancel', $this->september->id), ['reason' => 'wrong month'])->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CANCELLED, (string) $this->september->fresh()->status, 'প্রস্তুতি — রান বাতিল হলো না।');

        $this->assertNotNull($this->payment(), '⛔ রান বাতিলে বকেয়ার সারি মুছে গেল — ব্যাংক হয়তো দিয়েছে, মজুরি দুইবার দেওয়ার পথ খুলল।');
        $this->assertNotContains((int) $this->held->id, $this->ready(), '⛔ বাতিল রানের ফাইলের বকেয়া নিজে নিজে "দেওয়ার অপেক্ষায়" ফিরল।');

        $owed = app(PayrollService::class)->heldWagesOwed()->where('employee', 'Bank Line')->values();
        $this->assertCount(1, $owed, '⛔ HR-এর তালিকায় লাইনটা নেই, বা দুইবার।');
        $this->assertSame('bank_awaiting', $owed[0]['state'], '⛔ HR-এর তালিকা বলে না "ব্যাংকে পাঠানো — নিশ্চিতের অপেক্ষায়"।');

        $this->as($this->accountant)->get(route('hr.payroll.held.index'))->assertOk()
            ->assertSee('data-bank-awaiting-line="EMP-BNK"', false)
            ->assertSee(__('hr::payroll.bank_awaiting'), false)
            ->assertSee(route('hr.payroll.held.bank_unpaid', $this->payment()->id), false)
            ->assertDontSee('data-held-line="EMP-BNK"', false);
        $this->as($this->owner)->get(route('hr.payroll.show', $this->august->id))->assertOk()
            ->assertSee(__('hr::payroll.bank_awaiting_on', ['no' => $this->september->document_no]), false);

        // ⛔ পরের রানের ফাইলও বহন করে না — সারিটা এখনো সেপ্টেম্বরের
        $october = $this->confirmedPayroll(app(PayrollService::class)->build('2026-10-01'));
        $this->assertStringNotContainsString($this->arrears(), (string) app(PayrollService::class)->bankFile($october->fresh())['content'],
            '⛔ বাতিল রানের ফাইলের বকেয়া পরের রানের ফাইলেও — ব্যাংক আগেরটা দিয়ে থাকলে দুইবার।');
        $this->assertSame((int) $this->september->id, (int) $this->payment()?->paid_in_run_id);
    }

    public function test_the_bank_did_not_pay_needs_a_reason_and_another_hand_and_releases_the_wage_once(): void
    {
        $this->as($this->owner)->post(route('hr.payroll.cancel', $this->september->id), ['reason' => 'wrong month'])->assertSessionHasNoErrors();
        $payment = $this->payment();
        $door = route('hr.payroll.held.bank_unpaid', $payment->id);

        // ── কারণ ছাড়া — ফেরে, কিছুই লেখা হয় না
        $this->as($this->accountant)->from(route('hr.payroll.held.index'))->post($door, ['reason' => '  '])->assertSessionHasErrors('reason');
        $this->assertSame(0, BankUnpaidLine::query()->count(), '⛔ কারণ ছাড়া "ব্যাংক এই লাইন দেয়নি" বসল।');

        // ── কারণসহ — দ্বিতীয় সইয়ের অপেক্ষায়; মজুরি তখনো ছাড়া নয়
        $this->as($this->accountant)->post($door, ['reason' => 'Statement 30 Sep: line returned, account dormant'])->assertSessionHasNoErrors();
        $line = BankUnpaidLine::query()->sole();
        $approval = app(HeldWages::class)->unpaidApprovalOf($line);
        $this->assertSame(BankUnpaidLine::PENDING, (string) $line->status);
        $this->assertNotNull($approval);
        $this->assertSame(Approval::PENDING, (string) $approval->status, '⛔ চাওয়াটা সইয়ের অপেক্ষায় নয়।');
        $this->assertTrue(app(ApprovalEngine::class)->isSecondHand($approval), '⛔ অনুরোধটা যন্ত্রের "দ্বিতীয় সই" ছকে নয়।');
        $this->assertNotNull($this->payment(), '⛔ চাওয়ার সাথে সাথেই, সই ছাড়া, মজুরি ছাড়া পেল।');
        $this->assertTrue(AuditTrail::query()->forRecord(Employee::class, (int) $this->employee->id)->where('action', 'bank_unpaid_asked')
            ->where('reason', 'like', '%Statement 30 Sep%')->exists(), '⛔ চাওয়ার কথা কর্মীর অডিটে নেই, কারণসহ।');
        $this->as($this->accountant)->get(route('hr.payroll.held.index'))->assertOk()->assertSee('data-bank-unpaid-waiting', false)
            ->assertDontSee(route('hr.payroll.held.bank_unpaid_sign', $line->id), false);

        // ── যিনি চাইলেন তিনি সই দিতে পারেন না — পাতার দরজায়, আর ইনবক্সে
        $this->as($this->accountant)->from(route('hr.payroll.held.index'))->post(route('hr.payroll.held.bank_unpaid_sign', $line->id))
            ->assertSessionHasErrors('bank_unpaid');
        $this->as($this->accountant)->post(route('approval.inbox.approve', $approval->id));
        $this->assertSame(Approval::PENDING, (string) $approval->fresh()->status, '⛔ চাওয়া মানুষটির নিজের সই পড়ল।');
        $this->assertNotNull($this->payment(), '⛔ এক হাতেই মজুরি ছাড়া পেল।');

        // ── আরেকজন চাবিধারী — পাতার বোতাম দেখেন, সই দেন; মজুরি দেওয়ার তালিকায় ঠিক একবার
        $this->as($this->checker)->get(route('hr.payroll.held.index'))->assertOk()
            ->assertSee(route('hr.payroll.held.bank_unpaid_sign', $line->id), false);
        $this->as($this->checker)->post(route('hr.payroll.held.bank_unpaid_sign', $line->id))->assertSessionHasNoErrors();
        $this->assertSame(BankUnpaidLine::RELEASED, (string) $line->fresh()->status, '⛔ দ্বিতীয় সইয়ের পরেও লাইন অপেক্ষায়।');
        $this->assertNull($this->payment(), '⛔ দ্বিতীয় সই পড়ল, অথচ মজুরি "দেওয়া হয়েছে"-তেই আটকে।');
        $this->assertSame(1, collect($this->ready())->filter(fn (int $id) => $id === (int) $this->held->id)->count(),
            '⛔ সইয়ের পরে মজুরি দেওয়ার তালিকায় একবার নয়।');
        $owed = app(PayrollService::class)->heldWagesOwed()->where('employee', 'Bank Line')->values();
        $this->assertCount(1, $owed);
        $this->assertSame('owed', $owed[0]['state']);
        $this->assertTrue(AuditTrail::query()->forRecord(Employee::class, (int) $this->employee->id)->where('action', 'bank_unpaid_released')->exists(),
            '⛔ ছাড়ার কথা কর্মীর অডিটে নেই।');
        $this->as($this->checker)->get(route('hr.payroll.held.index'))->assertOk()->assertSee('data-held-line="EMP-BNK"', false)
            ->assertDontSee('data-bank-awaiting-line="EMP-BNK"', false);

        // ── দ্বিতীয় সই আর শ্রোতার আবার-চলা — কিছুই আর ছাড়ে না
        $this->as($this->checker)->from(route('hr.payroll.held.index'))->post(route('hr.payroll.held.bank_unpaid_sign', $line->id))
            ->assertSessionHasErrors('bank_unpaid');
        $november = $this->confirmedPayroll(app(PayrollService::class)->build('2026-11-01'));
        $this->assertSame(1, substr_count((string) app(PayrollService::class)->bankFile($november->fresh())['content'], $this->arrears()),
            '⛔ ছাড়া মজুরি পরের ফাইলে একবার নয়।');
        $this->assertSame((int) $november->id, (int) $this->payment()?->paid_in_run_id);
        app(HeldWages::class)->finishBankUnpaid($approval->fresh());
        $this->assertSame((int) $november->id, (int) $this->payment()?->paid_in_run_id,
            '⛔ পুরনো সইয়ের আবার-চলা নতুন ফাইলের সারি মুছে দিল — মজুরি দুইবার দেওয়ার পথ।');
        $this->assertSame(1, BankUnpaidLine::query()->count());
    }

    /** ⛔ মালিকও নিজের চাওয়ায় দ্বিতীয় সই নন (fe, ২১ অক্টোবর ২০২৬; [[ApprovalEngine::twoHands()]]) */
    public function test_the_owner_cannot_sign_his_own_bank_did_not_pay(): void
    {
        $this->as($this->owner)->post(route('hr.payroll.cancel', $this->september->id), ['reason' => 'wrong month'])->assertSessionHasNoErrors();
        $this->as($this->owner)->post(route('hr.payroll.held.bank_unpaid', $this->payment()->id), ['reason' => 'Returned by the bank'])
            ->assertSessionHasNoErrors();
        $line = BankUnpaidLine::query()->sole();
        $approval = app(HeldWages::class)->unpaidApprovalOf($line);

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $this->owner->fresh()), '⛔ মালিক নিজের চাওয়ায় নিজেই দ্বিতীয় সই।');
        $this->as($this->owner)->from(route('hr.payroll.held.index'))->post(route('hr.payroll.held.bank_unpaid_sign', $line->id))
            ->assertSessionHasErrors('bank_unpaid');
        $this->assertSame(BankUnpaidLine::PENDING, (string) $line->fresh()->status);
        $this->assertNotNull($this->payment(), '⛔ মালিকের এক হাতে মজুরি ছাড়া পেল।');

        $this->as($this->accountant)->post(route('hr.payroll.held.bank_unpaid_sign', $line->id))->assertSessionHasNoErrors();
        $this->assertNull($this->payment(), '⛔ আরেকজনের সইয়েও মজুরি ছাড়া পেল না।');
    }

    /**
     * ⛔ "ব্যাংক এই লাইন দেয়নি" একসাথে সইয়ে কখনো নয় — প্রতিটা নিজের পাতায় কারণ আর প্রমাণ দেখে (fe, ২১ অক্টোবর ২০২৬; module.php-র
     * `no_bulk`, [[BulkApproval::oneByOne()]])। ইনবক্সে টিক-বাক্স নেই; সরাসরি পাঠালেও সই নয়, কারণ লেখা; একার পাতায় সই চলে।
     */
    public function test_a_bank_line_is_never_signed_in_bulk_but_its_own_page_signs_it(): void
    {
        $this->as($this->owner)->post(route('hr.payroll.cancel', $this->september->id), ['reason' => 'wrong month'])->assertSessionHasNoErrors();
        $this->as($this->accountant)->post(route('hr.payroll.held.bank_unpaid', $this->payment()->id), ['reason' => 'Returned by the bank'])
            ->assertSessionHasNoErrors();
        $line = BankUnpaidLine::query()->sole();
        $approval = app(HeldWages::class)->unpaidApprovalOf($line);
        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval->fresh(), $this->checker->fresh()), 'প্রস্তুতি — দ্বিতীয় সইকারী সই দিতে পারেন না।');

        // ── ইনবক্সে আছে, কিন্তু বাছাইয়ের ঘর নেই
        $this->as($this->checker)->get(route('approval.inbox.index'))->assertOk()
            ->assertSee(route('approval.inbox.show', $approval->id), false)
            ->assertDontSee('name="ids[]" value="'.$approval->id.'"', false);

        // ── সরাসরি একসাথে-সইয়ের দরজায় — সই নয়, কারণ লেখা
        $reason = __('approval::message.bulk_skipped_one_by_one', ['count' => 1]);
        $this->as($this->checker)->followingRedirects()->post(route('approval.inbox.bulk'), ['ids' => [$approval->id]])
            ->assertOk()->assertSee($reason);
        $this->assertSame(Approval::PENDING, (string) $approval->fresh()->status, '⛔ "ব্যাংক এই লাইন দেয়নি" একসাথে-সইয়ে পার হলো — না দেখে সই।');
        $this->assertSame(BankUnpaidLine::PENDING, (string) $line->fresh()->status);
        $this->assertNotNull($this->payment(), '⛔ একসাথে-সইয়ে মজুরি ছাড়া পেল।');

        // ── নিজের পাতায় সই — চলে
        $this->as($this->checker)->post(route('hr.payroll.held.bank_unpaid_sign', $line->id))->assertSessionHasNoErrors();
        $this->assertSame(BankUnpaidLine::RELEASED, (string) $line->fresh()->status);
        $this->assertNull($this->payment(), '⛔ একার পাতার সইয়েও মজুরি ছাড়া পেল না।');
    }

    // ── যন্ত্রপাতি ──

    private function arrears(): string
    {
        return (string) __('hr::payroll.arrears_reference', ['no' => $this->august->document_no, 'month' => 'Aug 2026']);
    }

    private function payment(): ?HeldWagePayment
    {
        return HeldWagePayment::query()->where('payslip_id', $this->held->id)->first();
    }

    /** @return list<int> */
    private function ready(): array
    {
        return app(HeldWages::class)->ready()->pluck('payslip.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FieldSecurity::forget();

        return $user->fresh();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();

        return $this->actingAs($user->fresh());
    }
}
