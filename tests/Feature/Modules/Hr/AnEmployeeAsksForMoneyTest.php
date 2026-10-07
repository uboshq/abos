<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ কর্মী টাকা চান — খরচের দাবি আর অগ্রিম অনুরোধ (মালিকের আদেশ, ৭ অক্টোবর ২০২৬: টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা,
 * ভাগ ১৩-গ; [[ExpenseClaimService]])।
 *
 * ⛔ পরিকল্পনার প্রতিটা ঘর: অনুরোধ (খাত, অঙ্ক, তারিখ, কারণ, রসিদ) → অঙ্কের সীমায় সই, যিনি চাইলেন তিনি নন → শেষ সইয়ে খসড়া
 * ভাউচার → ক্যাশিয়ার নিজের টিল থেকে পাকা করেন (অন্য কেউ নয়), দ্বিতীয় সই নয় → অগ্রিম কর্মীর নামে, পরের খরচ আগে অগ্রিম থেকে।
 */
final class AnEmployeeAsksForMoneyTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $worker;

    private User $cashier;

    private User $signer;

    private Employee $employee;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->worker = $this->member('worker@claims.test', ['hr.claim.self']);
        $this->cashier = $this->member('cashier@claims.test', ['hr.claim.self']);
        $this->employee = app(EmployeeService::class)->create(['code' => 'EMP-CLM', 'name_en' => 'Field Worker', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $this->employee->forceFill(['user_id' => $this->worker->id])->save();

        // ⓘ প্রধান বাক্স ক্যাশিয়ারের হাতে, টাকাসহ
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $till->forceFill(['holder_id' => $this->cashier->id])->save();
        $this->putMoneyIn($till->account, '100000', now()->subDay()->toDateString());

        $this->expense = Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();
    }

    public function test_an_expense_claim_with_no_signature_flow_waits_for_the_cashier_who_pays_from_his_own_till(): void
    {
        // ⓘ ছক নেই — মালিকের সইয়ের অপেক্ষা, তারপর ক্যাশিয়ার ([[AClaimNeverPassesWithoutASignatureTest]], ৭ অক্টোবর ২০২৬)
        $claim = $this->signed($this->submit(ExpenseClaim::EXPENSE, '1200', receipt: UploadedFile::fake()->image('bus.jpg')));

        $this->assertSame(ExpenseClaim::APPROVED, $claim->status, 'মালিকের সইয়ে অনুমোদিত');
        $voucher = $claim->paymentVoucher;
        $this->assertNotNull($voucher, '⛔ অনুমোদনের পরে খসড়া ভাউচার হয়নি');
        $this->assertTrue($voucher->isDraft(), '⛔ ক্যাশিয়ারের আগেই টাকা খাতায়');
        $this->assertSame(Voucher::PAYMENT, $voucher->type);
        $this->assertMoney('1200', $voucher->lines()->where('account_id', $this->expense->id)->sum('debit'), 'Dr খরচের খাত');
        $this->assertSame(1, Attachment::query()->where('source_entity', ExpenseClaim::drillSourceType())->where('source_entity_id', $claim->id)->count(), '⛔ রসিদের ছবি রাখা হয়নি');

        // ⛔ যিনি টিল ধরেন না, তিনি পাকা করতে পারেন না — "নগদ কেবল নিজের টিল থেকে"
        $this->actingAs($this->worker);
        $this->assertThrows(fn () => app(VoucherService::class)->post($voucher->fresh()), ValidationException::class);
        $this->assertSame(ExpenseClaim::APPROVED, $claim->fresh()->status);

        $this->actingAs($this->cashier);
        app(VoucherService::class)->post($voucher->fresh());
        $this->assertSame(ExpenseClaim::PAID, $claim->fresh()->status, '⛔ পাকা হলেও দাবি "টাকা দেওয়া" হয়নি');
        $this->assertNotNull($claim->fresh()->paid_at);
    }

    public function test_an_advance_is_given_in_the_employees_name_and_the_next_expense_is_settled_from_it_first(): void
    {
        $advance = $this->signed($this->submit(ExpenseClaim::ADVANCE, '3000'));
        $this->actingAs($this->cashier);
        app(VoucherService::class)->post($advance->paymentVoucher);

        $this->assertSame(ExpenseClaim::PAID, $advance->fresh()->status);
        $this->assertMoney('3000', $this->open(), '⛔ অগ্রিম কর্মীর নামে বসেনি');

        // ⓘ ২,০০০-এর খরচ — পুরোটা অগ্রিম থেকে, নগদ লাগে না, সাথে সাথে "টাকা দেওয়া"
        $first = $this->signed($this->submit(ExpenseClaim::EXPENSE, '2000'));
        $this->assertSame(ExpenseClaim::PAID, $first->status, '⛔ অগ্রিম থেকে পুরোটা মিটলেও ক্যাশিয়ারের অপেক্ষা');
        $this->assertMoney('2000', $first->from_advance, 'অগ্রিম থেকে');
        $this->assertNull($first->payment_voucher_id, '⛔ অগ্রিম থাকতেও নগদের খসড়া');
        $this->assertSame(DocumentStatus::CONFIRMED, $first->settleVoucher->status, '⛔ অগ্রিম থেকে কাটা খাতায় বসেনি');
        $this->assertMoney('1000', $this->open(), '⛔ খরচ কর্মীর অগ্রিম থেকে কাটেনি');

        // ⓘ ১,৫০০-এর খরচ — ১,০০০ অগ্রিম থেকে, বাকি ৫০০ নগদে
        $second = $this->signed($this->submit(ExpenseClaim::EXPENSE, '1500'));
        $this->assertMoney('1000', $second->from_advance, '⛔ বাকি অগ্রিমের বেশি বা কম কাটা');
        $this->assertSame(ExpenseClaim::APPROVED, $second->status);
        $this->assertMoney('500', $second->paymentVoucher->lines()->sum('debit'), '⛔ নগদের খসড়া বাকিটুকুর নয়');
        $this->assertMoney('0', $this->open(), 'অগ্রিম শেষ');
        $this->assertSame(0, LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)->whereNull('party_id')->count(), '⛔ অগ্রিমের সারি নাম ছাড়া');
    }

    public function test_the_signature_flow_holds_it_the_requester_cannot_sign_and_the_cashier_is_not_asked_again(): void
    {
        $this->flow('hr', 'expense_claim');
        // ⓘ হিসাবের পরিশোধ-ছকও চালু — তবু দাবির ভাউচারে দ্বিতীয় সই নয়
        $this->flow('accounts', 'payment');

        $claim = $this->submit(ExpenseClaim::EXPENSE, '800');
        $this->assertSame(ExpenseClaim::SUBMITTED, $claim->status, '⛔ ছক থাকতেও সই ছাড়া অনুমোদিত');
        $this->assertNull($claim->payment_voucher_id, '⛔ সইয়ের আগেই ভাউচার');

        // ⛔ সই না পাওয়া দাবির বিপরীতে হাতে লেখা ভাউচারও নয় — টাকা সইয়ের পরে
        $this->assertRefused(fn () => $this->payAgainst($claim, '800'), 'against_id', '⛔ সইয়ের আগেই দাবির বিপরীতে টাকা দেওয়া গেল');

        $approval = Approval::query()->where('action', 'expense_claim')->where('status', Approval::PENDING)->sole();
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval, $this->worker->fresh()), '⛔ যিনি চাইলেন তিনিই সই দিতে পারেন');

        app(ApprovalEngine::class)->approve($approval, $this->signer);
        $claim->refresh();
        $this->assertSame(ExpenseClaim::APPROVED, $claim->status, '⛔ শেষ সইয়ে অনুমোদিত হয়নি');
        $this->assertNull(app(VoucherApproval::class)->actionFor($claim->paymentVoucher), '⛔ সই পাওয়া দাবির ভাউচারে আবার সই চাইল');

        $this->actingAs($this->cashier);
        app(VoucherService::class)->post($claim->paymentVoucher);
        $this->assertSame(ExpenseClaim::PAID, $claim->fresh()->status);
        // ⛔ একবার দেওয়া দাবির বিপরীতে দ্বিতীয়বার নয়
        $this->assertRefused(fn () => $this->payAgainst($claim->fresh(), '800'), 'against_id', '⛔ দেওয়া দাবির বিপরীতে আবার টাকা দেওয়া গেল');

        // ⓘ অগ্রিমের নিজের ছক — খরচের দাবির ছক অগ্রিমকে ধরে না (দুইটার আলাদা সীমা); নিজের ছক নেই বলে মালিকের অপেক্ষায়
        $advance = $this->submit(ExpenseClaim::ADVANCE, '400');
        $this->assertSame(ExpenseClaim::SUBMITTED, $advance->status);
        $this->assertSame(ExpenseClaim::ACTION_ADVANCE, Approval::query()->where('approvable_id', $advance->id)
            ->where('approvable_type', $advance->getMorphClass())->sole()->action, '⛔ অগ্রিম খরচের দাবির ছকে আটকাল');

        // ⓘ "না" — ফেরানো, কোনো ভাউচার নয়
        $refused = $this->submit(ExpenseClaim::EXPENSE, '900');
        app(ApprovalEngine::class)->reject(Approval::query()->where('action', 'expense_claim')->where('status', Approval::PENDING)->sole(), $this->signer, 'রসিদ নেই');
        $this->assertSame(ExpenseClaim::REJECTED, $refused->fresh()->status);
        $this->assertNull($refused->fresh()->payment_voucher_id);
    }

    public function test_the_wrong_requests_are_refused(): void
    {
        $this->assertRefused(fn () => app(ExpenseClaimService::class)->submit($this->cashier, $this->data(ExpenseClaim::EXPENSE, '100')), 'employee', '⛔ কর্মীর খাতা ছাড়া দাবি গেল');
        $this->assertRefused(fn () => app(ExpenseClaimService::class)->submit($this->worker, ['expense_account_id' => $this->cash()->id] + $this->data(ExpenseClaim::EXPENSE, '100')), 'expense_account_id', '⛔ নগদ খাতে "খরচ"');
        $this->assertRefused(fn () => app(ExpenseClaimService::class)->submit($this->worker, ['spent_on' => now()->addDay()->toDateString()] + $this->data(ExpenseClaim::EXPENSE, '100')), 'spent_on', '⛔ ভবিষ্যতের খরচ');
        $this->assertRefused(fn () => app(ExpenseClaimService::class)->submit($this->worker, $this->data(ExpenseClaim::EXPENSE, '0')), 'amount', '⛔ শূন্যের দাবি');
        $this->assertSame(0, ExpenseClaim::query()->count());
    }

    public function test_the_pages_the_phone_and_the_doors(): void
    {
        $this->actingAs($this->worker);
        $this->get(route('hr.claim.create'))->assertOk()->assertSee('data-claim-form', false);
        $this->post(route('hr.claim.store'), $this->data(ExpenseClaim::EXPENSE, '300'))->assertSessionHasNoErrors()->assertRedirect();
        $claim = ExpenseClaim::query()->sole();
        $this->get(route('hr.claim.index'))->assertOk()->assertSee($claim->document_no);
        $this->get(route('hr.claim.show', $claim))->assertOk()->assertSee('data-claim-facts', false);

        // ⛔ অন্যের দাবি — নিজের চাবি থাকলেও নয়
        $this->actingAs($this->cashier);
        $this->get(route('hr.claim.show', $claim))->assertForbidden();
        $this->get(route('hr.claim.index'))->assertOk()->assertDontSee($claim->document_no);

        // ⓘ ফোন — টোকেনে ঢোকা, ওয়েবের লগইনে নয়
        Sanctum::actingAs($this->worker->fresh(), [AuthController::APP]);
        // ⓘ ফোনে HR বন্ধ থাকলে দরজা ৪০৩ — মালিক কন্ট্রোল প্যানেলে চালু করেন
        $this->getJson(route('api.hr.claim.heads'))->assertForbidden()->assertJsonPath('module', 'hr');
        app(SettingsService::class)->set(PhoneModules::PREFIX.'hr', true);
        $this->getJson(route('api.hr.claim.heads'))->assertOk()->assertJsonFragment(['id' => $this->expense->id]);
        $this->postJson(route('api.hr.claim.store'), $this->data(ExpenseClaim::ADVANCE, '700'))->assertCreated()
            ->assertJsonPath('kind', ExpenseClaim::ADVANCE)->assertJsonPath('status', ExpenseClaim::SUBMITTED);
        $this->getJson(route('api.hr.claim.index'))->assertOk()->assertJsonCount(2, 'claims')->assertJsonPath('open_advance', '0.00');
        $this->getJson(route('api.hr.claim.show', $claim->public_id))->assertOk()->assertJsonPath('number', $claim->document_no);

        Sanctum::actingAs($this->cashier->fresh(), [AuthController::APP]);
        $this->getJson(route('api.hr.claim.show', $claim->public_id))->assertForbidden();
        $this->app['auth']->forgetGuards();

        // ⓘ চাবি ছাড়া কেউ দাবি পাঠান না
        $none = $this->member('none@claims.test', []);
        $this->actingAs($none);
        $this->get(route('hr.claim.create'))->assertForbidden();
    }

    public function test_the_roles_that_mark_their_own_attendance_are_given_the_claim_key(): void
    {
        $role = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'hr.attendance.self'))->firstOrFail();
        DB::table('role_has_permissions')->where('role_id', $role->id)
            ->whereIn('permission_id', Permission::query()->where('name', 'hr.claim.self')->select('id'))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (require base_path('app/Modules/Hr/Database/Migrations/2027_02_14_110000_whoever_marks_their_own_attendance_may_ask_for_money.php'))->up();

        $this->assertTrue($role->fresh()->hasPermissionTo('hr.claim.self'), '⛔ নিজের হাজিরার ভূমিকা দাবির চাবি পায়নি');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function submit(string $kind, string $amount, ?UploadedFile $receipt = null): ExpenseClaim
    {
        $this->actingAs($this->worker);
        $claim = app(ExpenseClaimService::class)->submit($this->worker, $this->data($kind, $amount), $receipt);
        $this->actingAs($this->owner);

        return $claim->fresh(['paymentVoucher', 'settleVoucher']);
    }

    /** মালিকের সই — ছক না থাকলে বসা ছকের একমাত্র স্তর ([[ClaimSigner]]) */
    private function signed(ExpenseClaim $claim): ExpenseClaim
    {
        $this->assertSame(ExpenseClaim::SUBMITTED, $claim->status, '⛔ সইয়ের আগেই দাবি এগোল');

        app(ApprovalEngine::class)->approve(Approval::query()->where('approvable_type', $claim->getMorphClass())
            ->where('approvable_id', $claim->id)->where('status', Approval::PENDING)->sole(), $this->owner);

        return $claim->fresh(['paymentVoucher', 'settleVoucher']);
    }

    /** @return array<string, mixed> */
    private function data(string $kind, string $amount): array
    {
        return ['kind' => $kind, 'amount' => $amount, 'reason' => 'Bus fare to the market', 'spent_on' => now()->toDateString(),
            'expense_account_id' => $kind === ExpenseClaim::EXPENSE ? $this->expense->id : null];
    }

    /** @param  list<string>  $keys */
    private function member(string $email, array $keys): User
    {
        $user = User::factory()->create(['email' => $email, 'current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(array_map(fn ($k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function flow(string $module, string $action): void
    {
        $flow = ApprovalFlow::query()->create(['module' => $module, 'action' => $action, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    /** ভাউচারের পর্দার মতো হাতে লেখা পরিশোধ, দাবির বিপরীতে — ক্যাশিয়ার লেখেন আর পাকা করেন; খাতার দরজা পাকা করার সময় */
    private function payAgainst(ExpenseClaim $claim, string $amount): Voucher
    {
        $this->actingAs($this->cashier);

        return app(VoucherService::class)->post(app(VoucherService::class)->create([
            'type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'by hand',
            'against_type' => ExpenseClaim::drillSourceType(), 'against_id' => $claim->id,
            'party_type' => 'employee', 'party_id' => $this->employee->id,
        ], [
            ['account_id' => $this->expense->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $this->cash()->id, 'debit' => '0', 'credit' => $amount],
        ]));
    }

    private function open(): string
    {
        return (string) LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)
            ->where('party_type', 'employee')->where('party_id', $this->employee->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
    }

    private function cash(): Account
    {
        return Account::query()->whereKey(app(CashTillService::class)->ensurePrimaryTill()->account_id)->firstOrFail();
    }

    private function assertRefused(callable $what, string $field, string $why): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), $why.' (অন্য ঘরে আটকেছে: '.implode(', ', array_keys($e->errors())).')');

            return;
        }

        $this->fail($why);
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
