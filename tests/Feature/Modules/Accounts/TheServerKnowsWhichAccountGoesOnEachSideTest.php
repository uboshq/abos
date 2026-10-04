<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গ৪ — ভাউচার আর স্থানান্তরের দুই পাশে কোন ধরনের খাত বসবে, সার্ভার নিজে যাচাই করে; আর মাথার পক্ষ এই কোম্পানির কি না
 * (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে পর্দার তালিকাই ছিল একমাত্র বেড়া। হাতে বানানো অনুরোধে পরিশোধ "পাওনা খাত থেকে", রসিদ "খরচের খাতে",
 * স্থানান্তর "খরচের খাতে" বসানো যেত — খরচ আর পরিশোধের অনুমোদন এড়িয়ে; আর মাথায় অন্য কোম্পানির গ্রাহক বসত।
 *
 * ⓘ নিয়ম পর্দার তালিকারই হুবহু ([[DepositFormOptions::sidesFor()]]):
 *   রসিদ — টাকা যায় টাকার খাতে · পরিশোধ — টাকা আসে টাকার খাত থেকে · খরচ — টাকার খাত বা প্রদেয় থেকে, খরচের খাতে ·
 *   কন্ট্রা — দুই দিকেই টাকার খাত · স্থানান্তর — কাউন্টার বা ব্যাংক।
 */
final class TheServerKnowsWhichAccountGoesOnEachSideTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_a_payment_cannot_come_out_of_the_receivable(): void
    {
        $this->send(Voucher::PAYMENT, [
            'from_account_id' => $this->code(StandardChart::RECEIVABLE)->id,
            'to_account_id' => $this->expense()->id,
        ])->assertSessionHasErrors('from_account_id');
    }

    public function test_a_receipt_cannot_land_in_an_expense_account(): void
    {
        $this->send(Voucher::RECEIPT, [
            'from_account_id' => $this->code(StandardChart::OWNER_CAPITAL)->id,
            'to_account_id' => $this->expense()->id,
        ])->assertSessionHasErrors('to_account_id');
    }

    public function test_an_expense_must_land_in_an_expense_account(): void
    {
        $this->send(Voucher::EXPENSE, [
            'from_account_id' => $this->bank()->id,
            'to_account_id' => $this->code(StandardChart::RECEIVABLE)->id,
        ])->assertSessionHasErrors('to_account_id');
    }

    public function test_an_expense_is_paid_from_money_or_owed_not_from_anywhere(): void
    {
        $this->send(Voucher::EXPENSE, [
            'from_account_id' => $this->code(StandardChart::OWNER_CAPITAL)->id,
            'to_account_id' => $this->expense()->id,
        ])->assertSessionHasErrors('from_account_id');
    }

    /** ⓘ বাকিতে খরচ বৈধ — প্রদেয়ের যেকোনো সন্তান খাত থেকে (পর্দার "বাকিতে" দল) */
    public function test_an_expense_on_credit_from_a_payable_passes(): void
    {
        $this->send(Voucher::EXPENSE, [
            'from_account_id' => $this->code(StandardChart::PAYABLE)->id,
            'to_account_id' => $this->expense()->id,
        ])->assertSessionDoesntHaveErrors(['from_account_id', 'to_account_id']);
    }

    public function test_a_contra_moves_money_between_money_accounts_only(): void
    {
        $this->send(Voucher::CONTRA, [
            'from_account_id' => $this->bank()->id,
            'to_account_id' => $this->expense()->id,
        ])->assertSessionHasErrors('to_account_id');
    }

    public function test_the_party_on_the_header_must_belong_to_this_company(): void
    {
        $stranger = Customer::query()->withoutGlobalScopes()->where('company_id', '<>', $this->company->id)->first()
            ?? tap(Customer::query()->firstOrFail()->replicate(['public_id']), function (Customer $c) {
                $c->forceFill([
                    'company_id' => Company::query()->whereKeyNot($this->company->id)->firstOrFail()->id,
                    'code' => 'G4-STRANGER',
                ])->saveQuietly();
            });

        $this->send(Voucher::RECEIPT, [
            'from_account_id' => $this->code(StandardChart::RECEIVABLE)->id,
            'to_account_id' => $this->bank()->id,
            'party_type' => 'customer',
            'party_id' => $stranger->id,
        ])->assertSessionHasErrors('party_id');
    }

    public function test_a_right_payment_passes_every_side_check(): void
    {
        $this->send(Voucher::EXPENSE, [
            'from_account_id' => $this->bank()->id,
            'to_account_id' => $this->expense()->id,
        ])->assertSessionDoesntHaveErrors(['from_account_id', 'to_account_id', 'party_id']);
    }

    public function test_a_transfer_cannot_go_into_an_expense_account(): void
    {
        $from = app(CashTillService::class)->ensurePrimaryTill();

        $this->from(route('accounts.transfer.create'))
            ->post(route('accounts.transfer.store'), [
                'trx_date' => now()->toDateString(),
                'from_till_id' => $from->id,
                'destination' => 'account:'.$this->expense()->id,
                'amount' => '100',
            ])->assertSessionHasErrors('to_account_id');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $fields */
    private function send(string $type, array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('accounts.voucher.create', ['type' => $type]))
            ->post(route('accounts.voucher.store', ['type' => $type]), [
                'type' => $type,
                'trx_date' => now()->toDateString(),
                'amount' => '100',
                'narration' => 'গ৪',
                'instrument_no' => 'G4-1',
                'save_as_draft' => 1,
                ...$fields,
            ]);
    }

    private function code(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function expense(): Account
    {
        return Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();
    }

    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->first()
            ?? Account::query()->create([
                'company_id' => $this->company->id,
                'code' => '1102-G4',
                'name_en' => 'G4 Bank',
                'name_bn' => 'গ৪ ব্যাংক',
                'parent_id' => StandardChart::find(StandardChart::BANK)->id,
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
                'money_kind' => Account::BANK,
                'is_active' => true,
                'status' => DocumentStatus::CONFIRMED,
            ]);
    }
}
