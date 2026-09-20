<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পলিসির মেয়াদ পেরিয়ে গেল, আর কেউ জানল না।
 *
 * ⓘ বীমা পলিসি এখন অর্থে: কোন কোম্পানি, কী বীমা করা, কত টাকার, কবে
 * নবায়ন। এই ফাইল পাহারা দেয় তিনটা জিনিস —
 *   · নবায়নের ৩০ দিন আগে থেকে সতর্কবার্তা, আর মেয়াদ পেরোলেও চুপ না করা
 *   · প্রিমিয়াম "এই খাতা → পরিশোধ ভাউচার → খতিয়ান" পথে: ভাউচার পোস্ট
 *     হলে সারিটা "দেওয়া হয়েছে", বাতিল হলে আবার "বাকি"
 *   · নবায়নে আগের মেয়াদের প্রিমিয়াম হারায় না
 */
final class ThePolicyLapsedAndNobodyKnewTest extends TestCase
{
    use RefreshDatabase;

    private InsuranceService $insurance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->insurance = app(InsuranceService::class);
    }

    /**
     * ⭐ ফর্ম থেকে পলিসি — নতুন বীমা কোম্পানি একই জমায় তালিকায় ওঠে,
     * আর প্রথম মেয়াদের প্রিমিয়াম "দেওয়া বাকি" হয়ে বসে।
     */
    public function test_a_policy_is_written_from_its_own_page_with_a_new_insurer(): void
    {
        $this->get(route('finance.insurance.index'))->assertOk()
            ->assertSee(route('finance.insurance.create'), escape: false);

        $this->get(route('finance.insurance.create'))->assertOk()
            ->assertSee('name="institution_new"', escape: false);

        $this->post(route('finance.insurance.store'), [
            'institution_new' => 'Green Delta Insurance PLC',
            'policy_no' => 'GD-MV-2026-0042',
            'covers' => InsurancePolicy::VEHICLE,
            'subject' => 'Truck DM-T 11-1234',
            'sum_insured' => '2500000',
            'premium' => '18500',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        $policy = InsurancePolicy::query()->where('policy_no', 'GD-MV-2026-0042')->firstOrFail();

        $this->assertSame(Institution::INSURANCE, $policy->institution->kind,
            'ফর্মে লেখা বীমা কোম্পানি বীমার ধরনে বসেনি।');
        $this->assertSame(1, $policy->premiums()->count());
        $this->assertSame(InsurancePremium::DRAFT, $policy->premiums()->first()->status);

        $this->get(route('finance.insurance.show', $policy))->assertOk()
            ->assertSee('Truck DM-T 11-1234')
            ->assertSee('against_type=insurance_premium', escape: false);
    }

    /**
     * ⛔ একই কোম্পানির একই পলিসি নম্বর দুইবার নয়।
     */
    public function test_the_same_policy_number_is_not_written_twice(): void
    {
        $insurer = $this->insurer();
        $this->policy($insurer, 'P-1');

        $this->expectException(ValidationException::class);
        $this->policy($insurer, 'P-1');
    }

    /**
     * ⭐ ৩০ দিনের সতর্কবার্তা — আর মেয়াদ পেরোনোগুলোও তার ভেতরে।
     */
    public function test_renewal_is_warned_from_thirty_days_and_a_lapsed_policy_stays_warned(): void
    {
        $insurer = $this->insurer();

        $far = $this->policy($insurer, 'FAR', ends: now()->addDays(31));
        $near = $this->policy($insurer, 'NEAR', ends: now()->addDays(30));
        $gone = $this->policy($insurer, 'GONE', ends: now()->subDays(3));
        $off = $this->policy($insurer, 'OFF', ends: now()->addDays(5));
        $this->insurance->setActive($off, false);

        $due = InsurancePolicy::query()->dueForRenewal()->pluck('policy_no')->all();

        $this->assertContains('NEAR', $due, '৩০ দিন বাকি থাকা পলিসিতে সতর্কবার্তা নেই।');
        $this->assertContains('GONE', $due, 'মেয়াদ পেরোনো পলিসিতে সতর্কবার্তা চুপ করে গেছে।');
        $this->assertNotContains('FAR', $due, '৩১ দিন বাকি থাকতেই সতর্ক করছে।');
        $this->assertNotContains('OFF', $due, 'বন্ধ করা পলিসি নিয়েও সতর্ক করছে।');

        $page = $this->get(route('finance.insurance.index'))->assertOk();
        $page->assertSee(route('finance.insurance.index', ['tab' => 'due']), escape: false);
        $page->assertSee(__('finance::insurance.due_banner', ['count' => 2]));

        $this->assertTrue($gone->hasLapsed());
        $this->assertFalse($far->isDueForRenewal());
        $this->assertTrue($near->isDueForRenewal());
    }

    /**
     * ⭐ প্রিমিয়াম পরিশোধ ভাউচারে — পোস্ট হলে "দেওয়া হয়েছে", বাতিলে আবার বাকি।
     */
    public function test_the_premium_is_settled_by_the_payment_voucher_and_unsettled_by_its_cancel(): void
    {
        $policy = $this->policy($this->insurer(), 'PAY-1', premium: '12000');
        $premium = $policy->premiums()->firstOrFail();

        $voucher = $this->payFor($premium);

        $premium->refresh();
        $this->assertSame(InsurancePremium::POSTED, $premium->status, 'ভাউচার পোস্ট হলো, প্রিমিয়াম এখনো বাকি।');
        $this->assertSame($voucher->id, (int) $premium->voucher_id);

        $this->get(route('finance.insurance.show', $policy))->assertOk()->assertSee($voucher->document_no);

        app(VoucherService::class)->cancel($voucher->fresh(), 'ভুল');

        $this->assertSame(InsurancePremium::DRAFT, $premium->fresh()->status,
            'ভাউচার বাতিলের পরেও প্রিমিয়াম "দেওয়া হয়েছে" বলছে।');
    }

    /**
     * ⭐ নবায়ন — নতুন মেয়াদের প্রিমিয়াম আলাদা সারিতে; আগেরটা থাকে।
     * ⛔ আর নতুন মেয়াদ আগেরটার ভেতরে শুরু হতে পারে না।
     */
    public function test_renewal_keeps_last_year_s_premium_and_refuses_an_overlap(): void
    {
        $policy = $this->policy($this->insurer(), 'REN-1', ends: now()->addDays(10), premium: '10000');
        $this->payFor($policy->premiums()->firstOrFail());

        $from = $policy->ends_on->copy()->addDay();

        /*
         * ⓘ ফর্মটা আগে দেখা, ব্যর্থ জমার আগে: জমা ব্যর্থ হলে পুরনো লেখা
         * ঘরে ফিরে আসে (`old()`), আর তখন পর্দায় আগের তারিখটাই থাকত —
         * অর্থাৎ পরীক্ষাটা ফর্মের ডিফল্ট নয়, পুরনো মান মাপত।
         */
        $this->get(route('finance.insurance.renew_form', $policy))->assertOk()
            ->assertSee($from->toDateString());

        $this->post(route('finance.insurance.renew', $policy), [
            'starts_on' => $policy->ends_on->toDateString(),
            'ends_on' => $policy->ends_on->copy()->addYear()->toDateString(),
            'premium' => '11000',
        ])->assertSessionHasErrors('starts_on');

        $this->post(route('finance.insurance.renew', $policy), [
            'starts_on' => $from->toDateString(),
            'ends_on' => $from->copy()->addYear()->subDay()->toDateString(),
            'premium' => '11000',
        ])->assertSessionHasNoErrors();

        $policy->refresh();
        $premiums = $policy->premiums()->get();

        $this->assertCount(2, $premiums, 'নবায়নে আগের মেয়াদের প্রিমিয়াম হারিয়েছে, বা নতুনটা বসেনি।');
        $this->assertSame([InsurancePremium::DRAFT, InsurancePremium::POSTED], $premiums->pluck('status')->all());
        $this->assertSame(0, bccomp((string) $policy->premium, '11000', 2));
        $this->assertFalse($policy->isDueForRenewal(), 'নবায়নের পরেও সতর্কবার্তা থেকে গেছে।');
    }

    /**
     * ⓘ পলিসি বদলালে খসড়া প্রিমিয়ামও মেলে — দেওয়াটা ছোঁয়া হয় না।
     */
    public function test_editing_the_premium_moves_an_unpaid_row_but_never_a_paid_one(): void
    {
        $insurer = $this->insurer();
        $policy = $this->policy($insurer, 'ED-1', premium: '5000');

        $this->put(route('finance.insurance.update', $policy), $this->form($policy, ['premium' => '5500']))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $policy->premiums()->first()->amount, '5500', 2),
            'প্রিমিয়াম বদলাল, "দিন" বোতাম এখনো পুরনো অঙ্ক নিয়ে যাবে।');

        $this->payFor($policy->premiums()->first());

        $this->put(route('finance.insurance.update', $policy), $this->form($policy->fresh(), ['premium' => '9999']))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $policy->premiums()->first()->amount, '5500', 2),
            'দেওয়া হয়ে যাওয়া প্রিমিয়ামের অঙ্ক বদলে গেছে — খাতা আর পলিসি দুই কথা বলছে।');
    }

    private function insurer(): Institution
    {
        return Institution::query()->create([
            'company_id' => CompanyContext::id(),
            'kind' => Institution::INSURANCE,
            'name_en' => 'Pragati Insurance '.fake()->unique()->numberBetween(1, 9999),
        ]);
    }

    private function policy(Institution $insurer, string $no, $ends = null, string $premium = '1000'): InsurancePolicy
    {
        $ends ??= now()->addYear();

        return $this->insurance->create([
            'institution_id' => $insurer->id,
            'policy_no' => $no,
            'covers' => InsurancePolicy::WAREHOUSE,
            'subject' => 'Netrakona godown',
            'sum_insured' => '1000000',
            'premium' => $premium,
            'starts_on' => $ends->copy()->subYear()->addDay()->toDateString(),
            'ends_on' => $ends->toDateString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function form(InsurancePolicy $policy, array $over): array
    {
        return [
            'institution_id' => $policy->institution_id,
            'policy_no' => $policy->policy_no,
            'covers' => $policy->covers,
            'subject' => $policy->subject,
            'sum_insured' => (string) $policy->sum_insured,
            'premium' => (string) $policy->premium,
            'starts_on' => $policy->starts_on->toDateString(),
            'ends_on' => $policy->ends_on->toDateString(),
            ...$over,
        ];
    }

    private function payFor(InsurancePremium $premium): Voucher
    {
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $head = Account::query()->where('code', StandardChart::INSURANCE_PREMIUM)->firstOrFail();

        $vouchers = app(VoucherService::class);

        $voucher = $vouchers->create([
            'type' => Voucher::PAYMENT,
            'trx_date' => now()->toDateString(),
            'narration' => 'premium',
            'against_type' => 'insurance_premium',
            'against_id' => $premium->id,
        ], [
            ['account_id' => $head->id, 'debit' => (string) $premium->amount, 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => (string) $premium->amount],
        ]);

        return $vouchers->post($voucher);
    }
}
