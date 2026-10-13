<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Modules\CreditControl\Services\CreditRisk;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ঝুঁকির মাত্রা — নিয়ম ধরে, প্রতিটা কারণ দেখিয়ে, আর কেবল জানায় (বাকি নিয়ন্ত্রণ ধাপ ৩.১)।
 *
 *   · কোনো লক্ষণ নেই → কম, ০, কারণ নেই।
 *   · ৬,০০০ মেয়াদের ৪৫ দিন পরেও বাকি, সীমা ১০,০০০, বাকির দিন ০: বয়স ২×১০ + অর্ধেক-সীমা ২০ + শর্তের বাইরে ১৫ = ৫৫ → বেশি, তিন কারণ।
 *   · তার উপর দুইটা ফেরত চেক (+৫০) → চরম; ১৮০ দিনের আগের ফেরত গোনা হয় না। · দেয়ালে তিনবার থামা → +১৫।
 *   · সেটিংসে একটা লক্ষণের নম্বর ০ করলে সেই কারণ আর আসে না; সীমা বদলালে মাত্রার সীমাও বদলায়।
 *   · মাত্রা সীমা বা দেয়াল বদলায় না। · গ্রাহকের বাকির পাতায় মাত্রা আর কারণ দেখায়।
 */
final class TheRiskRatingShowsItsReasonsTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_no_signs_is_low_with_no_reasons(): void
    {
        $c = $this->customer('RISK-0', '10000');
        $this->owes($c, '1000');

        $this->assertSame(['level' => CreditRisk::LOW, 'score' => 0, 'reasons' => []], app(CreditRisk::class)->rate($c->fresh()));
    }

    public function test_each_rule_adds_its_points_and_says_why(): void
    {
        $c = $this->customer('RISK-1', '10000');
        $this->bill($c, 'INV-RISK1', '6000', daysPastDue: 45);

        $r = app(CreditRisk::class)->rate($c->fresh());
        $this->assertSame(55, $r['score']);
        $this->assertSame(CreditRisk::HIGH, $r['level']);
        $this->assertCount(3, $r['reasons']);
        $this->assertStringContainsString('45', $r['reasons'][0]);

        $this->bouncedCheque($c, '2000');
        $this->bouncedCheque($c, '3000', daysAgo: 30);
        $this->bouncedCheque($c, '9000', daysAgo: 200); // ⓘ ১৮০ দিনের আগের — গোনা নয়
        $r = app(CreditRisk::class)->rate($c->fresh());
        $this->assertSame(105, $r['score']);
        $this->assertSame(CreditRisk::CRITICAL, $r['level']);

        foreach ([1, 2, 3] as $d) {
            $this->wallSaid($c, 'stop', daysAgo: $d);
        }
        $this->wallSaid($c, 'pass');
        $this->assertSame(120, app(CreditRisk::class)->rate($c->fresh())['score'], 'দেয়ালে তিনবার থামা = ১৫; "গেল" গোনা নয়।');
    }

    /**
     * ⛔ নিজের শর্তই শর্ত — ৭ দিন কেবল বাকির দিন ০ হলে (বাকি নিয়ন্ত্রণের লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬)। ⚠️ আগে `max(7, …)`:
     * ৩ দিনের শর্তের গ্রাহক মেয়াদের ৫ দিন পরেও "শর্তের বাইরে" ধরা পড়তেন না।
     */
    public function test_a_short_term_customer_is_late_beyond_his_own_days_not_seven(): void
    {
        $c = $this->customer('RISK-T', '100000');
        $c->forceFill(['credit_days' => 3])->save();
        $this->bill($c, 'INV-RISKT', '1000', daysPastDue: 5);

        $reasons = implode(' | ', app(CreditRisk::class)->rate($c->fresh())['reasons']);
        $this->assertStringContainsString(__('credit_control::risk.reason_late_beyond_terms', ['days' => 5, 'terms' => 3]), $reasons,
            '⛔ ৩ দিনের শর্তের গ্রাহক ৫ দিন দেরিতেও "শর্তের বাইরে" নন।');
    }

    public function test_the_rules_are_the_companys_own(): void
    {
        $c = $this->customer('RISK-2', '10000');
        $this->bill($c, 'INV-RISK2', '6000', daysPastDue: 45);

        $settings = app(SettingsService::class);
        $settings->set('credit_control.risk_points_overdue_age', 0);
        $r = app(CreditRisk::class)->rate($c->fresh());
        $this->assertSame(35, $r['score']);
        $this->assertCount(2, $r['reasons'], 'বন্ধ করা লক্ষণের কারণ তবু দেখাল।');

        $settings->set('credit_control.risk_high_at', 30);
        $this->assertSame(CreditRisk::HIGH, app(CreditRisk::class)->rate($c->fresh())['level']);
    }

    public function test_the_rating_only_informs_and_shows_on_the_profile(): void
    {
        $c = $this->customer('RISK-3', '10000');
        $this->bill($c, 'INV-RISK3', '6000', daysPastDue: 45);
        $this->bouncedCheque($c, '2000');
        app(SettingsService::class)->set('customer.overdue_stop', false);

        $before = app(CreditExposure::class)->check($c->fresh(), '1000');
        app(CreditRisk::class)->rate($c->fresh());
        $this->assertSame($before, app(CreditExposure::class)->check($c->fresh(), '1000'), '⛔ মাত্রা দেয়ালের উত্তর বদলেছে।');
        $this->assertSame(0, bccomp((string) $c->fresh()->credit_limit, '10000', 4));

        $this->get(route('credit_control.customer.show', $c->id))->assertOk()
            ->assertSee('data-risk-level="critical"', false)
            ->assertSee(__('credit_control::risk.reason_bounced_cheques', ['n' => 1]));
    }
}
