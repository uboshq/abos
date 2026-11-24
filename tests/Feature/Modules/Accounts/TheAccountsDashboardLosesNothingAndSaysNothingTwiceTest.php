<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsBoard;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ হিসাবের ড্যাশবোর্ড — ড্যাশবোর্ড যাচাই, ২৩ নভেম্বর ২০২৬ (মালিকের অনুমোদিত নমুনা RoPEayT7 "acc"; দফা ২ ও ৪)।
 *
 * দাবি:
 *   · কিছু হারায় না — নমুনার নিচের তালিকা (চলতি অবস্থান, সবচেয়ে বেশি বকেয়া যাদের, আমরা যাদের সবচেয়ে বেশি দেব, খসড়া পড়ে আছে,
 *     হস্তান্তর গ্রহণের অপেক্ষায়, শূন্যের নিচে ড্রয়ার, বকেয়া ঋণ) আর সম্পদের মূল্য পাতায় আঁকা হয় — গ্রাফের বোর্ডের পরে, সমস্যা
 *     কেন্দ্রের আগে; সমস্যা কেন্দ্র সবার শেষে। ⛔ আগে `board: true` ছিল, তাই কেবল সারিগুলো আঁকা হত, তালিকা নয়।
 *   · একই অঙ্ক দুইবার নয় — এ মাসের নিট লাভ কেবল গ্রাফের প্রথম কার্ডে (এক নজরের সারি আর লাভ-ক্ষতির সেতুর নিট দণ্ড আর নেই);
 *     অনুমোদনের অপেক্ষা, অনুমোদিত-পোস্ট-হয়নি, ব্যাংকে না-মেলা এক নজরে আর নেই (গ্রাফ আর সমস্যা কেন্দ্রে আছে); নগদ + ব্যাংকের জের
 *     কেবল গ্রাফে, এক নজরে কেবল তার বদল; আয় বনাম খরচের নিচের সারিতে গ্রাফের ছয় মাসের কোনোটা নেই; "খাতার স্বাস্থ্য" নামটা একবারই।
 */
