<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Reports\MonthlyCashReport;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লাভ-ক্ষতি খুললে কেবল এই মাস — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬; ছবিতে স্থিতিপত্রে "চলতি বছরের লাভ ৩৬৫", লাভ-ক্ষতিতে নিট ০)।
 *
 * ⭐ তারিখ না দিলে লাভ-ক্ষতি চলতি অর্থবছরের শুরু থেকে আজ পর্যন্ত — স্থিতিপত্রের একই বছর ([[MonthlyCashReport::yearStart()]])।
 */
final class TheProfitAndLossShowedOnlyThisMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profit_and_loss_opens_on_the_financial_year(): void
    {
        $this->travelTo('2026-10-10 11:00:00');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $yearStart = MonthlyCashReport::yearStart();
        $earlier = '2026-08-15';   // ⓘ এই বছরের, কিন্তু এই মাসের আগে
        $this->assertTrue($yearStart <= $earlier && $earlier < now()->startOfMonth()->toDateString(), 'দৃশ্যটাই বানানো যায়নি — '.$yearStart);

        $net = function (array $query = []): string {
            $page = $this->get(route('accounts.report.final.profit_loss', $query))->assertOk();
            $s = $page->viewData('summary');

            return $s['good'] ? (string) $s['value'] : bcmul((string) $s['value'], '-1', 4);
        };

        $before = $net(['from' => $yearStart, 'to' => now()->toDateString()]);
        $monthBefore = $net(['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);

        $cash = app(CashTillService::class)->ensurePrimaryTill()->account_id;
        app(PostingEngine::class)->post(sourceType: 'test:pl-year', sourceId: 1, trxDate: $earlier, lines: [
            ['account_id' => $cash, 'debit' => '365', 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::INTEREST_INCOME)->id, 'debit' => '0', 'credit' => '365'],
        ]);

        $this->assertSame(0, bccomp($net(), bcadd($before, '365', 4), 4), '⛔ তারিখ না দিলে লাভ-ক্ষতি অর্থবছরের আগের মাসের আয় দেখায় না।');
        // ⓘ মাস বেছে নিলে আগের মতোই কেবল মাস — ডিফল্টটাই বদলেছে, ছাঁকনি নয়
        $this->assertSame(0, bccomp($net(['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]), $monthBefore, 4));
    }
}
