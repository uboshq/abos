<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use App\Modules\Hr\Support\AdvanceBalance;
use App\Modules\Hr\Support\ClaimSigner;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ ছক না থাকলে খরচের দাবি সই ছাড়াই অনুমোদিত হত — অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬ (সমন্বয়কের আদেশ; মালিকের নিয়ম "স্বয়ংক্রিয়
 * অনুমোদন বাদ", ২৬ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ [[ExpenseClaimService::submit()]] সইয়ের ইঞ্জিনের `null`-কে "সই লাগে না" ধরত: অগ্রিম থেকে খরচের জাবেদা সাথে সাথে পাকা, আর
 * নগদের খসড়া ক্যাশিয়ারের কাছে — কোনো মানুষের সই ছাড়া। এখন ছক না থাকলে মালিকের সইয়ের ছক বসে ([[ClaimSigner]]); দাবি সইয়ের
 * অপেক্ষায়, খাতায় কিছু নয়; মালিক সই দিলে তবেই।
 */
final class AClaimNeverPassesWithoutASignatureTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $worker;

    private Employee $employee;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->worker = User::factory()->create(['email' => 'signer-less@claims.test', 'current_company_id' => $this->company->id, 'is_active' => true]);
        $this->worker->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $this->worker->givePermissionTo(Permission::findOrCreate('hr.claim.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->employee = app(EmployeeService::class)->create(['code' => 'EMP-SGN', 'name_en' => 'Needs A Signature', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $this->employee->forceFill(['user_id' => $this->worker->id])->save();

        $this->putMoneyIn(app(CashTillService::class)->ensurePrimaryTill()->account, '100000', now()->subDay()->toDateString());
        $this->expense = Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();

        // ⓘ ছক নেই — দৃশ্যটা এটাই
        ApprovalFlow::query()->where('module', 'hr')->delete();
    }

    public function test_with_no_flow_the_claim_waits_for_the_owner_and_nothing_is_booked(): void
    {
        // ⓘ খোলা অগ্রিম ৫০০ — আগে দাবি এলে অগ্রিম থেকে কাটার জাবেদা সাথে সাথে পাকা হত
        $this->advance('500');
        $rows = LedgerEntry::query()->count();

        $claim = $this->submit(ExpenseClaim::EXPENSE, '1200');

        $this->assertSame(ExpenseClaim::SUBMITTED, $claim->status, '⛔ ছক নেই — দাবি সই ছাড়াই অনুমোদিত হল');
        $this->assertNull($claim->settle_voucher_id, '⛔ সইয়ের আগেই অগ্রিম থেকে কাটার জাবেদা');
        $this->assertNull($claim->payment_voucher_id, '⛔ সইয়ের আগেই নগদের খসড়া');
        $this->assertSame($rows, LedgerEntry::query()->count(), '⛔ সইয়ের আগেই খাতায় কিছু বসল');
        $this->assertSame(0, Voucher::query()->where('narration', 'like', '%'.$claim->document_no.'%')->count());

        $approval = Approval::query()->where('module', 'hr')->where('action', ExpenseClaim::ACTION_EXPENSE)->where('status', Approval::PENDING)->sole();
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval, $this->worker->fresh()), '⛔ যিনি চাইলেন তিনিই সই দিতে পারেন');
        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval, $this->owner->fresh()), 'মালিক সই দিতে পারার কথা');

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $claim->refresh();
        $this->assertSame(ExpenseClaim::APPROVED, $claim->status, '⛔ মালিকের সইয়ে অনুমোদিত হল না');
        $this->assertSame(DocumentStatus::CONFIRMED, Voucher::query()->findOrFail($claim->settle_voucher_id)->status, 'সইয়ের পরে অগ্রিম থেকে কাটা খাতায়');
        $this->assertTrue(Voucher::query()->findOrFail($claim->payment_voucher_id)->isDraft(), 'সইয়ের পরে বাকিটুকুর খসড়া');
        $this->assertSame('0.00', app(AdvanceBalance::class)->open($this->employee, Carbon::today()));
    }

    public function test_an_advance_with_no_flow_waits_too(): void
    {
        $claim = $this->submit(ExpenseClaim::ADVANCE, '700');

        $this->assertSame(ExpenseClaim::SUBMITTED, $claim->status, '⛔ অগ্রিম সই ছাড়াই অনুমোদিত হল');
        $this->assertNull($claim->payment_voucher_id);
        $flow = ApprovalFlow::query()->where('module', 'hr')->where('action', ExpenseClaim::ACTION_ADVANCE)->sole();
        $this->assertSame(ApprovalFlowStep::BY_ROLE, $flow->steps->sole()->approver_type);
        $this->assertSame(PermissionSyncer::SUPER_ADMIN_ROLE, Role::query()->findOrFail($flow->steps->sole()->approver_id)->name, 'ডিফল্ট সইদাতা মালিক নন');
    }

    public function test_a_company_whose_owner_switched_every_flow_off_gets_no_new_one(): void
    {
        // ⓘ লাইভের UB: মালিক সব ছক বন্ধ রেখেছেন (৩ অক্টোবর ২০২৬), hr-এ কোনো ছক নেই
        ApprovalFlow::query()->create(['module' => 'sales', 'action' => 'order', 'is_active' => false]);
        ApprovalFlow::query()->update(['is_active' => false]);
        $this->app->forgetInstance(ApprovalEngine::class);

        $claim = $this->submit(ExpenseClaim::EXPENSE, '300');
        (require base_path('app/Modules/Hr/Database/Migrations/2027_02_15_110000_a_claim_never_passes_without_a_signature.php'))->up();

        $this->assertSame(0, ApprovalFlow::query()->where('is_active', true)->count(), '⛔ মালিকের বন্ধ রাখা কোম্পানিতে চালু ছক বসল');
        $this->assertSame(0, ApprovalFlow::query()->where('module', 'hr')->count());
        $this->assertSame(ExpenseClaim::APPROVED, $claim->status, 'মালিক অনুমোদন বন্ধ রেখেছেন — তাঁর সিদ্ধান্ত');
    }

    public function test_the_companys_own_flow_is_left_as_it_is(): void
    {
        $signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $own = ApprovalFlow::query()->create(['module' => 'hr', 'action' => ExpenseClaim::ACTION_EXPENSE, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $own->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);
        $this->app->forgetInstance(ApprovalEngine::class);

        $claim = $this->submit(ExpenseClaim::EXPENSE, '300');

        $this->assertSame(ExpenseClaim::SUBMITTED, $claim->status);
        $this->assertSame(1, ApprovalFlow::query()->where('module', 'hr')->where('action', ExpenseClaim::ACTION_EXPENSE)->count(), '⛔ কোম্পানির ছক থাকতেও আরেকটা বসল');
        $this->assertSame([ApprovalFlowStep::BY_USER.'#'.$signer->id], $own->fresh()->steps->map(fn ($s) => $s->approver_type.'#'.$s->approver_id)->all(), '⛔ কোম্পানির ছক বদলে গেল');
    }

    public function test_with_no_owner_role_nothing_is_sent(): void
    {
        Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->update(['name' => 'former_owner']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            $this->submit(ExpenseClaim::EXPENSE, '300');
            $this->fail('⛔ সইদাতা না থাকতেও দাবি গেল');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::claim.no_owner_to_sign'), $e->errors()['kind'][0] ?? null);
        }

        $this->assertSame(0, ExpenseClaim::query()->count(), '⛔ সইদাতা ছাড়া দাবি বসে রইল');
    }

    public function test_the_migration_sets_the_owner_flow_once_and_keeps_a_companys_own(): void
    {
        $other = Company::query()->where('code', '<>', 'TDEPOT')->orderBy('id')->firstOrFail();

        (require base_path('app/Modules/Hr/Database/Migrations/2027_02_15_110000_a_claim_never_passes_without_a_signature.php'))->up();
        (require base_path('app/Modules/Hr/Database/Migrations/2027_02_15_110000_a_claim_never_passes_without_a_signature.php'))->up();

        foreach ([$this->company, $other] as $company) {
            foreach (ClaimSigner::ACTIONS as $action) {
                $this->assertSame(1, ApprovalFlow::query()->withoutGlobalScopes()->where('company_id', $company->id)
                    ->where('module', 'hr')->where('action', $action)->count(), "⛔ {$company->code}: {$action}-এর ছক একটা নয়");
            }
        }

        $this->assertSame(0, DB::table('approval_flows')->where('module', 'hr')->where('is_active', false)->count());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function submit(string $kind, string $amount): ExpenseClaim
    {
        $this->actingAs($this->worker);
        $claim = app(ExpenseClaimService::class)->submit($this->worker, ['kind' => $kind, 'amount' => $amount, 'reason' => 'Bus fare',
            'spent_on' => now()->toDateString(), 'expense_account_id' => $kind === ExpenseClaim::EXPENSE ? $this->expense->id : null]);
        $this->actingAs($this->owner);

        return $claim->fresh();
    }

    private function advance(string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'payment_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->subDay()->toDateString(), lines: [
            ['account_id' => StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id, 'debit' => $amount, 'party_type' => 'employee', 'party_id' => $this->employee->id],
            ['account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id, 'credit' => $amount],
        ]);
    }
}
