<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use App\Modules\Finance\Reports\InsuranceReports;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ প্রিমিয়ামের সূচি — অর্থ-মডিউলের পরিকল্পনা ৬.২, ৬ অক্টোবর ২০২৬ (সমন্বয়কের উত্তর প্র১: কিস্তির ধরন, ডিফল্ট বছরে)।
 *
 * ⭐ দাবি:
 *   · তিন মাসে / মাসে — মেয়াদটা সেই মাপে ভাগ, দিন মাসে মাসে, যোগফল হুবহু প্রিমিয়াম (শেষ কিস্তি পয়সার বাকি নেয়)
 *   · বছরে — পুরো মেয়াদ একটা সারি, যেমন ছিল
 *   · বদলালে: একটাও দেওয়া না হলে নতুন ধরনে আবার ভাগ; দেওয়া হয়ে গেলে কিছুই ছোঁয়া হয় না
 *   · রিপোর্ট — দেওয়া / দিন পার / সামনে, অবস্থা ধরে ছাঁকা, বাকির কলাম
 */
final class APremiumIsPaidInPartsTest extends TestCase
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

    public function test_a_quarterly_premium_is_four_parts_three_months_apart_adding_up_to_the_premium(): void
    {
        $policy = $this->policy('Q-1', '10000', InsurancePolicy::QUARTERLY, '2026-07-01', '2027-06-30');
        $rows = $this->rows($policy);

        $this->assertSame(['2026-07-01', '2026-10-01', '2027-01-01', '2027-04-01'], array_column($rows, 'from'));
        $this->assertSame(['2026-09-30', '2026-12-31', '2027-03-31', '2027-06-30'], array_column($rows, 'to'));
        $this->assertSame(['2500.00', '2500.00', '2500.00', '2500.00'], array_column($rows, 'amount'));
    }

    public function test_a_monthly_premium_puts_the_leftover_paisa_on_the_last_part(): void
    {
        $rows = $this->rows($this->policy('M-1', '1000', InsurancePolicy::MONTHLY, '2026-07-01', '2027-06-30'));

        $this->assertCount(12, $rows);
        $this->assertSame('83.33', $rows[0]['amount']);
        $this->assertSame('83.37', $rows[11]['amount'], 'শেষ কিস্তি পয়সার বাকিটা নেয়নি।');
        $this->assertSame('1000.00', array_reduce($rows, fn (string $s, array $r) => bcadd($s, $r['amount'], 2), '0'));
    }

    public function test_a_yearly_premium_stays_one_row_for_the_term(): void
    {
        $rows = $this->rows($this->policy('Y-1', '4800', InsurancePolicy::YEARLY, '2026-07-15', '2027-07-14'));

        $this->assertSame([['from' => '2026-07-15', 'to' => '2027-07-14', 'amount' => '4800.00']], $rows);

        // ⓘ দুই বছরের মেয়াদেও "বছরে" মানে পুরো মেয়াদ একটা সারি — যেমন ছিল
        $long = $this->rows($this->policy('Y-2', '9000', InsurancePolicy::YEARLY, '2026-07-01', '2028-06-30'));
        $this->assertSame([['from' => '2026-07-01', 'to' => '2028-06-30', 'amount' => '9000.00']], $long);
    }

    public function test_an_edit_splits_again_only_while_nothing_is_paid(): void
    {
        $policy = $this->policy('E-1', '6000', InsurancePolicy::YEARLY, '2026-07-01', '2027-06-30');

        $this->insurance->update($policy, $this->form($policy, ['frequency' => InsurancePolicy::HALF_YEARLY]));
        $this->assertSame(['3000.00', '3000.00'], array_column($this->rows($policy), 'amount'), '⛔ কিস্তির ধরন বদলেও ভাগ হলো না।');

        // ⓘ প্রথম কিস্তি দেওয়া — এরপর বদলালে কিছুই ছোঁয়া হয় না
        InsurancePremium::query()->where('policy_id', $policy->id)->orderBy('period_from')->first()
            ->forceFill(['status' => InsurancePremium::POSTED, 'posted_at' => now()])->save();
        $this->insurance->update($policy->fresh(), $this->form($policy->fresh(), ['frequency' => InsurancePolicy::MONTHLY, 'premium' => '9000']));

        $this->assertSame(['3000.00', '3000.00'], array_column($this->rows($policy), 'amount'), '⛔ দেওয়া হয়ে যাওয়া মেয়াদ আবার ভাগ হলো।');
    }

    public function test_the_schedule_report_says_paid_overdue_and_coming_and_filters_by_state(): void
    {
        $policy = $this->policy('R-1', '4000', InsurancePolicy::QUARTERLY, now()->subMonths(5)->toDateString(), now()->addMonths(7)->subDay()->toDateString());
        InsurancePremium::query()->where('policy_id', $policy->id)->orderBy('period_from')->first()
            ->forceFill(['status' => InsurancePremium::POSTED, 'posted_at' => now()])->save();

        $run = fn (array $f = []) => collect(app(ReportEngine::class)->run(InsuranceReports::PREMIUMS,
            ['from' => now()->subYear()->toDateString(), 'to' => now()->addYear()->toDateString(), ...$f], perPage: 100)->rows)
            ->where('policy_no', 'R-1')->values();

        $all = $run();
        $this->assertSame(
            [__('finance::insurance.premium_state_paid'), __('finance::insurance.premium_state_overdue'),
                __('finance::insurance.premium_state_upcoming'), __('finance::insurance.premium_state_upcoming')],
            $all->pluck('state_label')->all(),
        );
        $this->assertSame(0, bccomp((string) $all[0]['unpaid'], '0', 2), 'দেওয়া কিস্তি বাকিতে উঠল।');
        $this->assertSame(0, bccomp((string) $all[1]['unpaid'], '1000', 2));

        $this->assertCount(1, $run(['state' => 'overdue']), '⛔ "দিন পার" ছাঁকনিতে অন্য অবস্থা এল।');

        $this->get(route('finance.report.show', ['slug' => 'insurance-premiums']))->assertOk()->assertSee('data-premium-state', false);
        $this->get(route('finance.insurance.index'))->assertOk()->assertSee('data-premium-schedule', false);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    /** @return list<array{from: string, to: string, amount: string}> */
    private function rows(InsurancePolicy $policy): array
    {
        return InsurancePremium::query()->where('policy_id', $policy->id)->orderBy('period_from')->get()
            ->map(fn (InsurancePremium $p) => [
                'from' => $p->period_from->toDateString(), 'to' => $p->period_to->toDateString(), 'amount' => bcadd((string) $p->amount, '0', 2),
            ])->all();
    }

    private function policy(string $no, string $premium, string $frequency, string $from, string $to): InsurancePolicy
    {
        $insurer = Institution::query()->create([
            'company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer '.$no,
        ]);

        return $this->insurance->create([
            'institution_id' => $insurer->id, 'policy_no' => $no, 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '500000', 'premium' => $premium, 'frequency' => $frequency, 'starts_on' => $from, 'ends_on' => $to,
        ]);
    }

    /** @return array<string, mixed> */
    private function form(InsurancePolicy $policy, array $over): array
    {
        return [
            'institution_id' => $policy->institution_id, 'policy_no' => $policy->policy_no, 'covers' => $policy->covers,
            'subject' => $policy->subject, 'sum_insured' => (string) $policy->sum_insured, 'premium' => (string) $policy->premium,
            'frequency' => $policy->frequency, 'starts_on' => $policy->starts_on->toDateString(), 'ends_on' => $policy->ends_on->toDateString(),
            ...$over,
        ];
    }
}
