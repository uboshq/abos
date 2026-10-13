<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use App\Modules\Finance\Services\InsuranceService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ শূন্য প্রিমিয়ামে নবায়ন করা মেয়াদে পরে অঙ্ক বসালে কিস্তি কখনো বসত না — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (বীমা ⚠️৫)।
 *
 * ⓘ সম্পাদনার শেষ শর্ত ছিল "পলিসিতে একটাও প্রিমিয়ামের সারি নেই" — আগের মেয়াদের সারি থাকায় নতুন মেয়াদের ৫,০০০ "দেওয়া বাকি"-তে
 * উঠত না, আর দেওয়ার পথও ছিল না।
 *
 * ⭐ দাবি:
 *   · শূন্যে নবায়ন, তারপর ৫,০০০ — নতুন মেয়াদে একটা খসড়া সারি, ৫,০০০; আগের মেয়াদের সারি যেমন ছিল
 *   · একই অঙ্কে আবার সম্পাদনা — দ্বিতীয় সারি বসে না
 */
final class APremiumTypedAfterAZeroRenewalWasNeverDueTest extends TestCase
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

    public function test_the_premium_typed_later_on_a_zero_renewal_becomes_due(): void
    {
        $insurance = app(InsuranceService::class);
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Zero Insurer']);
        $start = CarbonImmutable::now()->subYear()->startOfMonth();

        $policy = $insurance->create([
            'institution_id' => $insurer->id, 'policy_no' => 'ZR-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '1000',
            'starts_on' => $start->toDateString(), 'ends_on' => $start->addYear()->subDay()->toDateString(),
        ]);

        $newFrom = $start->addYear();
        $newTo = $newFrom->addYear()->subDay();
        $insurance->renew($policy, ['starts_on' => $newFrom->toDateString(), 'ends_on' => $newTo->toDateString(), 'premium' => '0']);
        $this->assertSame(0, $this->rowsFrom($policy, $newFrom)->count(), 'দৃশ্যটাই বানানো যায়নি — শূন্যে নবায়নে সারি বসল');

        $edit = [
            'institution_id' => $insurer->id, 'policy_no' => 'ZR-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '5000',
            'starts_on' => $newFrom->toDateString(), 'ends_on' => $newTo->toDateString(),
        ];
        $insurance->update($policy->fresh(), $edit);

        $rows = $this->rowsFrom($policy, $newFrom);
        $this->assertCount(1, $rows, '⛔ শূন্যে নবায়ন করা মেয়াদে পরে বসানো প্রিমিয়ামের কোনো কিস্তি বসেনি');
        $this->assertSame(0, bccomp((string) $rows->first()->amount, '5000', 4), '⛔ কিস্তির অঙ্ক ৫,০০০ নয়');
        $this->assertSame(InsurancePremium::DRAFT, $rows->first()->status);
        $this->assertSame(1, InsurancePremium::query()->where('policy_id', $policy->id)->where('period_from', '<', $newFrom->toDateString())->count(),
            '⛔ আগের মেয়াদের সারি বদলে গেল');

        $insurance->update($policy->fresh(), $edit);
        $this->assertCount(1, $this->rowsFrom($policy, $newFrom), '⛔ একই অঙ্কে আবার সম্পাদনায় দ্বিতীয় কিস্তি বসল');
    }

    /** @return \Illuminate\Support\Collection<int, InsurancePremium> */
    private function rowsFrom(InsurancePolicy $policy, CarbonImmutable $from): \Illuminate\Support\Collection
    {
        return InsurancePremium::query()->where('policy_id', $policy->id)->where('period_from', '>=', $from->toDateString())->get();
    }
}
