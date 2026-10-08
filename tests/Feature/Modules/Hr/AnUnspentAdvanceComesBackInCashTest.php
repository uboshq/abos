<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use App\Modules\Hr\Support\AdvanceBalance;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ বাকি অগ্রিম নগদে ফেরত — টাকার পরিকল্পনা দফা ১৩, ধাপ ৪ ("বাকি থাকলে ফেরত বা বেতন থেকে কাটা"; ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ বেতন থেকে কাটা আগে থেকেই ছিল; নগদ ফেরত কেবল হাতে লেখা আদায় ভাউচারে। এখন অগ্রিমের পাতা থেকে খসড়া আদায় — Cr ১১৩১ কর্মীর
 * নামে, ক্যাশিয়ার নিজের টিলে পাকা করেন ([[ExpenseClaimService::takeBackAdvance()]])।
 */
final class AnUnspentAdvanceComesBackInCashTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private User $worker;

    private User $cashier;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $member = function (array $keys) use ($company): User {
            $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
            $user->companies()->attach($company->id, ['is_active' => true]);
            CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(array_map(fn ($k) => Permission::findOrCreate($k, 'web'), $keys)));
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $user->fresh();
        };
        $this->worker = $member(['hr.claim.self']);
        $this->cashier = $member(['hr.claim.view', 'accounts.voucher.create']);

        $this->employee = app(EmployeeService::class)->create(['code' => 'EMP-TB', 'name_en' => 'Took Too Much', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $this->employee->forceFill(['user_id' => $this->worker->id])->save();

        $till = app(CashTillService::class)->ensurePrimaryTill();
        $till->forceFill(['holder_id' => $this->cashier->id])->save();
        $this->putMoneyIn($till->account, '100000', now()->subDay()->toDateString());
    }

    public function test_the_unspent_part_comes_back_through_the_cashiers_own_till(): void
    {
        $advance = $this->paidAdvance('3000');
        $this->assertSame('3000.00', $this->open(), 'প্রস্তুতিটাই ভুল — অগ্রিম ৩,০০০ খোলা থাকার কথা');

        $this->actingAs($this->cashier);
        $this->get(route('hr.claim.show', $advance))->assertOk()->assertSee('data-take-back', false);

        // ⛔ খোলা অগ্রিমের বেশি নয়
        $this->post(route('hr.claim.take_back', $advance), ['amount' => '5000'])->assertSessionHasErrors('amount');

        $this->post(route('hr.claim.take_back', $advance), ['amount' => '1000'])->assertSessionHasNoErrors()->assertRedirect();
        $draft = Voucher::query()->where('type', Voucher::RECEIPT)->where('status', 'draft')->latest('id')->firstOrFail();
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'receipt_voucher')->where('source_id', $draft->id)->count(), '⛔ খসড়াই খাতায় বসল');

        // ⛔ একজনের ফেরতের খসড়া একটাই — দুইটা পাকা হলে অগ্রিম ঋণাত্মক
        $this->post(route('hr.claim.take_back', $advance), ['amount' => '500'])->assertSessionHasErrors('amount');

        app(VoucherService::class)->post($draft->fresh());
        $this->assertSame('2000.00', $this->open(), '⛔ ফেরত পাকা হলেও কর্মীর খোলা অগ্রিম কমেনি');
        $this->assertSame(1, LedgerEntry::query()->where('source_type', 'receipt_voucher')->where('source_id', $draft->id)
            ->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)->where('party_type', 'employee')
            ->where('party_id', $this->employee->id)->where('credit', 1000)->count(), '⛔ ফেরত কর্মীর নামে ১১৩১-এ বসেনি');
    }

    public function test_only_a_voucher_writer_takes_it_back_and_only_from_a_paid_advance(): void
    {
        $advance = $this->paidAdvance('3000');

        // ⓘ কর্মী নিজের দাবি দেখেন, কিন্তু ভাউচার লেখেন না — ঘরও দেখেন না, দরজাও বন্ধ
        $this->actingAs($this->worker);
        $this->get(route('hr.claim.show', $advance))->assertOk()->assertDontSee('data-take-back', false);
        $this->post(route('hr.claim.take_back', $advance), ['amount' => '100'])->assertForbidden();

        // ⓘ খরচের দাবিতে ফেরত নেই
        $this->actingAs($this->worker);
        $expense = app(ExpenseClaimService::class)->submit($this->worker, ['kind' => ExpenseClaim::EXPENSE, 'amount' => '200', 'reason' => 'Bus',
            'spent_on' => now()->toDateString(), 'expense_account_id' => Account::query()->postable()->active()->where('type', Account::EXPENSE)->value('id')]);
        $this->actingAs($this->cashier);
        $this->post(route('hr.claim.take_back', $expense), ['amount' => '100'])
            ->assertSessionHasErrors(['amount' => __('hr::claim.return_only_paid_advance')]);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function paidAdvance(string $amount): ExpenseClaim
    {
        $this->actingAs($this->worker);
        $claim = app(ExpenseClaimService::class)->submit($this->worker, ['kind' => ExpenseClaim::ADVANCE, 'amount' => $amount, 'reason' => 'Trip']);

        $this->actingAs($this->owner);
        app(ApprovalEngine::class)->approve(Approval::query()->where('approvable_type', $claim->getMorphClass())
            ->where('approvable_id', $claim->id)->where('status', Approval::PENDING)->sole(), $this->owner);

        $this->actingAs($this->cashier);
        app(VoucherService::class)->post($claim->fresh()->paymentVoucher);
        $this->assertSame(ExpenseClaim::PAID, $claim->fresh()->status, 'প্রস্তুতিটাই ভুল — অগ্রিম দেওয়া হয়নি');

        return $claim->fresh();
    }

    private function open(): string
    {
        return app(AdvanceBalance::class)->open($this->employee, Carbon::today());
    }
}
