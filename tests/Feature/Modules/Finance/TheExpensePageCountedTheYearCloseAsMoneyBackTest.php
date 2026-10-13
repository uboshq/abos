<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Finance\Services\HeadTotals;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খরচ আর আয়ের পাতা বছর বন্ধের দাখিলাকে টাকা ফেরত ভাবত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️১)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * বছর বন্ধ ([[YearEndService::close()]]) বছরের শেষ দিনে প্রতিটা খরচের খাত ক্রেডিট করে শূন্য করে। লাভ-ক্ষতি,
 * বাজেট, খাতওয়ারি খরচের রিপোর্ট ঐ দাখিলা বাদ দেয় ([[YearEndService::closingSources()]]), কিন্তু
 * [[HeadTotals]] দিত না: বছরের শেষ দিনটা পরিসরে পড়লেই ভাড়ার খাত শূন্য দেখাত আর খরচের পাতা ও
 * ড্যাশবোর্ডের "টাকা কোথায় গেল" তালিকা থেকে হারিয়ে যেত।
 *
 * ── ⭐ দাবি ───────────────────────────────────────────────────────────
 * বছর বন্ধ করা খাতের খরচ বদলায় না — আগে আর পরে হুবহু এক। ⓘ ডেমোর নিজের সারিও থাকে, তাই আগে-পরের সমতা।
 */
final class TheExpensePageCountedTheYearCloseAsMoneyBackTest extends TestCase
{
    use RefreshDatabase;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    public function test_closing_the_year_leaves_the_expense_heads_as_they_were(): void
    {
        $this->spendOnRent('50000');

        $from = $this->year->starts_on->toDateString();
        $to = $this->year->ends_on->toDateString();
        $heads = app(HeadTotals::class);

        $before = $this->rentNow($heads->forParent(StandardChart::OPERATING_EXPENSES, $from, $to, true));
        $beforeTop = $this->rentTop($heads->topUnder(StandardChart::OPERATING_EXPENSES, $from, $to, 50));

        $this->assertNotNull($before, 'প্রস্তুতিটাই ভুল — ভাড়ার খাত বন্ধের আগেও তালিকায় নেই।');
        $this->assertNotNull($beforeTop, 'প্রস্তুতিটাই ভুল — ভাড়ার খাত বন্ধের আগেও ড্যাশবোর্ডের তালিকায় নেই।');

        app(YearEndService::class)->close($this->year);

        $after = $this->rentNow($heads->forParent(StandardChart::OPERATING_EXPENSES, $from, $to, true));
        $afterTop = $this->rentTop($heads->topUnder(StandardChart::OPERATING_EXPENSES, $from, $to, 50));

        $this->assertSame($before, $after,
            '⛔ বছর বন্ধের পরে খরচের পাতায় ভাড়ার খাত বদলে গেছে বা হারিয়ে গেছে — বন্ধের দাখিলা গোনা হচ্ছে।');
        $this->assertSame($beforeTop, $afterTop,
            '⛔ বছর বন্ধের পরে ড্যাশবোর্ডের "টাকা কোথায় গেল" তালিকায় ভাড়া বদলে গেছে বা হারিয়ে গেছে।');
    }

    /** @param  list<array{account: \App\Modules\Accounts\Models\Account, now: string, before: string}>  $rows */
    private function rentNow(array $rows): ?string
    {
        foreach ($rows as $row) {
            if ($row['account']->code === StandardChart::RENT) {
                return bcadd($row['now'], '0', 4);
            }
        }

        return null;
    }

    /** @param  list<array{label: string, amount: string}>  $rows */
    private function rentTop(array $rows): ?string
    {
        $label = StandardChart::find(StandardChart::RENT)->name();

        foreach ($rows as $row) {
            if ($row['label'] === $label) {
                return bcadd($row['amount'], '0', 4);
            }
        }

        return null;
    }

    private function spendOnRent(string $amount): void
    {
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account;
        // ⓘ বছরের শুরুর দিকে — ভবিষ্যতের তারিখে খাতা লেখে না ([[ClosingTheYearEmptiedSixScreensTest]]-এর ছাঁচ)
        $date = $this->year->starts_on->copy()->addMonths(3)->toDateString();

        app(PostingEngine::class)->post(sourceType: 'test_rent', sourceId: 1, trxDate: $date, lines: [
            ['account_id' => StandardChart::find(StandardChart::RENT)->id, 'debit' => $amount],
            ['account_id' => $cash->id, 'credit' => $amount],
        ]);
    }
}
