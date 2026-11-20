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
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Services\FinanceCharts;
use App\Modules\Finance\Services\WorkingCapital;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ লাইভ 1.3.7 (২১ নভেম্বর ২০২৬): অর্থের ড্যাশবোর্ডে "আদায়ে গড়ে -2 দিন (DSO) · গত মাস -8" আর নগদ চক্রের সারিতে "+-2 দিন"।
 * কারণ: পাওনার খাতা (1110) উল্টো জেরে — গ্রাহকের অগ্রিম বাকির চেয়ে বেশি — আর DSO = জের × 90 ÷ 90 দিনের বিক্রি সরাসরি ঋণাত্মক হত।
 * ⭐ DSO মানে "বিক্রির টাকা ঘরে আসতে কত দিন"; উল্টো জের মানে আগেই আদায় — 0 দিন। [[FinanceCharts::cycle()]]
 *
 * বিপজ্জনক উপাত্ত: বিক্রি আছে (মাপ হয়), কিন্তু পাওনার খাতায় অগ্রিম এত বেশি যে জের উল্টো। সুইচ (`abos.dashboards_v2`) চালু আর বন্ধ দুই অবস্থায় পাতা।
 */
final class TheCashCycleNeverShowsNegativeDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_receivable_ledger_in_credit_gives_zero_days_not_minus(): void
    {
        $this->advanceBiggerThanAllDues();

        $wc = app(WorkingCapital::class)->on(Carbon::today());
        $this->assertSame(-1, bccomp($wc['receivable'], '0', 4), 'দাবির ভিত্তি নেই — পাওনার খাতা উল্টো জেরে নয়।');

        $p = app(FinanceCharts::class)->position();

        $this->assertNotNull($p['cycle']['dso'], 'দাবির ভিত্তি নেই — 90 দিনে বিক্রি নেই, DSO মাপাই যায় না।');
        $this->assertSame(0, $p['cycle']['dso'], '⛔ উল্টো জেরে DSO ঋণাত্মক — "আদায়ে গড়ে -2 দিন" ফিরে এল।');

        foreach (array_merge($p['dso'], $p['dpo'], [$p['dso_before'], $p['cycle']['dio'], $p['cycle']['dpo']]) as $days) {
            $this->assertTrue($days === null || $days >= 0, '⛔ কোনো দিনের মাপ ঋণাত্মক: '.var_export($days, true));
        }

        if ($p['cycle']['ccc'] !== null) {
            $this->assertSame($p['cycle']['dio'] - $p['cycle']['dpo'], $p['cycle']['ccc'], '⛔ নগদ চক্র = DSO (0) + DIO − DPO নয়।');
        }
    }

    public function test_the_page_never_prints_plus_minus_days_with_the_new_dashboards_on(): void
    {
        $this->pageHasNoMinusDays(true);
    }

    public function test_the_page_never_prints_plus_minus_days_with_the_new_dashboards_off(): void
    {
        $this->pageHasNoMinusDays(false);
    }

    private function pageHasNoMinusDays(bool $v2): void
    {
        config(['abos.dashboards_v2' => $v2]);
        $this->advanceBiggerThanAllDues();

        $html = (string) $this->get(route('module.dashboard', ['module' => 'finance']))->assertOk()->getContent();

        $this->assertStringContainsString('data-fin-cycle', $html, 'দাবির ভিত্তি নেই — নগদ চক্রের বক্স পাতায় নেই।');
        $cycle = substr($html, (int) strpos($html, 'data-fin-cycle'));
        $cycle = substr($cycle, 0, (int) strpos($cycle, '</section>'));
        $this->assertDoesNotMatchRegularExpression('/[+−]-\d/u', $cycle, '⛔ নগদ চক্রের সারিতে "+-N দিন" ফিরে এল।');
        $lead = explode(':n', (string) trans('finance::board.pos.dso'))[0];
        $this->assertDoesNotMatchRegularExpression('/'.preg_quote($lead, '/').'[-−]\d/u', $html,
            '⛔ "আদায়ে গড়ে -N দিন (DSO)" ফিরে এল।');
    }

    /** ⓘ 90 দিনের ভিতরে নগদ বিক্রি (মাপ হয়), আর এক দোকানের অগ্রিম — সব বাকির চেয়ে বেশি, তাই 1110-এর জের উল্টো */
    private function advanceBiggerThanAllDues(): void
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $this->journal('2026-11-10', [
            ['account_id' => $till->id, 'debit' => '200000'],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => '200000'],
        ]);

        $balance = (string) app(WorkingCapital::class)->on(Carbon::today())['receivable'];
        $advance = bcadd(bccomp($balance, '0', 4) > 0 ? $balance : '0', '500000', 4);
        $shop = Customer::query()->orderBy('id')->firstOrFail();

        $this->journal('2026-11-12', [
            ['account_id' => $till->id, 'debit' => $advance],
            ['account_id' => $receivable->id, 'credit' => $advance, 'party_type' => Customer::drillSourceType(), 'party_id' => $shop->id],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function journal(string $date, array $lines): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'নগদ চক্রের দাবি'],
            $lines,
        );
        app(VoucherService::class)->post($voucher);
        app(\App\Core\Services\LedgerBalances::class)->forget();
    }
}
