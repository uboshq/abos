<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalLimit;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\ApprovalFacts;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\SignsMoneyOff;
use Tests\TestCase;

/**
 * ⛔ বেতনের রানের সইয়ের অনুরোধে নিট কেবল `hr.salary.view`-এর চোখে — fe, ২১ অক্টোবর ২০২৬ (a6)।
 *
 * ⓘ আগে রানের নিট `approvals.amount`-এ খোলা বসত, আর সেখান থেকে ইনবক্স, সইয়ের পাতা, একসাথে সইয়ের তালিকা, ফোনের কার্ড, ঘণ্টা
 * আর চিঠির খবর, অনুমোদনের রিপোর্ট আর তার ফাইল, এমনকি ইনবক্সের খোঁজা — সবাই চাবি ছাড়া নিট দেখাত।
 * ⭐ এখন এক নিয়ম, লেখার মুহূর্তে ([[ApprovalRules::amountKey()]], [[ApprovalEngine::sealed()]]): নিট খোলা জমা হয় না, এনক্রিপ্ট করা
 * থাকে; ইঞ্জিনের হিসাব ([[Approval::signedAmount()]]) আগের মতোই চলে (সই কোন নিট ঢাকে, নিশ্চিত করা)। চাবি থাকা মানুষ নিট দেখেন
 * রানের সারাংশে ([[PayrollRunSummary]])। ছকের পর্দায় চাবি ছাড়া কেউ রানের সইকারী নন ([[ApprovalFlowService]]); হিসাবরক্ষকের
 * নিজে-বসানো ছক ব্যতিক্রম ([[MoneyFlowDefaults]] — fe-র কাছে খোলা প্রশ্ন)।
 */
final class ThePayrollSignatureShowsNoNetWithoutTheKeyTest extends TestCase
{
    use RefreshDatabase;
    use SignsMoneyOff;

