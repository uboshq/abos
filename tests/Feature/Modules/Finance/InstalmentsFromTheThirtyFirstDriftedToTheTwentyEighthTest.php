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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ৩১ তারিখে শুরু হওয়া মাসিক কিস্তি ফেব্রুয়ারির পরে চিরকাল ২৮ তারিখে সরে যেত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (বীমা ⓘ৮)।
 *
 * ⓘ কিস্তির দিন আগের কিস্তি থেকে এক মাস যোগে গোনা হত; ফেব্রুয়ারিতে ২৮ হয়ে সেটাই পরের সব মাসে বয়ে যেত, আর মেয়াদের শেষে একটা
 * বাড়তি কিস্তি বসত।
 *
 * ⭐ দাবি: ৩১ জানুয়ারি থেকে ৩০ জুলাই, মাসিক — ছয় কিস্তি, প্রতিটা মাসের শেষ দিনে শুরু; ফাঁক বা জোড়া দিন নেই; যোগফল হুবহু প্রিমিয়াম।
 */
final class InstalmentsFromTheThirtyFirstDriftedToTheTwentyEighthTest extends TestCase
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

    public function test_each_monthly_instalment_is_counted_from_the_start_day(): void
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Month Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'M31-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '6000', 'frequency' => InsurancePolicy::MONTHLY,
            'starts_on' => '2026-01-31', 'ends_on' => '2026-07-30',
        ]);

        $rows = InsurancePremium::query()->where('policy_id', $policy->id)->orderBy('period_from')->get();

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'],
            $rows->map(fn ($r) => $r->period_from->toDateString())->all(),
            '⛔ মাসিক কিস্তির দিন ২৮ তারিখে সরে গেল (বা বাড়তি কিস্তি বসল)',
        );
        $this->assertSame(
            ['2026-02-27', '2026-03-30', '2026-04-29', '2026-05-30', '2026-06-29', '2026-07-30'],
            $rows->map(fn ($r) => $r->period_to->toDateString())->all(),
            '⛔ কিস্তির শেষ দিন পরের কিস্তির আগের দিন নয় — ফাঁক বা জোড়া দিন',
        );
        $this->assertSame(0, bccomp($rows->reduce(fn ($c, $r) => bcadd($c, (string) $r->amount, 4), '0'), '6000', 4), '⛔ কিস্তির যোগফল প্রিমিয়াম নয়');
    }
}
