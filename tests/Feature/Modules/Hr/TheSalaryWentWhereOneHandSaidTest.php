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
use App\Modules\Hr\Support\OwnerSelfSigned;
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
        ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'sales', 'action' => 'discount',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => false]);
        app()->forgetInstance(ApprovalEngine::class);

        $this->clerk = $this->person(self::HANDS);
        $this->signer = $this->person(self::HANDS);
        $this->worker = $this->person([]);
        $this->looker = $this->person(['hr.employee.view']);

        app(SalaryHeadService::class)->installDefaults();
        $this->employee = app(EmployeeService::class)->create([
            'code' => 'EMP-PAY', 'name_en' => 'Paid Person', 'joining_date' => '2026-01-15', 'user_id' => $this->worker->id,
            'payment_method' => 'bank', 'bank_account_no' => self::OLD, 'bank_name' => 'Sonali', 'bank_routing_no' => '200270001',
        ]);
        app(SalaryStructureService::class)->set($this->employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
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
            ->where('action', OwnerSelfSigned::ACTION)->exists(), '⛔ আরেকজনের সইয়ে মালিকের নিজে-সইয়ের চিহ্ন');

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

    public function test_a_refusal_or_a_withdrawal_leaves_the_account_and_the_owner_signs_his_own_with_a_mark(): void
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

        // ⛔ মালিক নিজে — এখন আর সরাসরি নয়: অপেক্ষায়, নিজে সই দিলে বসে, অডিটে চিহ্ন
        $this->as($this->owner)->put(route('hr.employee.update', $this->employee), $this->form(['bank_account_no' => self::NEW]))
            ->assertSessionHasNoErrors()->assertSessionHas('saved', __('hr::payment_change.waiting_saved'));
        $this->assertSame(self::OLD, (string) $this->employee->fresh()->bank_account_no, '⛔ মালিকের বদল সই ছাড়াই বসল');
        $own = PaymentChange::query()->where('status', PaymentChange::PENDING)->firstOrFail();

        $this->as($this->owner)->post(route('hr.employee.payment_change.approve', [$this->employee, $own]))->assertSessionHasNoErrors();
        $this->assertSame(self::NEW, (string) $this->employee->fresh()->bank_account_no, '⛔ মালিক নিজের বদলে সই দিতে পারলেন না');
        $this->assertTrue(AuditTrail::query()->forRecord(Employee::class, (int) $this->employee->id)
            ->where('action', OwnerSelfSigned::ACTION)->exists(), '⛔ মালিকের নিজে-সই অডিটে আলাদা চিহ্নে নেই');

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
