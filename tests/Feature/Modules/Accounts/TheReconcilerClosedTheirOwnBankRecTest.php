<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\BankReconciliationService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ যিনি ব্যাংক মেলানো খুললেন, তিনিই "মিলেছে" লিখে বন্ধ করতেন (ERP ১০০%-এর হিসাবের ফাঁক #২৯, ২০ অক্টোবর ২০২৬)।
 *
 * <p>মালিকের maker-checker নিয়ম (১৪ অক্টোবর ২০২৬) নগদ আর মজুদ গোনায় খাটত, ব্যাংক মেলানোয় নয়। একজন হিসাবরক্ষক খাতার
 * সারিতে টিক দিয়ে তফাত শূন্য করে নিজেই বন্ধ করতে পারতেন — ভুল টিক বা লুকানো সারি দ্বিতীয় কেউ দেখত না। SOX-এ ব্যাংক
 * মেলানোর বন্ধে দ্বিতীয় হাতের সই একটা মূল নিয়ন্ত্রণ। ছাড় কেবল super admin-এর (মালিক একা কাজ করলে)।
 */
final class TheReconcilerClosedTheirOwnBankRecTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1102-REC2', 'name_en' => 'City Bank', 'name_bn' => 'সিটি ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK,
        ]);
        $cash = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1101-REC2', 'name_en' => 'Main till', 'name_bn' => 'প্রধান ক্যাশ',
            'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::CASH,
        ]);
        $this->putMoneyIn($cash, '100000', '2026-08-01');

        // ⓘ নগদ থেকে ব্যাংকে ৫০,০০০ — ব্যাংকের খাতে একটা সারি, কাগজেও ৫০,০০০
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create(
            ['type' => Voucher::CONTRA, 'trx_date' => '2026-08-01', 'instrument' => 'transfer', 'instrument_no' => 'REF-REC2'],
            $vouchers->twoLineEntry(Voucher::CONTRA, $cash->id, $this->bank->id, '50000'),
        ));
    }

    public function test_the_reconciler_who_opened_it_cannot_close_it_and_someone_else_can(): void
    {
        $maker = $this->accountant();
        $checker = $this->accountant();
        $recon = $this->agreedReconOpenedBy($maker);

        $this->actingAs($maker);
        $this->get(route('accounts.reconciliation.show', $recon))->assertOk()
            ->assertSee(__('accounts::recon.needs_another_hand'))
            ->assertDontSee(route('accounts.reconciliation.confirm', $recon));
        $this->post(route('accounts.reconciliation.confirm', $recon))->assertSessionHasErrors('status');
        $this->assertTrue($recon->fresh()->isDraft(), '⛔ যিনি খুললেন তিনিই বন্ধ করলেন');

        $this->actingAs($checker);
        $this->get(route('accounts.reconciliation.show', $recon))->assertOk()
            ->assertDontSee(__('accounts::recon.needs_another_hand'))
            ->assertSee(route('accounts.reconciliation.confirm', $recon));
        $this->post(route('accounts.reconciliation.confirm', $recon))->assertSessionHasNoErrors();
        $closed = $recon->fresh();
        $this->assertTrue($closed->isConfirmed(), 'দ্বিতীয় হাত বন্ধ করতে পারল না');
        $this->assertSame((int) $checker->id, (int) $closed->confirmed_by);
    }

    public function test_the_owner_working_alone_may_close_what_they_opened(): void
    {
        $recon = $this->agreedReconOpenedBy($this->owner);

        $this->actingAs($this->owner);
        $this->post(route('accounts.reconciliation.confirm', $recon))->assertSessionHasNoErrors();
        $this->assertTrue($recon->fresh()->isConfirmed(), 'super admin-এর ছাড় নেই — মালিক একা কাজ করলে আটকে গেল');
    }

    public function test_a_reconciliation_that_does_not_agree_still_cannot_be_closed_by_the_second_hand(): void
    {
        $maker = $this->accountant();
        $this->actingAs($maker);
        $recon = app(BankReconciliationService::class)->open([
            'bank_account_id' => $this->bank->id, 'statement_date' => '2026-08-31', 'statement_balance' => '12345',
        ]);

        $this->actingAs($this->accountant());
        $this->post(route('accounts.reconciliation.confirm', $recon))->assertSessionHasErrors('statement_balance');
        $this->assertTrue($recon->fresh()->isDraft());
    }

    private function agreedReconOpenedBy(User $who): BankReconciliation
    {
        $this->actingAs($who);
        $recons = app(BankReconciliationService::class);
        $recon = $recons->open(['bank_account_id' => $this->bank->id, 'statement_date' => '2026-08-31', 'statement_balance' => '50000']);
        $recon = $recons->mark($recon, $recons->candidates($recon)->pluck('id')->all());
        $this->assertTrue($recons->summary($recon)['agrees'], 'দৃশ্যটাই বানানো যায়নি — তফাত শূন্য নয়');
        $this->assertSame((int) $who->id, (int) $recon->created_by);

        return $recon;
    }

    private function accountant(): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        foreach (['accounts.reconciliation.view', 'accounts.reconciliation.manage'] as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        return $user;
    }
}