final class TheAccountsDashboardLosesNothingAndSaysNothingTwiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        config(['abos.dashboards_v2' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    /** ⭐ নমুনার নিচের তালিকা আর সম্পদের মূল্য — গ্রাফের পরে, সমস্যা কেন্দ্রের আগে; সমস্যা কেন্দ্র শেষে */
    public function test_the_page_draws_the_samples_lists_below_the_charts_and_the_problem_centre_last(): void
    {
        $html = $this->page();
        $board = strpos($html, 'data-viz-board');
        $problems = strpos($html, 'data-acc-problems');
        $this->assertNotFalse($board, '⛔ পাতায় গ্রাফের বোর্ড নেই।');
        $this->assertNotFalse($problems, '⛔ পাতায় সমস্যা কেন্দ্র নেই।');

        foreach (['current_position', 'top_due', 'top_owed', 'needs_finishing', 'transfers_waiting', 'tills_below_zero', 'outstanding_loan', 'asset_value'] as $key) {
            $label = e(__('accounts::dashboard.'.$key));
            $at = strpos($html, $label, (int) $board);
            $this->assertNotFalse($at, '⛔ "'.$label.'" পাতায় নেই, অথবা গ্রাফের বোর্ডের আগে — নমুনার নিচের সারি হারিয়েছে।');
            $this->assertLessThan($problems, $at, '⛔ "'.$label.'" সমস্যা কেন্দ্রের পরে — সমস্যা কেন্দ্র সবার শেষে থাকার কথা।');
        }

        // ⓘ সমস্যা কেন্দ্রের পরে আর কোনো অংশ নয় — ১০ নভেম্বরের সব সারি তার আগে
        foreach (['data-acc-pnl', 'data-acc-glance', 'data-acc-timeline', 'data-acc-flow', 'data-acc-funnel', 'data-acc-bubbles'] as $mark) {
            $this->assertLessThan($problems, (int) strpos($html, $mark), '⛔ '.$mark.' সমস্যা কেন্দ্রের পরে।');
        }

        $this->assertStringNotContainsString('data-listing', substr($html, (int) $problems), '⛔ সমস্যা কেন্দ্রের পরে তালিকা।');
    }

    /** ⭐ এ মাসের নিট লাভ একবারই — গ্রাফের প্রথম কার্ডে; এক নজরের সারি নেই, সেতুতে নিট দণ্ড নেই */
    public function test_this_months_net_profit_is_shown_once(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, e(__('accounts::dashboard.net_profit_month'))),
            '⛔ "এ মাসের নিট লাভ" পাতায় একবার নয় — গ্রাফ, এক নজর আর সেতু, একই অঙ্ক কয়েকবার।');
        $this->assertStringNotContainsString('data-glance="profit"', $html, '⛔ এক নজরে এখনো নিট লাভের সারি।');
        $this->assertSame(6, substr_count($html, 'data-pnl-step'), '⛔ লাভ-ক্ষতির সেতুতে এখনো নিট লাভের দণ্ড — অঙ্কটা গ্রাফের প্রথম কার্ডে।');
        $this->assertArrayHasKey('last_net', app(AccountsBoard::class)->pnl(), '⛔ সেতুর মাথায় গত মাসের তুলনা নেই।');
    }

    /** ⭐ এক নজরে কেবল যা আর কোথাও নেই — আজকের ভাউচার আর নগদ + ব্যাংকের বদল */
    public function test_the_glance_row_holds_only_what_is_nowhere_else(): void
    {
        $this->assertSame(['today', 'money'], array_column(app(AccountsBoard::class)->glance(), 'key'),
            '⛔ এক নজরে এমন সারি, যার অঙ্ক গ্রাফে বা সমস্যা কেন্দ্রে আগেই আছে।');

        $html = $this->page();

        foreach (['awaiting', 'signed', 'bank', 'profit'] as $gone) {
            $this->assertStringNotContainsString('data-glance="'.$gone.'"', $html, '⛔ এক নজরে এখনো "'.$gone.'" — অঙ্কটা দুই জায়গায়।');
        }
    }

    /** ⭐ আয় বনাম খরচের নিচের সারি গ্রাফের ছয় মাসের আগের ছয় মাস — কোনো মাস দুইবার নয়, বারো মাস তবু দেখা যায় */
    public function test_the_income_row_below_shows_only_the_months_the_chart_does_not(): void
    {
        $chart = [];
        for ($i = 5; $i >= 0; $i--) {
            $chart[] = (int) Carbon::today()->startOfMonth()->subMonthsNoOverflow($i)->month;
        }

        $below = array_column(app(AccountsBoard::class)->incomeVsExpense(), 'month');

        $this->assertCount(6, $below, '⛔ নিচের সারিতে ছয় মাস নয়।');
        $this->assertSame([], array_values(array_intersect($below, $chart)), '⛔ নিচের সারিতে গ্রাফের মাস — একই মাস দুইবার।');
        $this->assertSame([12, 1, 2, 3, 4, 5], $below, '⛔ 20 নভেম্বর 2026-এ নিচের সারি ডিসেম্বর 2025 থেকে মে 2026 নয় (গ্রাফ জুন–নভেম্বর)।');
    }

    /** ⭐ "খাতার স্বাস্থ্য" নামে একটাই ঘর — গ্রাফের কার্ড; নিচের রাডারের নিজের নাম */
    public function test_the_books_health_title_is_used_once(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, '>'.e(__('accounts::dashboard.viz_health')).'<'), '⛔ "খাতার স্বাস্থ্য" শিরোনাম পাতায় একবার নয়।');
        $this->assertNotSame(__('accounts::dashboard.viz_health'), __('accounts::board.r7.health'));
    }

    private function page(): string
    {
        return (string) $this->get(route('module.dashboard', ['module' => 'accounts']))->assertOk()->getContent();
    }
}
