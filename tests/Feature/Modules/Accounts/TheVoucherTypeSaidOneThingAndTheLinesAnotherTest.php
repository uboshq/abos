<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ভাউচারের ধরন বলল এক কথা, সারিগুলো আরেক — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ক (৭ অক্টোবর ২০২৬; খরচ: fe-র বিকল্প ক১)।
 *
 * ⭐ হাতে লেখা নতুন ভাউচারে ([[VoucherService::assertTemplate()]]):
 *   জাবেদা — টাকার খাত নয়; পাওনা/দেনার সারি কারো নামে · কনট্রা — কেবল টাকার খাত (বদলির চার্জ ছাড়া) ·
 *   আদায় — টাকা ঢোকে · পরিশোধ — টাকা বেরোয় · খরচ — টাকা বেরোয় অথবা পক্ষসহ দেনা হয়, টাকার খাতে ডেবিট কোনোটাতেই নয়।
 * ⓘ ব্যবস্থার নিজের ভাউচার (byHand ছাড়া) আগের মতো।
 */
final class TheVoucherTypeSaidOneThingAndTheLinesAnotherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_journal_takes_no_money_and_a_contra_takes_only_money(): void
    {
        $this->refused(Voucher::JOURNAL, [$this->dr($this->expense()), $this->cr($this->cash())], 'journal');
        $this->accepted(Voucher::JOURNAL, [$this->dr($this->expense()), $this->cr($this->income())]);

        $this->refused(Voucher::CONTRA, [$this->dr($this->bank()), $this->cr($this->income())], 'contra');
        $this->accepted(Voucher::CONTRA, [$this->dr($this->bank()), $this->cr($this->cash())]);
        // ⓘ বদলির চার্জ কনট্রার নিজের সারি
        $this->accepted(Voucher::CONTRA, [$this->dr($this->bank(), '95'), $this->dr(StandardChart::find(StandardChart::BANK_CHARGES), '5'), $this->cr($this->cash())]);
    }

    public function test_a_receipt_brings_money_in_and_a_payment_takes_it_out(): void
    {
        $this->refused(Voucher::RECEIPT, [$this->dr($this->expense()), $this->cr($this->income())], 'receipt');
        $this->refused(Voucher::RECEIPT, [$this->dr($this->income()), $this->cr($this->cash())], 'receipt');
        $this->accepted(Voucher::RECEIPT, [$this->dr($this->cash()), $this->cr($this->income())]);

        $this->refused(Voucher::PAYMENT, [$this->dr($this->cash()), $this->cr($this->income())], 'payment');
        $this->refused(Voucher::PAYMENT, [$this->dr($this->expense()), $this->cr($this->income())], 'payment');
        $this->accepted(Voucher::PAYMENT, [$this->dr($this->expense()), $this->cr($this->bank())]);
    }

    public function test_an_expense_takes_money_out_or_owes_someone_and_never_debits_money(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $owed = StandardChart::find(StandardChart::LABOUR_PAYABLE);

        $this->accepted(Voucher::EXPENSE, [$this->dr($this->expense()), $this->cr($this->cash())]);
        // ⭐ বাকিতে — পর্দার "বাকিতে" দল (ক১)
        $this->accepted(Voucher::EXPENSE, [$this->dr($this->expense()), $this->cr($owed)], ['party_type' => 'supplier', 'party_id' => $supplier->id]);

        $this->refused(Voucher::EXPENSE, [$this->dr($this->expense()), $this->cr($this->income())], 'expense');
        $this->refused(Voucher::EXPENSE, [$this->dr($this->bank()), $this->cr($owed)], 'expense');
        $this->refused(Voucher::EXPENSE, [$this->dr($this->cash()), $this->cr($this->bank())], 'expense');
        // ⓘ দেনায় ডেবিট মানে দেনা কমা — বাকিতে খরচ নয়
        $this->refused(Voucher::EXPENSE, [$this->dr($owed), $this->cr($this->income())], 'expense', ['party_type' => 'supplier', 'party_id' => $supplier->id]);
    }

    public function test_a_journal_line_on_a_receivable_or_payable_names_its_party(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $receivable = $this->leaf(StandardChart::RECEIVABLE);
        $payable = StandardChart::find(StandardChart::VENDOR_PAYABLE);

        $this->refused(Voucher::JOURNAL, [$this->dr($this->expense()), $this->cr($payable)], 'control_needs_party');
        $this->refused(Voucher::JOURNAL, [$this->dr($receivable), $this->cr($this->income())], 'control_needs_party');
        // ⓘ হাতধার আর কর্মীর অগ্রিমও কারো নামে (fe, ৭ অক্টোবর ২০২৬)
        $this->refused(Voucher::JOURNAL, [$this->dr($this->leaf(StandardChart::HAND_LOAN)), $this->cr($this->income())], 'control_needs_party');
        $this->refused(Voucher::JOURNAL, [$this->dr($this->leaf(StandardChart::EMPLOYEE_ADVANCE)), $this->cr($this->income())], 'control_needs_party');

        $this->accepted(Voucher::JOURNAL, [$this->dr($this->expense()), [...$this->cr($payable), 'party_type' => 'supplier', 'party_id' => $supplier->id]]);
        $this->accepted(Voucher::JOURNAL, [[...$this->dr($receivable), 'party_type' => 'customer', 'party_id' => $customer->id], $this->cr($this->income())]);
        // ⓘ মাথার পক্ষও চলে — সারি না বললে সেটাই নামে
        $this->accepted(Voucher::JOURNAL, [$this->dr($receivable), $this->cr($this->income())], ['party_type' => 'customer', 'party_id' => $customer->id]);
    }

    public function test_the_screen_is_held_and_the_systems_own_vouchers_are_not(): void
    {
        [$a] = [$this->expense()];

        $this->post(route('accounts.voucher.store', 'journal'), [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'নগদে জাবেদা', 'save_as_draft' => '1',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $this->cash()->id, 'debit' => '0', 'credit' => '100']],
        ])->assertSessionHasErrors('lines');
        $this->assertSame(0, Voucher::query()->count(), '⛔ পর্দা থেকে নগদের জাবেদা জমা হলো।');

        // ⓘ সম্পাদনার পথেও — ঠিক খসড়া রেখে পরে নগদ বসালে আটকায়
        $this->post(route('accounts.voucher.store', 'journal'), [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ঠিক জাবেদা', 'save_as_draft' => '1',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $this->income()->id, 'debit' => '0', 'credit' => '100']],
        ])->assertSessionHasNoErrors();
        $draft = Voucher::query()->latest('id')->firstOrFail();
        $this->put(route('accounts.voucher.update', $draft), [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'নগদ বসল', 'save_as_draft' => '1',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $this->cash()->id, 'debit' => '0', 'credit' => '100']],
        ])->assertSessionHasErrors('lines');
        $this->assertFalse($draft->fresh(['lines.account'])->lines->contains(fn ($l) => $l->account?->money_kind !== null), '⛔ সম্পাদনায় জাবেদায় নগদ বসল।');
        $draft->delete();

        // ⓘ ব্যবস্থার নিজের জাবেদা (নগদ গণনার ঘাটতি, আন্তঃকোম্পানি…) — নিজের সেবার ছাঁচে
        app(VoucherService::class)->create($this->data(Voucher::JOURNAL), [$this->dr($a), $this->cr($this->cash())]);
        $this->assertSame(1, Voucher::query()->count());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $lines  @param  array<string, mixed>  $extra */
    private function refused(string $type, array $lines, string $why, array $extra = []): void
    {
        try {
            app(VoucherService::class)->create($this->data($type, $extra), $lines, byHand: true);
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                explode(':account', __('accounts::voucher.template_'.$why))[0],
                (string) collect($e->errors())->flatten()->first(),
                "⛔ {$type}: ভুল কারণে থামল।",
            );

            return;
        }

        $this->fail("⛔ {$type}: ছাঁচ ভাঙা ভাউচার ({$why}) জমা হলো।");
    }

    /** @param  list<array<string, mixed>>  $lines  @param  array<string, mixed>  $extra */
    private function accepted(string $type, array $lines, array $extra = []): void
    {
        $voucher = app(VoucherService::class)->create($this->data($type, $extra), $lines, byHand: true);
        $this->assertTrue($voucher->exists, "⛔ {$type}: ঠিক ভাউচারও থামল।");
    }

    /** @return array<string, mixed> */
    private function data(string $type, array $extra = []): array
    {
        return ['type' => $type, 'trx_date' => now()->toDateString(), 'narration' => 'ছাঁচের পরীক্ষা', ...$extra];
    }

    /** @return array<string, mixed> */
    private function dr(Account $account, string $amount = '100'): array
    {
        return ['account_id' => $account->id, 'debit' => $amount, 'credit' => '0'];
    }

    /** @return array<string, mixed> */
    private function cr(Account $account, string $amount = '100'): array
    {
        return ['account_id' => $account->id, 'debit' => '0', 'credit' => $amount];
    }

    private function cash(): Account
    {
        return Account::query()->postable()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
    }

    private function bank(): Account
    {
        return Account::query()->postable()->active()->where('money_kind', Account::BANK)->orderBy('code')->first()
            ?? app(AccountService::class)->create([
                'code' => StandardChart::BANK.'-01',
                'name_en' => 'Test bank account',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            ]);
    }

    private function expense(): Account
    {
        return StandardChart::find(StandardChart::HAMMALI);
    }

    private function income(): Account
    {
        return StandardChart::find(StandardChart::RENT_INCOME);
    }

    private function leaf(string $code): Account
    {
        $root = StandardChart::find($code);

        return $root->is_group
            ? Account::query()->postable()->whereKey($root->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail()
            : $root;
    }
}