    private const BASIC = '64813.57';

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();
        FieldSecurity::forget();
        $this->travelTo('2026-09-05 10:00:00');

        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-NET', 'name_en' => 'Net Probe', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', self::BASIC);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_net_is_never_stored_in_the_clear_and_reaches_no_door_without_the_key(): void
    {
        $blind = $this->staff('approval.decide', 'approval.report', 'governance.export.view');
        $sighted = $this->staff('approval.decide', 'hr.payroll.view', 'hr.salary.view');
        $this->flow($blind, $sighted);

        [$run, $approval, $net] = $this->askedToSign();
        $digits = $this->digits($net);

        // ── জমা: খোলা ঘর খালি, payload-এ কেবল এনক্রিপ্ট করা; ইঞ্জিন আসল নিট জানে
        $raw = DB::table('approvals')->where('id', $approval->id)->first();
        $this->assertNull($raw->amount, '⛔ রানের নিট approvals.amount-এ খোলা।');
        $this->assertStringNotContainsString($digits, (string) $raw->payload, '⛔ payload-এ নিট খোলা।');
        $this->assertSame(0, bccomp($net, (string) $approval->signedAmount(), 4), '⛔ ইঞ্জিন সইয়ের নিট হারাল।');

        // ── খবর: ঘণ্টা আর চিঠির লেখা, ইঞ্জিনের খবরের তথ্য
        $this->assertSame('', (string) (app(ApprovalEngine::class)->noticeData($approval)['amount'] ?? ''), '⛔ খবরের তথ্যে নিট।');
        foreach ([Notification::query()->withoutGlobalScopes()->get()->toJson(), NotificationEvent::query()->withoutGlobalScopes()->get()->toJson()] as $sent) {
            $this->assertStringNotContainsString($digits, $sent, '⛔ ঘণ্টা বা চিঠির খবরে রানের নিট।');
            $this->assertStringNotContainsString(Money::format($net), $sent, '⛔ ঘণ্টা বা চিঠির খবরে রানের নিট।');
        }

        // ── চাবি ছাড়া: ইনবক্স (একসাথে সইয়ের তালিকা), সইয়ের পাতা, রিপোর্ট আর তার ফাইল, ইনবক্সের সারির তথ্য (ফোনের কার্ডও এটাই পড়ে)
        foreach ([
            'inbox' => route('approval.inbox.index'),
            'signing page' => route('approval.inbox.show', $approval->id),
            'pending report' => route('approval.report.show', ['slug' => 'pending']),
            'pending report, CSV' => route('approval.report.show', ['slug' => 'pending', 'export' => 'csv']),
        ] as $door => $url) {
            $body = $this->body($this->as($blind)->get($url)->assertOk());
            $this->assertStringNotContainsString(Money::format($net), $body, "⛔ [{$door}] চাবি ছাড়া রানের নিট।");
            $this->assertStringNotContainsString($digits, $body, "⛔ [{$door}] চাবি ছাড়া রানের নিট।");
        }

        $this->as($blind);
        $about = (string) (app(ApprovalFacts::class)->of(collect([$approval->fresh()]))[$approval->id]['about'] ?? '');
        $this->assertStringContainsString(FieldSecurity::mask(), $about, '⛔ সারাংশে নিটের জায়গায় চিহ্ন নেই।');
        $this->assertStringNotContainsString(Money::format($net), $about);

        // ── খোঁজা: নিট লিখে খুঁজলে রানের অনুরোধ মেলে না — অঙ্ক আন্দাজের দরজা বন্ধ
        $this->as($sighted)->get(route('approval.inbox.index', ['q' => $digits]))->assertOk()
            ->assertDontSee((string) $run->document_no, false);

        // ── চাবি থাকলে: সারিতে নিট
        $this->as($sighted)->get(route('approval.inbox.index'))->assertOk()->assertSee(Money::format($net), false);
    }

    public function test_the_sealed_net_still_lets_the_signed_run_confirm(): void
    {
        $sighted = $this->staff('approval.decide', 'hr.payroll.view', 'hr.salary.view');
        $this->flow($sighted);

        [$run, $approval] = $this->askedToSign();

        $this->actingAs($sighted);
        app(ApprovalEngine::class)->approve($approval->fresh(), $sighted->fresh(), 'ok');

        $this->actingAs($this->owner);
        $confirmed = app(PayrollService::class)->confirm($run->fresh());

        $this->assertSame(DocumentStatus::CONFIRMED, $confirmed->status, '⛔ ঢাকা নিটে সই পেয়েও রান নিশ্চিত হলো না — সই কোন নিট ঢাকে, ইঞ্জিন জানল না।');
    }

    public function test_an_authority_limit_still_measures_the_sealed_net(): void
    {
        $role = CompanyContext::forCompany($this->company->id, function () {
            $role = Role::query()->create(['name' => 'Pay Signer', 'guard_name' => 'web', 'company_id' => $this->company->id]);
            $role->givePermissionTo([Permission::findOrCreate('hr.salary.view', 'web'), Permission::findOrCreate('approval.decide', 'web')]);

            return $role;
        });
        ApprovalLimit::query()->create(['company_id' => $this->company->id, 'role_id' => $role->id, 'module' => 'hr',
            'action' => 'hr.payroll', 'branch_id' => null, 'max_amount' => '1000']);

        $signer = $this->staff();
        CompanyContext::forCompany($this->company->id, fn () => $signer->assignRole($role));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $role->id]);
        app()->forgetInstance(ApprovalEngine::class);

        [, $approval] = $this->askedToSign();

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $signer->fresh()),
            '⛔ ১০০০ টাকার সীমার সইকারী ৬৪ হাজারের রানে সই দিতে পারছেন — ঢাকা নিট সীমায় মাপা হয়নি।');
    }

    public function test_an_amount_in_the_clear_is_read_as_before(): void
    {
        $plain = new Approval(['amount' => '500']);

        $this->assertSame('500.0000', $plain->signedAmount());
        $this->assertTrue($plain->covers('500'), '⛔ খোলা অঙ্কের সই আর নিজের অঙ্ক ঢাকে না — বাকি সব টাকার কাগজ নতুন সই চাইত।');
        $this->assertFalse($plain->covers('501'));
    }

    public function test_the_command_seals_an_old_payroll_request_once(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $id = DB::table('approvals')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::uuid7(), 'company_id' => $this->company->id,
            'approvable_type' => PayrollRun::class, 'approvable_id' => $run->id, 'module' => 'hr', 'action' => 'payroll',
            'amount' => '12345.6700', 'status' => Approval::PENDING, 'current_level' => 1, 'payload' => null,
            'requested_by' => $this->owner->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('abos:seal-approval-amounts')->assertSuccessful();
        $this->artisan('abos:seal-approval-amounts')->assertSuccessful();

        $row = DB::table('approvals')->where('id', $id)->first();
        $this->assertNull($row->amount, '⛔ পুরনো অনুরোধের নিট খোলা রয়ে গেল।');
        $this->assertStringNotContainsString('12345', (string) $row->payload);
        $this->assertSame('12345.6700', Approval::query()->findOrFail($id)->signedAmount(), '⛔ ঢাকতে গিয়ে নিট হারাল।');
    }

    public function test_the_flow_editor_refuses_a_payroll_signer_without_the_salary_key(): void
    {
        $blind = $this->staff('approval.decide');

        $this->actingAs($this->owner)->post(route('approval.flow.store'), [
            'module' => 'hr', 'action' => 'payroll', 'is_active' => '1', 'steps' => [['level' => 1, 'approver' => 'user|'.$blind->id]],
        ])->assertSessionHasErrors('steps');

        $this->assertSame(0, ApprovalFlow::query()->where('module', 'hr')->where('action', 'payroll')->count(),
            '⛔ চাবি ছাড়া সইকারী নিয়ে বেতনের রানের ছক বসল।');
    }

    /**
     * ⭐ নিজে-বসানো বেতনের রানের ছক — মালিক (super admin রোল) বা চাবি থাকা HR-এর প্রধান, হিসাবরক্ষক কখনো নন; বেতন পরিশোধের ভাউচারের
     * ছক আগের মতোই হিসাবরক্ষকের (fe, ২১ অক্টোবর ২০২৬, a6; [[MoneyFlowDefaults::keyHolders()]])।
     */
    public function test_the_default_payroll_flow_is_signed_by_the_owner_or_an_hr_head_never_the_accountant(): void
    {
        $head = CompanyContext::forCompany($this->company->id, function () {
            $role = Role::query()->create(['name' => 'HR Head', 'guard_name' => 'web', 'company_id' => $this->company->id]);
            $role->givePermissionTo(Permission::findOrCreate('hr.salary.view', 'web'));

            return $role;
        });
        $blindHr = CompanyContext::forCompany($this->company->id, fn () => Role::query()->create(
            ['name' => 'HR Clerk', 'guard_name' => 'web', 'company_id' => $this->company->id]));
        $owner = Role::query()->where('company_id', $this->company->id)->where('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        // ⓘ হিসাবরক্ষকের হাতে চাবি থাকলেও বেতনের রানের নিজে-বসানো সইকারী নন (fe: "never the Accountant")
        CompanyContext::forCompany($this->company->id, fn () => $this->accountantRoleIn($this->company)
            ->givePermissionTo(Permission::findOrCreate('hr.salary.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $report = $this->moneyFlowsFor($this->company);
        $accountant = (int) $report['role_id'];

        $this->assertContains('hr.payroll', $report['created'], '⛔ বেতনের রানের ছক বসল না — রান কারো সই ছাড়াই চলত।');

        $payroll = ApprovalFlow::query()->with('steps')->where('module', 'hr')->where('action', 'payroll')->firstOrFail();
        $signers = $payroll->steps->pluck('approver_id')->map(fn ($id) => (int) $id)->all();

        $this->assertNotContains($accountant, $signers, '⛔ বেতনের রানের নিজে-বসানো ছকে হিসাবরক্ষক।');
        $this->assertContains((int) $owner->id, $signers, '⛔ বেতনের রানের ছকে মালিক নেই।');
        $this->assertContains((int) $head->id, $signers, '⛔ চাবি থাকা HR-এর প্রধান ছকে নেই।');
        $this->assertNotContains((int) $blindHr->id, $signers, '⛔ চাবি ছাড়া রোল বেতনের রানের সইকারী হলো।');
        $this->assertSame([ApprovalFlowStep::BY_ROLE], $payroll->steps->pluck('approver_type')->unique()->values()->all());
        $this->assertSame([1], $payroll->steps->pluck('level')->map(fn ($l) => (int) $l)->unique()->values()->all());

        // ⓘ বেতন পরিশোধের ভাউচার (`accounts.payment`) আর বাকি প্রতিটা টাকার কাজ — আগের মতোই হিসাবরক্ষকের
        $this->assertTrue(in_array('accounts.payment', $report['created'], true) || in_array('accounts.payment', $report['skipped'], true),
            'প্রস্তুতি — পরিশোধের ভাউচারের ছক দেখাই হয়নি।');

        foreach (array_diff($report['created'], ['hr.payroll']) as $key) {
            [$module, $action] = explode('.', $key, 2);
            $flow = ApprovalFlow::query()->with('steps')->where('module', $module)->where('action', $action)->firstOrFail();
            $this->assertSame([$accountant], $flow->steps->pluck('approver_id')->map(fn ($id) => (int) $id)->all(),
                "⛔ {$key}-এর ছক হিসাবরক্ষকের নয় — বেতন পরিশোধের ভাউচারসহ টাকার কাজ হিসাবরক্ষকেরই।");
        }
    }

    public function test_where_every_approval_is_switched_off_nothing_is_seeded(): void
    {
        // ⓘ UB-র মতো — ছক আছে, একটাও চালু নয় (মালিক, ৩ অক্টোবর ২০২৬: সব সই বন্ধ)
        ApprovalFlow::query()->update(['is_active' => false]);
        ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'sales', 'action' => 'discount',
            'document_type' => 'UBOFF', 'threshold_amount' => null, 'is_active' => false]);

        $report = $this->moneyFlowsFor($this->company);

        $this->assertSame([], $report['created'], '⛔ সব সই বন্ধ, তবু নতুন ছক বসল: '.implode(', ', $report['created']));
        $this->assertContains('hr.payroll', $report['switched_off']);
        $this->assertSame(0, ApprovalFlow::query()->where('module', 'hr')->where('action', 'payroll')->count(),
            '⛔ অনুমোদন বন্ধ রাখা কোম্পানিতে বেতনের রানের ছক বসে গেল।');
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /** @return array{PayrollRun, Approval, string} */
    private function askedToSign(): array
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $net = bcadd((string) $run->fresh()->net_total, '0', 2);
        $this->assertSame(1, bccomp($net, '0', 2), 'প্রস্তুতি — নিট শূন্য।');

        try {
            app(PayrollService::class)->confirm($run->fresh());
            $this->fail('প্রস্তুতি — রান সইয়ের জন্য থামার কথা।');
        } catch (ValidationException) {
        }

        $approval = app(ApprovalEngine::class)->latestFor($run->fresh(), 'payroll');
        $this->assertNotNull($approval);
        $this->assertSame(Approval::PENDING, $approval->status);

        return [$run->fresh(), $approval, $net];
    }

    /** ⓘ নিটের অঙ্কগুলো দশমিক ছাড়া — "64813" — যেকোনো ছাপার রূপে খোঁজার জন্য */
    private function digits(string $net): string
    {
        return explode('.', $net)[0];
    }

    /** ⓘ ছক হাতে — মডেল দিয়ে (পুরনো ছকের মতো, চাবির নিয়মের আগে); এক স্তর, যেকোনো একজন */
    private function flow(User ...$signers): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);

        foreach ($signers as $signer) {
            ApprovalFlowStep::query()->create([
                'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id,
            ]);
        }

        app()->forgetInstance(ApprovalEngine::class);
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? (string) $response->streamedContent()
            : (string) $response->getContent();
    }

    private function staff(string ...$keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys) {
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
