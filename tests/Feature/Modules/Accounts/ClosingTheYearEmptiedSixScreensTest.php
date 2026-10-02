<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\GroupLedgerService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Finance\Services\BudgetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * বছর বন্ধ করলে ছয়টা পর্দার আয়-খরচ শূন্য হয়ে যেত — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) §২, ২ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * বছর বন্ধের দাখিলা ([[YearEndService::close()]]) বছরের শেষ দিনে প্রতিটা আয় আর খরচের খাত শূন্য করে
 * সঞ্চিত মুনাফায় সরায়। লাভ-ক্ষতি আর স্থিতিপত্র ঐ দাখিলা চেনে ([[YearEndService::closingSources()]]), কিন্তু
 * আরও ছয় জায়গা চিনত না: খাতওয়ারি খরচ, খাতওয়ারি আয়, খরচের কেন্দ্র, ড্যাশবোর্ডের মাসের আয়-খরচ (আর তার ওপর
 * বিনিয়োগের ফেরত), দল-রিপোর্টের লাভ, আর বাজেটের প্রকৃত খরচ। বছরের শেষ দিনটা পরিসরে পড়লেই ওরা বলত
 * "এই বছরে খরচ শূন্য"।
 *
 * ── ⭐ দাবি ───────────────────────────────────────────────────────────
 * বছর বন্ধ করা কেবল লাভটা মূলধনে সরায় — বছরের আয়-খরচ বদলায় না। তাই ছয়টা সংখ্যাই বন্ধের আগে আর পরে
 * হুবহু এক। ⓘ ডেমোর নিজের সারিও থাকে, তাই পরম অঙ্ক নয়, আগে-পরের সমতা।
 */
final class ClosingTheYearEmptiedSixScreensTest extends TestCase
{
    use RefreshDatabase;

    private FinancialYear $year;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    public function test_closing_the_year_changes_no_income_or_expense_screen(): void
    {
        $this->trade(income: '50000', expense: '30000');
        $this->budgetTheLastMonth();

        $before = $this->readAll();

        $this->assertNotSame('0.00', $before['expense_by_head'], 'প্রস্তুতিটাই ভুল — বছরে কোনো খরচ নেই, দাবিটা কিছু মাপত না।');

        app(YearEndService::class)->close($this->year);

        $after = $this->readAll();

        foreach ($before as $screen => $value) {
            $this->assertSame(0, bccomp((string) $after[$screen], (string) $value, 2),
                "⛔ বছর বন্ধের পরে '{$screen}' বদলে গেছে: আগে {$value}, পরে {$after[$screen]} — বন্ধের দাখিলা গোনা হচ্ছে।");
        }
    }

    /** @return array<string, string> */
    private function readAll(): array
    {
        $from = $this->year->starts_on->toDateString();
        $to = $this->year->ends_on->toDateString();
        $range = ['from' => $from, 'to' => $to];
        $reports = app(ReportEngine::class);
        $facts = app(AccountsFacts::class);
        $group = app(GroupLedgerService::class)->build($this->owner, $from, $to);
        $end = Carbon::parse($to);
        $budget = app(BudgetService::class)->vsActual((int) $end->year, (int) $end->month, (int) $end->month);

        return [
            'expense_by_head' => (string) ($reports->run('accounts.expense_by_head', $range, perPage: 500)->totals['spent'] ?? '0'),
            'income_by_head' => (string) ($reports->run('accounts.income_by_head', $range, perPage: 500)->totals['earned'] ?? '0'),
            'by_cost_centre' => (string) ($reports->run('accounts.by_cost_centre', $range, perPage: 500)->totals['net'] ?? '0'),
            'dashboard_income' => $facts->netOfType(Account::INCOME, Carbon::parse($from), Carbon::parse($to)),
            'dashboard_expense' => $facts->netOfType(Account::EXPENSE, Carbon::parse($from), Carbon::parse($to)),
            'group_profit' => (string) ($group['total']['profit'] ?? '0'),
            'budget_actual' => (string) ($budget->first()['actual'] ?? 'no budget row'),
        ];
    }

    /** খরচের খাতে শেষ মাসের একটা বাজেট — বন্ধের দাখিলা ঠিক ঐ মাসে বসে। */
    private function budgetTheLastMonth(): void
    {
        $end = Carbon::parse($this->year->ends_on);

        app(BudgetService::class)->saveYear((int) $end->year, StandardChart::find(StandardChart::DISCOUNT_GIVEN)->id, null, [(int) $end->month => '1000']);
    }

    /** আয় আর খরচ ([[TheClosingEntryWasCountedFourDifferentWaysTest]]-এর ছাঁচ)। */
    private function trade(string $income, string $expense): void
    {
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account;
        // ⓘ বছরের শুরুর দিকে — ভবিষ্যতের তারিখে খাতা লেখে না; বাজেটের শেষ মাসে প্রকৃত তখন শূন্য, আর বন্ধের দাখিলা না চিনলে ঋণাত্মক
        $date = $this->year->starts_on->copy()->addMonths(3)->toDateString();

        app(PostingEngine::class)->post(sourceType: 'test_sale', sourceId: 1, trxDate: $date, lines: [
            ['account_id' => $cash->id, 'debit' => $income],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $income],
        ]);

        app(PostingEngine::class)->post(sourceType: 'test_spend', sourceId: 1, trxDate: $date, lines: [
            ['account_id' => StandardChart::find(StandardChart::DISCOUNT_GIVEN)->id, 'debit' => $expense],
            ['account_id' => $cash->id, 'credit' => $expense],
        ]);
    }
}
