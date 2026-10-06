<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use App\Modules\Finance\Models\InsurancePrepayment;
use App\Modules\Finance\Services\InsurancePrepaymentService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ প্রিমিয়াম মাসে মাসে খরচ — অর্থ-মডিউলের পরিকল্পনা ৬.৩, ৬ অক্টোবর ২০২৬ ([[InsurancePrepaymentService]]; সমন্বয়কের
 * উত্তর প্র২ আর সিদ্ধান্ত ক)।
 *
 * ⭐ দাবি:
 *   · মাস শেষে মেয়াদের না-আসা অংশ খরচ থেকে অগ্রিম বীমায় (1136), দিনের ভাগে; পরের মাসের ১ তারিখে উল্টো — ফলে মাসের শেষে
 *     খরচ = মেয়াদের যত দিন গেল তার ভাগ, আর 1136 = বাকিটা
 *   · খরচের খাত পরিশোধ ভাউচারের — 5221 নয় এমন খাতে দেওয়া প্রিমিয়াম সেই খাত থেকেই সরে, 5221 ছোঁয়া হয় না
 *   · এক কিস্তিতে এক মাস একবারই; না-শেষ মাস নয়; না-দেওয়া বা মাস শেষের পরে দেওয়া কিস্তি নয়
 *   · বন্ধ মাসে কিছুই বসে না — উল্টোটাও নয় (গোটা মাস এক লেনদেনে)
 *   · বোতাম কেবল বীমা চালানোর চাবিতে; দেখার চাবিতে নয়
 */
final class APremiumIsExpensedMonthByMonthTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private InsurancePrepaymentService $prepaid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->prepaid = app(InsurancePrepaymentService::class);
    }

    public function test_month_end_moves_the_unexpired_days_to_prepaid_and_the_next_month_turns_it_back(): void
    {
        // ⓘ ১২,০০০ — ১ জুলাই ২০২৬ থেকে ৩০ জুন ২০২৭, ৩৬৫ দিন; ৫ জুলাই দেওয়া
        $premium = $this->paidPremium('Y-1', '12000', '2026-07-01', '2027-06-30', '2026-07-05', $this->insuranceHead());

        $this->assertSame(['prepaid' => 1, 'reversed' => 0], $this->prepaid->run(Carbon::parse('2026-08-01')));

        $aug = InsurancePrepayment::query()->where('premium_id', $premium->id)->firstOrFail();
        $this->assertSame([303, 365], [$aug->days_left, $aug->days_total], 'আগস্টের শেষে মেয়াদের ৩০৩ দিন বাকি।');
        $this->assertSame(0, bccomp((string) $aug->amount, '9961.64', 4), '১২,০০০ × ৩০৩ ÷ ৩৬৫ নয়।');
        $this->assertSame(0, bccomp($this->balance(StandardChart::PREPAID_INSURANCE, '2026-08-31'), '9961.64', 4));
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_PREMIUM, '2026-08-31'), '2038.36', 4),
            '⛔ আগস্টের শেষে খরচ ৬২ দিনের ভাগ নয়।');

        // ⓘ সেপ্টেম্বর — আগস্টেরটা ১ সেপ্টেম্বর উল্টায়, তারপর ২৭৩ দিনের অগ্রিম
        $this->assertSame(['prepaid' => 1, 'reversed' => 1], $this->prepaid->run(Carbon::parse('2026-09-01')));
        $this->assertSame('2026-09-01', $aug->fresh()->reversalVoucher->trx_date->toDateString(), 'উল্টো দাখিলা পরের মাসের ১ তারিখে নয়।');
        $this->assertSame(0, bccomp($this->balance(StandardChart::PREPAID_INSURANCE, '2026-09-30'), '8975.34', 4),
            '⛔ মাস শেষে অগ্রিম কেবল এই মাসের — আগেরটা উল্টায়নি?');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_PREMIUM, '2026-09-30'), '3024.66', 4),
            '⛔ সেপ্টেম্বরের শেষে খরচ ৯২ দিনের ভাগ নয়।');

        // ⓘ একই মাস আবার — কিছুই নয়
        $vouchers = Voucher::query()->count();
        $this->assertSame(['prepaid' => 0, 'reversed' => 0], $this->prepaid->run(Carbon::parse('2026-09-01')));
        $this->assertSame($vouchers, Voucher::query()->count(), '⛔ একই মাস দুইবার বসল।');
    }

    public function test_the_expense_comes_back_from_the_account_the_payment_used(): void
    {
        $other = Account::query()->where('type', Account::EXPENSE)->where('is_group', false)
            ->where('code', '!=', StandardChart::INSURANCE_PREMIUM)->orderBy('code')->firstOrFail();
        $this->paidPremium('O-1', '3650', '2026-07-01', '2027-06-30', '2026-07-03', $other);

        $this->prepaid->run(Carbon::parse('2026-08-01'));

        $row = InsurancePrepayment::query()->firstOrFail();
        $this->assertSame($other->id, (int) $row->expense_account_id, '⛔ অগ্রিম পরিশোধের খাত থেকে সরল না।');
        $this->assertSame(0, bccomp($this->balance($other->code, '2026-08-31'), '620', 4), '৩,৬৫০ − ৩০৩ দিন × ১০ = ৬২০ নয়।');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INSURANCE_PREMIUM, '2026-08-31'), '0', 4),
            '⛔ যে খাতে প্রিমিয়াম বসেনি, সেই 5221 থেকে টাকা সরল।');
    }

    public function test_unpaid_or_later_paid_parts_and_unfinished_months_stay_out(): void
    {
        $this->policy('U-1', '5000', '2026-07-01', '2027-06-30');   // ⓘ দেওয়াই হয়নি
        $this->paidPremium('L-1', '7300', '2026-07-01', '2027-06-30', '2026-09-10', $this->insuranceHead());   // ⓘ আগস্টের পরে দেওয়া

        // ⓘ খরচে বসেনি (অগ্রিমের খাতে দেওয়া) — খরচেই যা নেই, তা সরানো যায় না
        $this->paidPremium('A-1', '1000', '2026-07-01', '2027-06-30', '2026-07-02',
            Account::query()->where('code', StandardChart::ADVANCE)->firstOrFail());

        $this->assertSame([], $this->prepaid->preview(Carbon::parse('2026-08-01')), '⛔ না-দেওয়া, পরে দেওয়া বা খরচে না-বসা কিস্তি অগ্রিমে উঠল।');
        $this->assertCount(1, $this->prepaid->preview(Carbon::parse('2026-09-01')));

        $this->expectException(ValidationException::class);
        $this->prepaid->run(now());
    }

    public function test_a_closed_month_books_nothing_not_even_the_reversal(): void
    {
        $this->paidPremium('C-1', '12000', '2026-07-01', '2027-06-30', '2026-07-05', $this->insuranceHead());
        $this->prepaid->run(Carbon::parse('2026-08-01'));

        PeriodLock::query()->create(['company_id' => CompanyContext::id(), 'year' => 2026, 'month' => 9,
            'reason' => 'test', 'locked_by' => auth()->id(), 'locked_at' => now()]);

        $vouchers = Voucher::query()->count();

        try {
            $this->prepaid->run(Carbon::parse('2026-09-01'));
            $this->fail('⛔ বন্ধ মাসে অগ্রিম বসল।');
        } catch (ValidationException) {
        }

        $this->assertSame(1, InsurancePrepayment::query()->count(), '⛔ বন্ধ মাসে সেপ্টেম্বরের সারি রয়ে গেল।');
        $this->assertNull(InsurancePrepayment::query()->firstOrFail()->reversal_voucher_id, '⛔ বন্ধ মাসে আগস্টেরটা উল্টাল।');
        $this->assertSame($vouchers, Voucher::query()->count(), '⛔ থেমে যাওয়া মাসের আধা-লেখা ভাউচার রয়ে গেল।');
    }

    public function test_only_the_insurance_managers_key_runs_the_button(): void
    {
        $this->paidPremium('B-1', '12000', '2026-07-01', '2027-06-30', '2026-07-05', $this->insuranceHead());

        $this->get(route('finance.insurance.index'))->assertOk()->assertSee('data-insurance-prepaid', false);
        $this->post(route('finance.insurance.prepay'), ['month' => now()->format('Y-m')])->assertSessionHasErrors('month');

        $viewer = User::factory()->create(['is_active' => true, 'current_company_id' => CompanyContext::id()]);
        $viewer->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        CompanyContext::forCompany(CompanyContext::id(), fn () => $viewer->givePermissionTo(Permission::findOrCreate('finance.insurance.view', 'web')));

        $this->actingAs($viewer)->get(route('finance.insurance.index'))->assertOk()->assertDontSee('data-insurance-prepaid', false);
        $this->actingAs($viewer)->post(route('finance.insurance.prepay'), ['month' => '2026-08'])->assertForbidden();
        $this->assertSame(0, InsurancePrepayment::query()->count());

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner)->post(route('finance.insurance.prepay'), ['month' => '2026-08'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, InsurancePrepayment::query()->count());

        $policy = InsurancePolicy::query()->where('policy_no', 'B-1')->firstOrFail();
        $this->get(route('finance.insurance.show', $policy))->assertOk()->assertSee('data-insurance-prepayments', false);
        $this->assertSame($owner->id, (int) InsurancePrepayment::query()->value('created_by'), 'কে বসাল, লেখা নেই।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    private function balance(string $code, string $on): string
    {
        return (string) Account::query()->where('code', $code)->firstOrFail()->balanceOn($on);
    }

    private function insuranceHead(): Account
    {
        return Account::query()->where('code', StandardChart::INSURANCE_PREMIUM)->firstOrFail();
    }

    private function policy(string $no, string $premium, string $from, string $to): InsurancePolicy
    {
        $insurer = Institution::query()->create([
            'company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer '.$no,
        ]);

        return app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => $no, 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '500000', 'premium' => $premium, 'starts_on' => $from, 'ends_on' => $to,
        ]);
    }

    /** পলিসি বসিয়ে প্রিমিয়ামটা পরিশোধ ভাউচারে দেওয়া — খরচের খাত `$head` */
    private function paidPremium(string $no, string $amount, string $from, string $to, string $paidOn, Account $head): InsurancePremium
    {
        $premium = $this->policy($no, $amount, $from, $to)->premiums()->firstOrFail();
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $this->putMoneyIn($cash, $amount, $paidOn);

        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::PAYMENT, 'trx_date' => $paidOn, 'narration' => 'premium '.$no,
            'against_type' => 'insurance_premium', 'against_id' => $premium->id,
        ], [
            ['account_id' => $head->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => $amount],
        ]));

        $this->assertTrue($premium->fresh()->isPaid(), 'দাবির ভিত্তি নেই — প্রিমিয়াম দেওয়া হয়নি।');

        return $premium->fresh();
    }
}
