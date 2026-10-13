<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বীমার টাকার ঘরে "1e5" ৫০০ দিত, আর তিন দশমিকের দাবি কখনো মিটত না — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (বীমা ⓘ৬, ⓘ৭)।
 *
 * ⓘ Laravel-এর `numeric` "1e5" মেনে নেয়, bcmath নেয় না (ValueError)। আর দাবির অনুমোদিত অঙ্ক চার দশমিকে বসত, রসিদ দুইয়ে —
 * ১০০.০০৫-এর আধা পয়সা কখনো আসত না, দাবি নিজে নিষ্পন্ন হত না।
 *
 * ⭐ দাবি:
 *   · নবায়ন, নতুন পলিসি, দাবি জমা, অনুমোদন, টাকা আসা — "1e5" ফর্মের ভুল হয়ে ফেরে, ৫০০ নয়, কিছুই বসে না
 *   · দাবির অঙ্কে তিন দশমিক — ফর্মের ভুল
 *   · পলিসির সম্পাদনায় ঘরের নিজের মান ("4500.0000") আগের মতোই চলে
 */
final class AnInsuranceAmountWrittenAsAnExponentCrashedTheFormTest extends TestCase
{
    use RefreshDatabase;

    private Institution $insurer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Exp Insurer']);
    }

    public function test_an_exponent_premium_is_a_form_error_not_a_crash(): void
    {
        $policy = $this->policy();
        $rows = InsurancePremium::query()->where('policy_id', $policy->id)->count();

        $this->post(route('finance.insurance.renew', $policy), [
            'starts_on' => $policy->ends_on->copy()->addDay()->toDateString(),
            'ends_on' => $policy->ends_on->copy()->addYear()->toDateString(),
            'premium' => '1e5',
        ])->assertRedirect()->assertSessionHasErrors('premium');

        $this->assertSame($rows, InsurancePremium::query()->where('policy_id', $policy->id)->count(), '⛔ "1e5" নবায়নে প্রিমিয়ামের সারি বসল');

        $this->post(route('finance.insurance.store'), [
            'institution_id' => $this->insurer->id, 'policy_no' => 'EXP-2', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '1e6', 'premium' => '1e5',
            'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->subDay()->toDateString(),
        ])->assertRedirect()->assertSessionHasErrors(['premium', 'sum_insured']);
        $this->assertSame(0, InsurancePolicy::query()->where('policy_no', 'EXP-2')->count(), '⛔ "1e5"-এর পলিসি বসল');
    }

    public function test_the_policy_edit_form_still_takes_its_own_four_place_values(): void
    {
        $policy = $this->policy()->fresh();

        $this->put(route('finance.insurance.update', $policy), [
            'institution_id' => $policy->institution_id, 'policy_no' => $policy->policy_no, 'covers' => $policy->covers, 'subject' => $policy->subject,
            'sum_insured' => (string) $policy->sum_insured, 'premium' => (string) $policy->premium,
            'starts_on' => $policy->starts_on->toDateString(), 'ends_on' => $policy->ends_on->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    public function test_claim_amounts_take_plain_taka_to_the_paisa_only(): void
    {
        $policy = $this->policy();

        $this->post(route('finance.insurance.claim.store', $policy), [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Fire', 'claimed_amount' => '1e5',
        ])->assertRedirect()->assertSessionHasErrors('claimed_amount');
        $this->assertSame(0, InsuranceClaim::query()->count(), '⛔ "1e5"-এর দাবি বসল');

        $claim = app(InsuranceClaimService::class)->lodge($policy, [
            'incident_on' => now()->subDays(3)->toDateString(), 'claimed_on' => now()->subDays(2)->toDateString(),
            'incident' => 'Fire', 'claimed_amount' => '1000',
        ]);

        foreach (['1e2', '100.005'] as $bad) {
            $this->post(route('finance.insurance.claim.approve', $claim), [
                'approved_amount' => $bad, 'approved_on' => now()->toDateString(), 'approval_ref' => 'L-X',
            ])->assertRedirect()->assertSessionHasErrors('approved_amount');
        }

        $this->assertNull($claim->fresh()->approved_amount, '⛔ "1e2" বা তিন দশমিকের অনুমোদন বসল — আধা পয়সা কখনো আসবে না');

        $this->post(route('finance.insurance.claim.receive', $claim), [
            'money_account_id' => 1, 'amount' => '1e2', 'received_on' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasErrors('amount');
    }

    private function policy(): InsurancePolicy
    {
        return app(InsuranceService::class)->create([
            'institution_id' => $this->insurer->id, 'policy_no' => 'EXP-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(10)->toDateString(),
        ]);
    }
}
