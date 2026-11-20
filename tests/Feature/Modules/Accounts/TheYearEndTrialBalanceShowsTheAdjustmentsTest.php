<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\YearEndTrialBalance;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ বছর-শেষের রেওয়ামিল — সমন্বয়ের আগে + সমন্বয় = পরে, আর আগের বছর-শেষের ঘর (মালিক, ১৭ নভেম্বর ২০২৬; [[YearEndTrialBalance]])।
 *
 *   · সমন্বয় জাবেদা (`is_adjusting`) কেবল সমন্বয়ের ঘরে, সাধারণ জাবেদা "আগে"-তে; প্রতিটা খাতে আগে + সমন্বয় = পরে।
 *   · চার জোড়াই মেলে; জাবেদার নম্বর খাতের "নং" ঘরে আর নিচের তালিকায়, অঙ্কসহ।
 *   · আগের বছর-শেষের ঘরে কেবল আগের বছরের শেষ দিন পর্যন্ত।
 *   · পাতা খোলে (অর্থবছর বাছা যায়), আর বাংলা ও ইংরেজি কাগজ বেরোয়।
 *
 * ⓘ চালানো হয়নি — এই পিসিতে পরীক্ষা নিষেধ (মালিক); ম্যাকে চলবে।
 */
final class TheYearEndTrialBalanceShowsTheAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    private FinancialYear $year;

    private FinancialYear $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->year = FinancialYear::forDate(now()) ?? FinancialYear::create([
            'name' => now()->year.'-'.(now()->year + 1), 'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(), 'is_current' => true,
        ]);

        $start = Carbon::parse($this->year->starts_on);
        $this->previous = YearEndTrialBalance::previousOf($this->year) ?? FinancialYear::create([
            'name' => 'PREV-'.$start->year, 'starts_on' => $start->copy()->subYear()->toDateString(),
            'ends_on' => $start->copy()->subDay()->toDateString(), 'is_current' => false,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * ⓘ পোস্ট হওয়া জাবেদা — `create()` কেবল খসড়া লেখে, খাতায় কিছু বসে না; রেওয়ামিল পড়ে খাতা আর কেবল পোস্ট হওয়া সমন্বয় জাবেদা
     *   (Mac run of e88be43a3: খসড়াগুলো কোনো ঘরেই আসেনি — "সমন্বয়ের ঘরে আসেনি")।
     */
    private function journal(string $date, string $debitCode, string $creditCode, string $amount, bool $adjusting): Voucher
    {
        $service = app(VoucherService::class);

        return $service->post($service->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'YTB '.$amount, 'is_adjusting' => $adjusting],
            [
                ['account_id' => StandardChart::find($debitCode)->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => StandardChart::find($creditCode)->id, 'debit' => '0', 'credit' => $amount],
            ],
        ));
    }

    /** @return array<string, string> খাতের নিট (ডেবিট − ক্রেডিট) — আগে, সমন্বয়, পরে, আগের বছর */
    private function netsOf(array $ytb, string $code): array
    {
        $row = collect($ytb['groups'])->flatMap(fn (array $g) => $g['rows'])->firstWhere('code', $code);
        $v = $row['values'] ?? array_fill_keys(YearEndTrialBalance::CELLS, '0');

        $out = [];
        foreach (YearEndTrialBalance::PAIRS as $pair) {
            $out[$pair] = bcsub((string) $v[$pair.'_debit'], (string) $v[$pair.'_credit'], 4);
        }

        return $out + ['refs' => $row['refs'] ?? []];
    }

    public function test_before_plus_adjustments_is_after_and_the_previous_year_end_is_right(): void
    {
        $service = app(YearEndTrialBalance::class);
        $baseline = $service->build($this->year);
        $was = $this->netsOf($baseline, StandardChart::HAMMALI);

        $inYear = Carbon::parse($this->year->starts_on)->addDays(10)->toDateString();
        $lastYear = Carbon::parse($this->previous->ends_on)->subDays(10)->toDateString();

        $this->journal($lastYear, StandardChart::HAMMALI, StandardChart::OWNER_CAPITAL, '500', adjusting: false);
        $this->journal($inYear, StandardChart::HAMMALI, StandardChart::OWNER_CAPITAL, '1000', adjusting: false);
        $adjusting = $this->journal($inYear, StandardChart::HAMMALI, StandardChart::RENT_INCOME, '300', adjusting: true);

        $ytb = $service->build($this->year->fresh());
        $now = $this->netsOf($ytb, StandardChart::HAMMALI);

        $this->assertSame(0, bccomp(bcsub($now['adj'], $was['adj'], 4), '300', 4), '⛔ সমন্বয় জাবেদা সমন্বয়ের ঘরে আসেনি।');
        $this->assertSame(0, bccomp(bcsub($now['before'], $was['before'], 4), '1500', 4), '⛔ সাধারণ জাবেদা (এই আর আগের বছরের) "আগে"-তে নেই, বা সমন্বয় সেখানে ঢুকল।');
        $this->assertSame(0, bccomp(bcsub($now['after'], $was['after'], 4), '1800', 4), '⛔ সমন্বয়ের পরের ঘর ভুল।');
        $this->assertSame(0, bccomp(bcsub($now['prev'], $was['prev'], 4), '500', 4), '⛔ আগের বছর-শেষে এই বছরের অঙ্ক ঢুকল, বা আগের বছরেরটা নেই।');

        // ⭐ প্রতিটা খাতে আগে + সমন্বয় = পরে
        foreach (collect($ytb['groups'])->flatMap(fn (array $g) => $g['rows']) as $row) {
            $v = $row['values'];
            $before = bcsub($v['before_debit'], $v['before_credit'], 4);
            $adj = bcsub($v['adj_debit'], $v['adj_credit'], 4);
            $after = bcsub($v['after_debit'], $v['after_credit'], 4);
            $this->assertSame(0, bccomp(bcadd($before, $adj, 4), $after, 4), "⛔ {$row['code']}: আগে + সমন্বয় ≠ পরে।");
        }

        // ⭐ চার জোড়াই মেলে, আর মোটে সমন্বয়ের ডেবিট = ক্রেডিট
        $this->assertTrue($ytb['agrees'], '⛔ বছর-শেষের রেওয়ামিল মেলেনি: '.json_encode($ytb['gaps']));
        $this->assertSame(0, bccomp($ytb['total']['adj_debit'], $ytb['total']['adj_credit'], 4));

        // ⭐ নম্বর — খাতের ঘরে আর তালিকায়, অঙ্কসহ
        $entry = collect($ytb['adjusting'])->firstWhere('id', $adjusting->id);
        $this->assertNotNull($entry, '⛔ সমন্বয় জাবেদা তালিকায় নেই।');
        $this->assertSame(0, bccomp($entry['amount'], '300', 4));
        $this->assertContains($entry['no'], $now['refs'], '⛔ খাতের "নং" ঘরে জাবেদার নম্বর নেই।');
        $this->assertSame(range(1, count($ytb['adjusting'])), array_column($ytb['adjusting'], 'no'), '⛔ নম্বর ১ থেকে ধারাবাহিক নয়।');
    }

    public function test_the_page_opens_for_a_chosen_year_and_prints_in_both_languages(): void
    {
        $this->get(route('accounts.year_end_trial_balance', ['year_id' => $this->year->id]))
            ->assertOk()
            ->assertSee(__('accounts::fs.ytb.before'))
            ->assertSee(__('accounts::fs.ytb.adjustments'))
            ->assertSee('data-ytb-agrees', false);

        $this->get(route('accounts.year_end_trial_balance', ['year_id' => $this->previous->id]))->assertOk();

        foreach (['bn', 'en'] as $lang) {
            $paper = $this->get(route('accounts.year_end_trial_balance', ['year_id' => $this->year->id, 'export' => 'pdf', 'lang' => $lang]));
            $paper->assertOk();
            $this->assertSame('application/pdf', $paper->headers->get('Content-Type'));
            $this->assertStringContainsString('-'.$lang.'.pdf', (string) $paper->headers->get('Content-Disposition'));
        }
    }

    public function test_someone_without_the_final_accounts_key_is_refused(): void
    {
        $clerk = User::factory()->create(['current_company_id' => CompanyContext::id(), 'is_active' => true]);
        $clerk->companies()->attach(CompanyContext::id(), ['is_active' => true]);

        $this->actingAs($clerk)->get(route('accounts.year_end_trial_balance'))->assertForbidden();
    }
}
