<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ঋণ — টার্ম লোন ও CC, পর্দা থেকে খতিয়ান পর্যন্ত।
 *
 * সূচির অঙ্ক LoanScheduleTest-এ যাচাই হয়। এখানে যাচাই হয় তার পরেরটুকু:
 * ফর্ম থেকে পাঠালে সত্যিই দায় জন্মায় কিনা, কিস্তির টাকা আসল আর সুদে
 * ভাগ হয় কিনা, CC সীমা মানে কিনা, আর অনুমতি ছাড়া কেউ ঢুকতে পারে কিনা।
 *
 * প্রতিটা টাকার দাবি দুইভাবে যাচাই হয়: একবার ঋণের নিজের হিসাব
 * (Loan::outstanding), আরেকবার খতিয়ানের সারিগুলো নিজে যোগ করে। দুইটা
 * এক না হলে কোনো একটা মিথ্যা বলছে।
 */
class LoanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();
    }

    /** কোম্পানির একজন সদস্য, কোনো অনুমতি ছাড়াই। */
    private function memberOfCompany(): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    private function service(): LoanService
    {
        return app(LoanService::class);
    }

    private function liabilityAccount(): Account
    {
        return Account::query()->where('code', '2210')->firstOrFail();
    }

    private function interestAccount(): Account
    {
        return Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->firstOrFail();
    }

    /**
     * টাকার একটা খাত — নিজেরাই বানানো।
     *
     * প্রমিত ছকে ১১০২ একটা গ্রুপ, তার নিচে আসল হিসাবগুলো বসে। ডেমো
     * ডেটার উপর ভরসা করলে টেস্টটা ডেমোর বদলের সাথে ভাঙত।
     */
    private function moneyAccount(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => '1102-01'],
            [
                'company_id' => $this->company->id,
                'parent_id' => Account::query()->where('code', StandardChart::BANK_AND_MFS)->value('id'),
                'name_en' => 'Sonali Bank CD',
                'name_bn' => 'সোনালী ব্যাংক চলতি',
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
                'is_group' => false,
                'is_bank' => true,
            ],
        );
    }

    /** খতিয়ানের সারি নিজে যোগ করে দায়ের জের — বকেয়ার স্বাধীন হিসাব। */
    private function liabilityFromLedger(): string
    {
        return LedgerEntry::query()
            ->where('account_id', $this->liabilityAccount()->id)
            ->get()
            ->reduce(
                fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->credit, (string) $e->debit, 4), 4),
                '0',
            );
    }

    /** @param array<string, mixed> $overrides */
    private function term(array $overrides = []): Loan
    {
        return $this->service()->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => Loan::TERM,
                'interest_method' => LoanSchedule::REDUCING,
                'sanctioned' => '1200000',
                'interest_rate' => '12',
                'tenure_months' => 36,
                'start_date' => '2026-07-01',
                'first_instalment_on' => '2026-08-01',
                'liability_account_id' => $this->liabilityAccount()->id,
                'interest_account_id' => $this->interestAccount()->id,
                ...$overrides,
            ],
            intoAccountId: $this->moneyAccount()->id,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function cc(array $overrides = []): Loan
    {
        return $this->service()->create(data: [
            'lender' => 'City Bank',
            'kind' => Loan::CC,
            'sanctioned' => '500000',
            'interest_rate' => '13.5',
            'start_date' => '2026-07-01',
            'liability_account_id' => $this->liabilityAccount()->id,
            'interest_account_id' => $this->interestAccount()->id,
            ...$overrides,
        ]);
    }

    // ── টার্ম লোন ──────────────────────────────────────────────────────

    public function test_a_term_loan_brings_money_in_and_owes_the_same_amount(): void
    {
        $this->actingAs($this->user);

        $loan = $this->term();

        // ঋণের নিজের হিসাব
        $this->assertSame(0, bccomp($loan->outstanding(), '1200000', 4));

        // আর খতিয়ান আলাদা করে গুনে একই কথা বলে
        $this->assertSame(0, bccomp($this->liabilityFromLedger(), '1200000', 4));

        // টাকাটা সত্যিই ব্যাংকে ঢুকেছে
        $money = LedgerEntry::query()
            ->where('account_id', $this->moneyAccount()->id)
            ->sum('debit');

        $this->assertSame(0, bccomp((string) $money, '1200000', 4));
    }

    public function test_a_term_loan_gets_its_full_schedule(): void
    {
        $this->actingAs($this->user);

        $loan = $this->term();

        $this->assertCount(36, $loan->instalments);
        $this->assertSame('2026-08-01', $loan->instalments->first()->due_date->toDateString());
        $this->assertSame('2029-07-01', $loan->instalments->last()->due_date->toDateString());
    }

    public function test_paying_an_instalment_splits_the_money_into_principal_and_interest(): void
    {
        $this->actingAs($this->user);

        $loan = $this->term();
        $first = $loan->instalments->first();

        $before = $loan->outstanding();

        $this->service()->payInstalment($first, $this->moneyAccount()->id, '2026-08-01');

        // দায় কমেছে ঠিক আসলের সমান — সুদের অংশ নয়
        $after = $loan->fresh()->outstanding();
        $this->assertSame(0, bccomp(bcsub($before, $after, 4), (string) $first->principal, 4));

        // আর সুদটা খরচে বসেছে
        $interest = LedgerEntry::query()
            ->where('account_id', $this->interestAccount()->id)
            ->sum('debit');

        $this->assertSame(0, bccomp((string) $interest, (string) $first->interest, 4));
        $this->assertTrue($first->fresh()->isPaid());
    }

    public function test_a_bank_that_takes_more_puts_the_extra_on_the_principal(): void
    {
        $this->actingAs($this->user);

        $loan = $this->term();
        $first = $loan->instalments->first();

        $extra = bcadd($first->total(), '500', 4);

        $this->service()->payInstalment($first, $this->moneyAccount()->id, '2026-08-01', $extra);

        // সুদ সূচির মানেই থেকেছে — খরচের খাত ব্যাংকের কাগজের সাথে মেলে
        $interest = LedgerEntry::query()
            ->where('account_id', $this->interestAccount()->id)
            ->sum('debit');
        $this->assertSame(0, bccomp((string) $interest, (string) $first->interest, 4));

        // বাড়তিটা আসলে গেছে, তাই দায় ৫০০ টাকা বেশি কমেছে
        $expected = bcsub('1200000', bcadd((string) $first->principal, '500', 4), 4);
        $this->assertSame(0, bccomp($loan->fresh()->outstanding(), $expected, 4));
    }

    public function test_the_same_instalment_cannot_be_paid_twice(): void
    {
        $this->actingAs($this->user);

        $loan = $this->term();
        $first = $loan->instalments->first();

        $this->service()->payInstalment($first, $this->moneyAccount()->id, '2026-08-01');

        $this->expectException(ValidationException::class);
        $this->service()->payInstalment($first->fresh(), $this->moneyAccount()->id, '2026-08-02');
    }

    public function test_a_term_loan_ends_at_zero_when_every_instalment_is_paid(): void
    {
        $this->actingAs($this->user);

        // ছোট মেয়াদ, নাহলে ৩৬টা পরিশোধ টেস্টটা অকারণে ভারী করে
        $loan = $this->term(['tenure_months' => 6]);

        foreach ($loan->instalments as $row) {
            $this->service()->payInstalment($row, $this->moneyAccount()->id, $row->due_date->toDateString());
        }

        $this->assertSame(0, bccomp($loan->fresh()->outstanding(), '0', 4));
        $this->assertTrue($loan->fresh()->isSettled());
        $this->assertSame(0, bccomp($this->liabilityFromLedger(), '0', 4));
    }

    // ── CC ─────────────────────────────────────────────────────────────

    public function test_a_cc_owes_nothing_until_money_is_drawn(): void
    {
        $this->actingAs($this->user);

        $loan = $this->cc();

        // সীমা মঞ্জুর হওয়া আর টাকা নেওয়া এক জিনিস নয়
        $this->assertSame(0, bccomp($loan->outstanding(), '0', 4));
        $this->assertSame(0, bccomp($loan->available(), '500000', 4));
        $this->assertCount(0, $loan->instalments);
        $this->assertSame(0, LedgerEntry::query()->where('account_id', $this->liabilityAccount()->id)->count());
    }

    public function test_drawing_and_repaying_a_cc_moves_the_outstanding_both_ways(): void
    {
        $this->actingAs($this->user);

        $loan = $this->cc();

        $this->service()->drawDown($loan, '300000', $this->moneyAccount()->id, '2026-07-05');
        $this->assertSame(0, bccomp($loan->fresh()->outstanding(), '300000', 4));
        $this->assertSame(0, bccomp($loan->fresh()->available(), '200000', 4));

        $this->service()->repay($loan->fresh(), '120000', $this->moneyAccount()->id, '2026-07-20');
        $this->assertSame(0, bccomp($loan->fresh()->outstanding(), '180000', 4));
        $this->assertSame(0, bccomp($this->liabilityFromLedger(), '180000', 4));
    }

    public function test_a_cc_cannot_be_drawn_past_its_limit(): void
    {
        $this->actingAs($this->user);

        $loan = $this->cc();
        $this->service()->drawDown($loan, '450000', $this->moneyAccount()->id, '2026-07-05');

        $this->expectException(ValidationException::class);
        $this->service()->drawDown($loan->fresh(), '60000', $this->moneyAccount()->id, '2026-07-06');
    }

    public function test_cc_interest_raises_the_debt_without_moving_any_money(): void
    {
        $this->actingAs($this->user);

        $loan = $this->cc();
        $this->service()->drawDown($loan, '300000', $this->moneyAccount()->id, '2026-07-05');

        $moneyBefore = $this->ledgerNetOf($this->moneyAccount());

        $this->service()->chargeInterest($loan->fresh(), '3375', '2026-07-31');

        // ধার বেড়েছে
        $this->assertSame(0, bccomp($loan->fresh()->outstanding(), '303375', 4));

        // অথচ নগদ-ব্যাংকে একটা পয়সাও নড়েনি
        $this->assertSame(0, bccomp($this->ledgerNetOf($this->moneyAccount()), $moneyBefore, 4));
    }

    private function ledgerNetOf(Account $account): string
    {
        return LedgerEntry::query()
            ->where('account_id', $account->id)
            ->get()
            ->reduce(
                fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
                '0',
            );
    }

    // ── পর্দা ও অনুমতি ─────────────────────────────────────────────────

    public function test_the_screens_open_and_show_the_loan(): void
    {
        $this->actingAs($this->user);

        $loan = $this->term();

        $this->get(route('accounts.loan.index'))
            ->assertOk()
            ->assertSee('Sonali Bank')
            ->assertSee($loan->document_no);

        $this->get(route('accounts.loan.create'))->assertOk();

        $this->get(route('accounts.loan.show', $loan->id))
            ->assertOk()
            ->assertSee($loan->document_no)
            ->assertSee(number_format((float) $loan->instalments->first()->total(), 2));
    }

    public function test_the_form_creates_a_term_loan_with_its_money_and_schedule(): void
    {
        $this->actingAs($this->user)
            ->post(route('accounts.loan.store'), [
                'lender' => 'Janata Bank',
                'kind' => Loan::TERM,
                'interest_method' => LoanSchedule::FLAT,
                'sanctioned' => '600000',
                'interest_rate' => '10',
                'tenure_months' => 12,
                'start_date' => '2026-07-01',
                'first_instalment_on' => '2026-08-01',
                'liability_account_id' => $this->liabilityAccount()->id,
                'interest_account_id' => $this->interestAccount()->id,
                'into_account_id' => $this->moneyAccount()->id,
            ])
            ->assertRedirect();

        $loan = Loan::query()->where('lender', 'Janata Bank')->firstOrFail();

        $this->assertCount(12, $loan->instalments);
        $this->assertSame(0, bccomp($loan->outstanding(), '600000', 4));
    }

    /**
     * ফিচারটার আগে তৈরি হওয়া কোম্পানিতেও ঋণ সেভ হয়।
     *
     * ── পরীক্ষক যা পেয়েছেন (১০ আগস্ট ২০২৬, ১০:৩৩) ──────────────────
     * `/accounts/loans/create` থেকে সব ঘর ভরে "Save loan" চাপলে সোজা
     * HTTP 500, আর লগে: "No number series is configured for document
     * type 'LN'."
     *
     * কারণটা ব্যবহারকারীর কিছু নয়: সিরিজগুলো বসে কোম্পানি তৈরির দিনে,
     * ওই দিন যত ডকুমেন্ট টাইপ ঘোষিত ছিল তত। ঋণ এসেছে পরে, 'LN' নিয়ে —
     * তাই পুরনো কোম্পানিগুলোর জন্য সারিটা কোনোদিন বসেনি।
     *
     * ── কেন সিরিজটা এখানে মুছে ফেলা হয় ─────────────────────────────
     * DemoSeeder আজকের কোড দিয়ে কোম্পানি বানায়, তাই তার 'LN' সিরিজ
     * আছেই — অর্থাৎ সাধারণ পরীক্ষা এই অবস্থাটায় কোনোদিন পৌঁছাত না, আর
     * পরীক্ষক যা পেয়েছেন তা এখানে কখনো ধরা পড়ত না। সারিটা মুছে দিয়ে
     * ঠিক ওই পুরনো কোম্পানিটাই বানানো হয়।
     */
    public function test_a_company_older_than_the_loan_feature_can_still_save_one(): void
    {
        NumberSeries::query()->where('doc_type', 'LN')->delete();

        $this->assertSame(0, NumberSeries::query()->where('doc_type', 'LN')->count());

        $this->actingAs($this->user)
            ->post(route('accounts.loan.store'), [
                'lender' => 'City Bank',
                'kind' => Loan::TERM,
                'interest_method' => LoanSchedule::REDUCING,
                'sanctioned' => '200000',
                'interest_rate' => '10',
                'tenure_months' => 12,
                'start_date' => '2026-08-01',
                'liability_account_id' => $this->liabilityAccount()->id,
                'interest_account_id' => $this->interestAccount()->id,
                'into_account_id' => $this->moneyAccount()->id,
            ])
            ->assertRedirect();

        $loan = Loan::query()->where('lender', 'City Bank')->firstOrFail();

        // নম্বরটা সত্যিই বসেছে — খালি নয়, আর ঘোষিত উপসর্গ ধরে
        $this->assertStringStartsWith('LN-', $loan->document_no);

        // আর টাকাটাও এসেছে: সূচি ও দায়, দুইটাই
        $this->assertCount(12, $loan->instalments);
        $this->assertSame(0, bccomp($loan->outstanding(), '200000', 4));
    }

    public function test_a_term_loan_from_the_form_must_say_where_the_money_lands(): void
    {
        $this->actingAs($this->user)
            ->post(route('accounts.loan.store'), [
                'lender' => 'Rupali Bank',
                'kind' => Loan::TERM,
                'interest_method' => LoanSchedule::REDUCING,
                'sanctioned' => '100000',
                'interest_rate' => '9',
                'tenure_months' => 12,
                'start_date' => '2026-07-01',
                'liability_account_id' => $this->liabilityAccount()->id,
                'interest_account_id' => $this->interestAccount()->id,
            ])
            ->assertSessionHasErrors('into_account_id');

        $this->assertSame(0, Loan::query()->where('lender', 'Rupali Bank')->count());
    }

    public function test_a_cc_from_the_form_needs_no_tenure(): void
    {
        $this->actingAs($this->user)
            ->post(route('accounts.loan.store'), [
                'lender' => 'Dutch-Bangla Bank',
                'kind' => Loan::CC,
                'sanctioned' => '400000',
                'interest_rate' => '13',
                'start_date' => '2026-07-01',
                'liability_account_id' => $this->liabilityAccount()->id,
                'interest_account_id' => $this->interestAccount()->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $loan = Loan::query()->where('lender', 'Dutch-Bangla Bank')->firstOrFail();

        $this->assertTrue($loan->isCc());
        $this->assertSame(0, bccomp($loan->outstanding(), '0', 4));
    }

    public function test_paying_an_instalment_through_the_screen_marks_it_paid(): void
    {
        $this->actingAs($this->user);

        $loan = $this->term(['tenure_months' => 6]);
        $first = $loan->instalments->first();

        $this->actingAs($this->user)
            ->post(route('accounts.loan.instalment.pay', [$loan->id, $first->id]), [
                'from_account_id' => $this->moneyAccount()->id,
                'trx_date' => '2026-08-01',
            ])
            ->assertRedirect();

        $this->assertSame(LoanInstalment::PAID, $first->fresh()->status);
    }

    public function test_an_instalment_of_another_loan_cannot_be_paid_from_this_one(): void
    {
        $this->actingAs($this->user);

        $mine = $this->term(['tenure_months' => 6]);
        $other = $this->term(['lender' => 'Agrani Bank', 'tenure_months' => 6]);

        // অন্য ঋণের কিস্তির নম্বর দিলে ৪০৪ — নইলে একটা ঋণের টাকা
        // আরেকটার কিস্তিতে বসানো যেত
        $this->actingAs($this->user)
            ->post(route('accounts.loan.instalment.pay', [$mine->id, $other->instalments->first()->id]), [
                'from_account_id' => $this->moneyAccount()->id,
            ])
            ->assertNotFound();
    }

    public function test_a_reader_can_look_but_not_touch(): void
    {
        $this->actingAs($this->user);
        $loan = $this->cc();

        $reader = $this->memberOfCompany();
        $reader->givePermissionTo(Permission::findOrCreate('accounts.loan.view', 'web'));

        $this->actingAs($reader)->get(route('accounts.loan.index'))->assertOk();
        $this->actingAs($reader)->get(route('accounts.loan.show', $loan->id))->assertOk();

        $this->actingAs($reader)->get(route('accounts.loan.create'))->assertForbidden();
        $this->actingAs($reader)
            ->post(route('accounts.loan.draw', $loan->id), [
                'amount' => '1000',
                'into_account_id' => $this->moneyAccount()->id,
            ])
            ->assertForbidden();
    }

    public function test_someone_with_no_loan_permission_sees_nothing(): void
    {
        $this->actingAs($this->memberOfCompany())->get(route('accounts.loan.index'))->assertForbidden();
    }
}
