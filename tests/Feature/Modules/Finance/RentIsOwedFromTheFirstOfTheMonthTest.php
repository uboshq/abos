<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ মাসের ভাড়া মাসের শুরুতে প্রদেয় — মালিকের সিদ্ধান্ত প্র২, ৬ অক্টোবর ২০২৬ ([[RentalAccrualService]])।
 *
 * ⛔ মাসের প্রথম দিনে Dr খরচ / Cr ২১৪১, ঐ মাসের দরে; দেওয়ার দিন ২১৪১ শোধ, খরচ দুইবার নয়, তফাত কেবল খরচে; এক মাস একবার;
 * আগে দেওয়া মাস, সামনের মাস, বন্ধ মাস নয়; সই বাকি থাকলে দেওয়া যায় না, শেষ সইয়ে চুক্তি চালুই থাকে, "না" হলে মাস খোলে।
 */
final class RentIsOwedFromTheFirstOfTheMonthTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        // ⓘ আজ = মাসের ১০ তারিখ
        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
        $this->putMoneyIn($this->cash(), '1000000', $this->month(-3)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_month_is_expensed_on_its_first_day_and_the_payment_clears_the_payable(): void
    {
        $contract = $this->open('First Day Landlord', $this->month(-3), '10000');

        $this->assertSame(['accrued' => 1, 'held' => 0, 'failed' => []], app(RentalAccrualService::class)->run($this->month(0)));
        $this->assertSame(['accrued' => 0, 'held' => 0, 'failed' => []], app(RentalAccrualService::class)->run($this->month(0)), '⛔ একই মাস দুইবার');

        $accrual = RentalAccrual::query()->sole();
        $this->assertSame(DocumentStatus::CONFIRMED, $accrual->voucher->status);
        $this->assertTrue($accrual->voucher->is_adjusting, '⛔ ভাড়ার বকেয়ায় সমন্বয় দাগ নেই।'); // ⭐ মাসশেষের সমন্বয় দাগ (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
        $this->assertSame($this->month(0)->toDateString(), $accrual->voucher->trx_date->toDateString(), 'মাসের প্রথম দিনে');
        $this->assertMoney('-10000', $this->net(StandardChart::RENT_PAYABLE), 'মাসের শুরুতে দায়');
        $this->assertMoney('10000', $this->netOf($contract->expense_account_id), 'মাসের শুরুতে খরচ');

        $this->pay($contract, $this->month(0), '10000');
        $this->assertMoney('0', $this->net(StandardChart::RENT_PAYABLE), '⛔ দেওয়ার পরেও দায় রয়ে গেল');
        $this->assertMoney('10000', $this->netOf($contract->expense_account_id), '⛔ একই মাসের ভাড়া দুইবার খরচে');

        // ⓘ প্রদেয় না বসা মাস — দেওয়ার ভাউচারেই খরচ, ২১৪১ নড়ে না
        $this->pay($contract, $this->month(-1), '10000');
        $this->assertMoney('0', $this->net(StandardChart::RENT_PAYABLE), 'প্রদেয় ছাড়া মাস ২১৪১ ছুঁল');
        $this->assertMoney('20000', $this->netOf($contract->expense_account_id), 'প্রদেয় ছাড়া মাসের খরচ পড়েনি');
    }

    public function test_only_the_difference_moves_the_expense_and_each_month_takes_its_own_rent(): void
    {
        $more = $this->open('Paid More Landlord', $this->month(-3), '10000');
        $less = $this->open('Paid Less Landlord', $this->month(-3), '10000');
        app(RentalContractService::class)->reviseTerms($less, ['monthly_rent' => '12000', 'effective_from' => $this->month(-1)->format('Y-m')]);

        app(RentalAccrualService::class)->run($this->month(-2));
        app(RentalAccrualService::class)->run($this->month(-1));

        $amounts = RentalAccrual::query()->where('rental_contract_id', $less->id)->orderBy('for_month')->pluck('amount')->map(fn ($a) => bcadd((string) $a, '0', 0))->all();
        $this->assertSame(['10000', '12000'], $amounts, '⛔ পুরনো মাস এখনকার দরে বসল');

        $this->pay($more->fresh(), $this->month(-1), '11000');
        $this->pay($less->fresh(), $this->month(-1), '11500');

        // ⓘ বসানো: ১০,০০০ + ১০,০০০ (মাস −২) + ১০,০০০ + ১২,০০০ (মাস −১) = ৪২,০০০। মাস −১ শোধ: ১০,০০০ + ১২,০০০ ২১৪১ থেকে;
        // বেশি দেওয়া ১,০০০ খরচে Dr, কম দেওয়া ৫০০ খরচে Cr
        $this->assertSame($more->expense_account_id, $less->expense_account_id, 'দৃশ্যটাই বানানো যায়নি — দুই চুক্তির খরচ দুই খাতে');
        $this->assertMoney('-20000', $this->net(StandardChart::RENT_PAYABLE), '⛔ শোধ বসানো অঙ্কে হয়নি — মাস −২ বাকি থাকার কথা');
        $this->assertMoney('42500', $this->netOf($more->expense_account_id), '⛔ তফাত খরচে যায়নি');
    }

    public function test_a_paid_month_a_future_month_a_closed_month_and_a_closed_contract_are_not_booked(): void
    {
        $paid = $this->open('Paid Ahead Landlord', $this->month(-3), '10000');
        $this->open('Later Landlord', $this->month(1), '10000');
        $gone = $this->open('Gone Landlord', $this->month(-3), '10000');
        $gone->forceFill(['status' => RentalContract::CLOSED])->save();

        $this->pay($paid, $this->month(-1), '10000');
        $this->assertSame(0, app(RentalAccrualService::class)->run($this->month(-1))['accrued'], '⛔ আগে দেওয়া মাস আবার খরচে, বা বন্ধ চুক্তি বা সামনের চুক্তি বসল');
        $this->assertMoney('10000', $this->netOf($paid->expense_account_id), '⛔ আগে দেওয়া মাসের খরচ দুইবার');

        $this->assertRefused(fn () => app(RentalAccrualService::class)->run($this->month(1)), 'month', '⛔ সামনের মাসের ভাড়া খরচে বসল');

        $this->closeMonth($this->month(-2));
        $this->assertRefused(fn () => app(RentalAccrualService::class)->run($this->month(-2)), 'month', '⛔ বন্ধ মাসে প্রদেয় বসল');
        $this->assertSame(0, RentalAccrual::query()->count());
    }

    public function test_the_signature_holds_it_the_payment_waits_and_a_refusal_frees_the_month(): void
    {
        $contract = $this->open('Signed Landlord', $this->month(-3), '10000');
        $this->flow();

        $this->assertSame(['accrued' => 1, 'held' => 1, 'failed' => []], app(RentalAccrualService::class)->run($this->month(-1)));
        $this->assertMoney('0', $this->net(StandardChart::RENT_PAYABLE), '⛔ সই ছাড়াই প্রদেয় খাতায়');
        $this->assertRefused(fn () => $this->pay($contract->fresh(), $this->month(-1), '10000'), 'for_month', '⛔ সই বাকি প্রদেয় শোধ হল');

        $this->sign();
        $this->assertMoney('-10000', $this->net(StandardChart::RENT_PAYABLE), '⛔ শেষ সই পড়ল, অথচ প্রদেয় বসেনি');
        $this->assertSame(RentalContract::ACTIVE, $contract->fresh()->status, '⛔ প্রদেয়ের সই চুক্তি বন্ধ করে দিল');

        app(RentalAccrualService::class)->run($this->month(0));
        $draft = RentalAccrual::query()->whereDate('for_month', $this->month(0)->toDateString())->sole()->voucher;
        $this->refuse();

        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status);
        $this->assertSame(RentalContract::ACTIVE, $contract->fresh()->status);
        $this->assertSame(0, RentalAccrual::query()->whereDate('for_month', $this->month(0)->toDateString())->count(), '⛔ ফেরানো মাস আটকে রইল');
        $this->assertSame(1, app(RentalAccrualService::class)->run($this->month(0))['accrued']);
    }

    /**
     * ⛔ সামনের মাসের ভাড়া আগে দিলে অগ্রিম (১১৩৭), খরচ নয়; মাসটা এলে মাসের জমা সেটা খরচে সরায়, একবারই; সই বাকি থাকলে নয়
     * (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ ⛔৬)।
     */
    public function test_a_month_paid_ahead_waits_as_prepaid_and_is_expensed_when_it_comes(): void
    {
        // ⓘ চলতি মাস, প্রদেয় না বসা — আগের মতোই সরাসরি খরচে, অগ্রিমে নয়
        $now = $this->open('This Month Landlord', $this->month(-1), '8000');
        $this->pay($now, $this->month(0), '8000');
        $this->assertMoney('8000', $this->netOf((int) $now->expense_account_id), '⛔ চলতি মাসের ভাড়া খরচে যায়নি');
        $this->assertMoney('0', $this->net(StandardChart::PREPAID_RENT), '⛔ চলতি মাসের ভাড়া অগ্রিমে বসল');
        // ⓘ এর কাজ শেষ — পরের মাসের প্রদেয় যেন নিচের অঙ্কে না মেশে
        $now->forceFill(['status' => RentalContract::CLOSED])->save();

        $contract = $this->open('Ahead Landlord', $this->month(-1), '10000');
        $expense = (int) $contract->expense_account_id;
        $this->assertSame((int) $now->expense_account_id, $expense, 'দৃশ্যটাই বানানো যায়নি — দুই চুক্তির খরচ দুই খাতে');
        $base = $this->netOf($expense);

        $this->pay($contract, $this->month(1), '10000');
        $this->assertMoney($base, $this->netOf($expense), '⛔ সামনের মাসের ভাড়া আজই খরচে');
        $this->assertMoney('10000', $this->net(StandardChart::PREPAID_RENT), '⛔ আগাম ভাড়া অগ্রিমে বসেনি');

        // ⓘ মাসটা এলো
        Carbon::setTestNow($this->month(1)->addDays(2));
        $this->assertSame(1, app(RentalAccrualService::class)->run(now()->startOfMonth())['accrued']);
        $this->assertSame(0, app(RentalAccrualService::class)->run(now()->startOfMonth())['accrued'], '⛔ আগাম মাস দুইবার খরচে');
        $this->assertMoney(bcadd($base, '10000', 4), $this->netOf($expense), '⛔ আগাম মাস নিজের মাসে খরচে যায়নি');
        $this->assertMoney('0', $this->net(StandardChart::PREPAID_RENT), '⛔ অগ্রিম মোছেনি');
        // ⭐ মাসশেষের সমন্বয় দাগ (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
        $released = \App\Modules\Accounts\Models\Voucher::query()->where('type', \App\Modules\Accounts\Models\Voucher::JOURNAL)
            ->whereHas('lines', fn ($q) => $q->where('account_id', StandardChart::find(StandardChart::PREPAID_RENT)->id)->where('credit', '>', 0))->get();
        $this->assertNotEmpty($released);
        $this->assertTrue($released->every(fn ($v) => $v->is_adjusting), '⛔ অগ্রিম ভাড়া সরানোয় সমন্বয় দাগ নেই।');
        $this->assertMoney('0', $this->net(StandardChart::RENT_PAYABLE), '⛔ আগাম দেওয়া মাসে আবার প্রদেয় বসল');
        $this->assertSame(now()->startOfMonth()->toDateString(), RentalAccrual::query()->sole()->voucher->trx_date->toDateString(), 'মাসের প্রথম দিনে');

        // ⓘ সই বাকি আগাম পরিশোধ — মাস এলেও সরে না, সই পড়লে পরের চালে
        $this->flow();
        $this->pay($contract->fresh(), $this->month(1), '10000');
        Carbon::setTestNow($this->month(1)->addDays(2));
        $this->assertSame(0, app(RentalAccrualService::class)->run(now()->startOfMonth())['accrued'], '⛔ সই বাকি আগাম পরিশোধ খরচে সরল');
        $this->sign();
        $this->assertSame(1, app(RentalAccrualService::class)->run(now()->startOfMonth())['accrued'], '⛔ সই পড়ার পরেও আগাম মাস খরচে সরেনি');
        $this->assertMoney('0', $this->net(StandardChart::PREPAID_RENT), 'অগ্রিম মুছেছে');
    }

    public function test_the_command_and_the_button_run_it(): void
    {
        $this->open('Command Landlord', $this->month(-3), '10000');

        $this->get(route('finance.rental.index'))->assertOk()->assertSee('data-rent-accrual', false);
        $this->post(route('finance.rental.accrue'), ['month' => $this->month(1)->format('Y-m')])->assertSessionHasErrors('month');
        $this->post(route('finance.rental.accrue'), ['month' => $this->month(-1)->format('Y-m')])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, RentalAccrual::query()->count());

        // ⓘ কনসোলে কেউ লগইন নেই — চলতি মাস, কোম্পানি ধরে
        $this->app['auth']->forgetGuards();
        $this->artisan('abos:rent-accrue', ['--company' => 'TDEPOT'])->assertSuccessful();
        $this->artisan('abos:rent-accrue', ['--company' => 'TDEPOT'])->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame(1, RentalAccrual::query()->whereDate('for_month', $this->month(0)->toDateString())->count(), '⛔ কমান্ড চলতি মাস বসায়নি বা দুইবার বসাল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function month(int $offset): Carbon
    {
        return now()->startOfMonth()->addMonths($offset);
    }

    private function open(string $who, Carbon $starts, string $rent): RentalContract
    {
        return app(RentalContractService::class)->open([
            'counterparty' => $who, 'subject' => $who.' place', 'deposit_amount' => '0', 'monthly_rent' => $rent,
            'monthly_adjustment' => '0', 'term_months' => 36, 'starts_on' => $starts->toDateString(), 'rent_day' => 5,
        ]);
    }

    private function pay(RentalContract $contract, Carbon $month, string $rent): void
    {
        app(RentalContractService::class)->adjustMonth($contract, [
            'for_month' => $month->toDateString(), 'rent' => $rent, 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
        ]);
    }

    private function flow(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'rental', 'is_active' => true]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id,
        ]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function pending(): Approval
    {
        return Approval::query()->where('status', Approval::PENDING)->where('action', 'rental')->latest('id')->firstOrFail();
    }

    private function sign(): void
    {
        app(ApprovalEngine::class)->approve($this->pending(), $this->signer);
    }

    private function refuse(): void
    {
        app(ApprovalEngine::class)->reject($this->pending(), $this->signer, 'এখন নয়');
    }

    private function closeMonth(Carbon $month): void
    {
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) $month->year, 'month' => (int) $month->month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);
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

    private function net(string $code): string
    {
        return $this->netOf((int) StandardChart::find($code)->id);
    }

    private function netOf(int $accountId): string
    {
        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
