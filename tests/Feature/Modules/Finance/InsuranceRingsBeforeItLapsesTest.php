<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Dashboard\FinanceDashboard;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\DueNotices;
use App\Modules\Finance\Services\InsuranceDues;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ বীমার সতর্কতা — অর্থ-মডিউলের পরিকল্পনা ৬.৫, ৬ অক্টোবর ২০২৬ ([[InsuranceDues]])।
 *
 * ⭐ দাবি:
 *   · মেয়াদ শেষের ৩০ দিনের ভিতরের (বা পেরোনো) পলিসি নবায়নের ঘণ্টা পায়; দূরেরটা পায় না; বন্ধ পলিসি পায় না
 *   · বাকি প্রিমিয়াম — দিন পার বা সামনের সাত দিনে — ঘণ্টা পায়; দেওয়া প্রিমিয়াম পায় না
 *   · একই সপ্তাহে একই খবর দুবার নয়
 *   · ড্যাশবোর্ডের ঘর = একই হিসাব: বাকি প্রিমিয়ামের মোট, নবায়নের সংখ্যা
 */
final class InsuranceRingsBeforeItLapsesTest extends TestCase
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

    public function test_renewals_and_unpaid_premiums_are_found_and_the_far_ones_are_not(): void
    {
        $insurer = $this->insurer();
        // ⓘ মেয়াদ ২০ দিনে শেষ, প্রিমিয়াম বাকি (শুরু এক বছর আগে — দিন পার)
        $soon = $this->policy($insurer, 'P-SOON', now()->addDays(20));
        // ⓘ মেয়াদ ছয় মাস পরে, শুরু আজ থেকে ৫ দিন পরে — প্রিমিয়াম সামনের সাত দিনে
        $later = $this->policy($insurer, 'P-LATER', now()->addDays(5)->addYear()->subDay(), '2000', now()->addDays(5));
        // ⓘ মেয়াদ অনেক দূরে, প্রিমিয়াম মাস খানেক পরে — কিছুই নয়
        $far = $this->policy($insurer, 'P-FAR', now()->addDays(40)->addYear()->subDay(), '3000', now()->addDays(40));
        // ⓘ বন্ধ পলিসি — মেয়াদ কাছে হলেও নয়
        $off = $this->policy($insurer, 'P-OFF', now()->addDays(10));
        $this->insurance->setActive($off, false);

        $dues = app(InsuranceDues::class);

        $this->assertSame(['P-SOON'], $dues->renewals()->pluck('policy_no')->all(), '⛔ নবায়নের তালিকা ভুল।');
        $this->assertSame(['P-SOON', 'P-LATER'], $dues->premiumsDue()->map(fn ($p) => $p->policy->policy_no)->all(),
            '⛔ বাকি প্রিমিয়ামের তালিকা ভুল — দূরেরটা এল, বা কাছেরটা বাদ পড়ল।');
        $this->assertNotNull($far);
        $this->assertNotNull($later);
        $this->assertNotNull($soon);
    }

    public function test_the_bell_rings_once_a_week_and_a_paid_premium_is_quiet(): void
    {
        $insurer = $this->insurer();
        $soon = $this->policy($insurer, 'P-BELL', now()->addDays(15));

        $this->assertGreaterThan(0, $this->fresh(DueNotices::INSURANCE_RENEWAL_DUE), '⛔ নবায়নের ঘণ্টা বাজেনি।');
        $this->assertTrue(Notification::query()->where('type', DueNotices::INSURANCE_PREMIUM_DUE)->exists(), '⛔ প্রিমিয়ামের ঘণ্টা বাজেনি।');
        $this->assertSame(0, $this->fresh(DueNotices::INSURANCE_RENEWAL_DUE), '⛔ একই সপ্তাহে আবার বাজল।');

        // ⓘ প্রিমিয়াম দেওয়া হলো — আর তাগাদা নয়
        $soon->premiums()->update(['status' => 'posted', 'posted_at' => now()]);
        $this->assertSame([], app(InsuranceDues::class)->premiumsDue()->all(), '⛔ দেওয়া প্রিমিয়ামের তাগাদা গেল।');
    }

    public function test_the_dashboard_says_what_the_bell_says(): void
    {
        config(['abos.dashboards_v2' => true]);
        $insurer = $this->insurer();
        $this->policy($insurer, 'P-D1', now()->addDays(25), '1500');
        $this->policy($insurer, 'P-D2', now()->addDays(3)->addYear()->subDay(), '2500', now()->addDays(3));

        $stats = (new \ReflectionMethod(FinanceDashboard::class, 'insuranceDue'))->invoke(null);

        $this->assertSame(Money::format('4000'), $stats[0]->value, '⛔ ড্যাশবোর্ড আর ঘণ্টা দুই অঙ্ক বলে।');
        $this->assertStringContainsString('1', $stats[0]->hint);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    private function fresh(string $type): int
    {
        $was = (int) Notification::query()->max('id');
        $owner = auth()->user();
        auth()->logout();
        $this->artisan('abos:money-due')->assertSuccessful();
        $this->actingAs($owner);

        return Notification::query()->where('id', '>', $was)->where('type', $type)->count();
    }

    private function insurer(): Institution
    {
        return Institution::query()->create([
            'company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE,
            'name_en' => 'Pragati Insurance '.fake()->unique()->numberBetween(1, 9999),
        ]);
    }

    private function policy(Institution $insurer, string $no, $ends, string $premium = '1000', $starts = null): InsurancePolicy
    {
        return $this->insurance->create([
            'institution_id' => $insurer->id, 'policy_no' => $no, 'covers' => InsurancePolicy::WAREHOUSE,
            'subject' => 'Godown', 'sum_insured' => '1000000', 'premium' => $premium,
            'starts_on' => ($starts ?? $ends->copy()->subYear()->addDay())->toDateString(),
            'ends_on' => $ends->toDateString(),
        ]);
    }
}
