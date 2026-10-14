<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ সই-হওয়া দাবির টাকা সই-হওয়া খাতেই — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[VoucherService::assertAgainstFits()]])।
 *
 * ⛔ দাবির ভাউচার নিজের সই নেয় না — সই কাগজে। আগে কেবল ধরন, অঙ্ক আর কর্মী মেলানো হত: ৩,০০০ টাকার অগ্রিমের ভাউচারে ডেবিট
 * খরচের খাতে বসিয়ে পাকা করা যেত (অগ্রিম আর কখনো বেতন থেকে কাটা হত না), বা খরচ অন্য খাতে — সই ছাড়াই।
 */
final class AClaimIsPaidIntoItsSignedHeadTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $worker;

    private User $cashier;

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

        $this->worker = $this->member('worker@head.test');
        $this->cashier = $this->member('cashier@head.test');
        $this->employee = app(EmployeeService::class)->create(['code' => 'EMP-HD', 'name_en' => 'Head Worker', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $this->employee->forceFill(['user_id' => $this->worker->id])->save();

        $till = app(CashTillService::class)->ensurePrimaryTill();
        $till->forceFill(['holder_id' => $this->cashier->id])->save();
        $this->putMoneyIn($till->account, '100000', now()->subDay()->toDateString());

        $this->expense = Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();
    }

    public function test_an_advance_cannot_be_paid_into_an_expense_head(): void
    {
        $claim = $this->signed(ExpenseClaim::ADVANCE, '3000');

        $this->assertRefused(fn () => $this->payAgainst($claim, $this->expense, '3000'),
            '⛔ ৩,০০০ টাকার অগ্রিমের সই নিয়ে টাকা খরচের খাতে বসল — বেতন থেকে আর কাটা হবে না।');
        $this->assertSame(ExpenseClaim::APPROVED, $claim->fresh()->status);

        // ⭐ সুখী পথ — সফটওয়্যারের নিজের খসড়া (অগ্রিমের খাতে) আগের মতোই পাকা
        $this->actingAs($this->cashier);
        app(VoucherService::class)->post($claim->fresh()->paymentVoucher);
        $this->assertSame(ExpenseClaim::PAID, $claim->fresh()->status);
    }

    public function test_an_expense_cannot_be_paid_into_another_head(): void
    {
        $claim = $this->signed(ExpenseClaim::EXPENSE, '1200');
        $other = Account::query()->postable()->active()->where('type', Account::EXPENSE)->whereKeyNot($this->expense->id)->orderBy('code')->firstOrFail();

        $this->assertRefused(fn () => $this->payAgainst($claim, $other, '1200'), '⛔ বাসভাড়ার সই নিয়ে টাকা অন্য খরচের খাতে বসল।');
    }

    private function signed(string $kind, string $amount): ExpenseClaim
    {
        $this->actingAs($this->worker);
        $claim = app(ExpenseClaimService::class)->submit($this->worker, ['kind' => $kind, 'amount' => $amount, 'reason' => 'Bus fare',
            'spent_on' => now()->toDateString(), 'expense_account_id' => $kind === ExpenseClaim::EXPENSE ? $this->expense->id : null], null);
        $this->actingAs($this->owner);

        app(ApprovalEngine::class)->approve(Approval::query()->where('approvable_type', $claim->getMorphClass())
            ->where('approvable_id', $claim->id)->where('status', Approval::PENDING)->sole(), $this->owner);

        $claim = $claim->fresh(['paymentVoucher']);
        $this->assertSame(ExpenseClaim::APPROVED, $claim->status, 'প্রস্তুতি — দাবি সই পেয়েছে');

        return $claim;
    }

    private function payAgainst(ExpenseClaim $claim, Account $debit, string $amount): Voucher
    {
        $this->actingAs($this->cashier);

        return app(VoucherService::class)->post(app(VoucherService::class)->create([
            'type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'by hand',
            'against_type' => ExpenseClaim::drillSourceType(), 'against_id' => $claim->id,
            'party_type' => 'employee', 'party_id' => $this->employee->id,
        ], [
            ['account_id' => $debit->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id, 'debit' => '0', 'credit' => $amount],
        ]));
    }

    private function assertRefused(callable $what, string $why): void
    {
        try {
            $what();
            $this->fail($why);
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.against_wrong_account'), $e->errors()['against_id'][0] ?? null,
                $why.' — অন্য কারণে থামল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    private function member(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('hr.claim.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
