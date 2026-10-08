<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ মালিক নিজের দাবিতে নিজেই সই দিলে "একই মানুষ" দাগ — টাকার পরিকল্পনা দফা ১৩, দায়িত্বের ছক (৭ অক্টোবর ২০২৬)।
 *
 * ⓘ পরিকল্পনা: "মালিক একাই সব করলে সিস্টেম কাজ আটকায় না, কিন্তু নিরীক্ষার দাগে 'একই মানুষ' চিহ্ন দিয়ে রাখে।" আগে সিদ্ধান্তের সারিতে
 * দুই নাম থাকত, আলাদা দাগ নয় ([[ExpenseClaimService::noteTheRequesterSigned()]])।
 */
final class AnOwnersOwnClaimIsMarkedTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    public function test_the_owner_signing_their_own_claim_leaves_a_mark_and_signing_someone_elses_does_not(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();
        $this->putMoneyIn(app(CashTillService::class)->ensurePrimaryTill()->account, '100000', now()->subDay()->toDateString());
        $expense = Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();

        $worker = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $worker->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $worker->givePermissionTo(Permission::findOrCreate('hr.claim.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([[$owner, 'EMP-OWN'], [$worker, 'EMP-WRK']] as [$user, $code]) {
            app(EmployeeService::class)->create(['code' => $code, 'name_en' => $code, 'joining_date' => '2026-01-15', 'payment_method' => 'cash'])
                ->forceFill(['user_id' => $user->id])->save();
        }

        $send = function (User $who) use ($expense): ExpenseClaim {
            $this->actingAs($who);

            return app(ExpenseClaimService::class)->submit($who, ['kind' => ExpenseClaim::EXPENSE, 'amount' => '300', 'reason' => 'Fuel',
                'spent_on' => now()->toDateString(), 'expense_account_id' => $expense->id]);
        };
        $sign = function (ExpenseClaim $claim) use ($owner): void {
            $this->actingAs($owner);
            app(ApprovalEngine::class)->approve(Approval::query()->where('approvable_type', $claim->getMorphClass())
                ->where('approvable_id', $claim->id)->where('status', Approval::PENDING)->sole(), $owner);
        };
        $marks = fn (ExpenseClaim $claim) => AuditTrail::query()->where('action', 'own_claim_signed')
            ->where('auditable_type', $claim->getMorphClass())->where('auditable_id', $claim->id)->count();

        // ⓘ মালিকের নিজের দাবি — নিজেই সই; কাজ আটকায় না, দাগ থাকে
        $own = $send($owner);
        $sign($own);
        $this->assertSame(ExpenseClaim::APPROVED, $own->fresh()->status, 'মালিকের নিজের দাবি আটকে গেল');
        $this->assertSame(1, $marks($own), '⛔ মালিক নিজের দাবিতে নিজে সই দিলেন, অথচ নিরীক্ষায় "একই মানুষ" দাগ নেই');

        // ⓘ কর্মীর দাবি — মালিক সই দিলেন; দুই আলাদা মানুষ, দাগ নয়
        $theirs = $send($worker);
        $sign($theirs);
        $this->assertSame(ExpenseClaim::APPROVED, $theirs->fresh()->status);
        $this->assertSame(0, $marks($theirs), '⛔ আলাদা মানুষের সইয়েও "একই মানুষ" দাগ বসল');
    }
}
