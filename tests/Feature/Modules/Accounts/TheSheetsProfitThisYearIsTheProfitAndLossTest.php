<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ স্থিতিপত্রের "চলতি বছরের লাভ" আর লাভ-ক্ষতির নিট লাভ একই অঙ্ক — a5-এর পাতা-সাজানোর পরিকল্পনা, ধাপ ০ (সমন্বয়ক, ১২ অক্টোবর ২০২৬)।
 *
 * ⓘ [[BalanceSheetService]] সারিটার নাম দিত "চলতি বছরের লাভ", অথচ অঙ্ক গুনত খাতার শুরু থেকে — আগের বছর বন্ধ না হলে তার লাভও
 * এতে ঢুকত, আর লাভ-ক্ষতি (বছরের শুরু থেকে) অন্য অঙ্ক বলত। এখন আগের বন্ধ-না-হওয়া অংশ নিজের সারিতে; যোগ আগের মতোই, স্থিতিপত্র মেলে।
 */
final class TheSheetsProfitThisYearIsTheProfitAndLossTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unclosed_earlier_year_gets_its_own_line_and_this_year_matches_the_profit_and_loss(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $year = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $earlier = FinancialYear::query()->where('ends_on', $year->starts_on->copy()->subDay()->toDateString())->first()
            ?? FinancialYear::query()->create([
                'name' => 'earlier', 'starts_on' => $year->starts_on->copy()->subYear()->toDateString(),
                'ends_on' => $year->starts_on->copy()->subDay()->toDateString(), 'is_closed' => false, 'is_current' => false,
            ]);
        $this->assertFalse((bool) $earlier->is_closed, 'দৃশ্যটাই বানানো যায়নি — আগের বছর বন্ধ');

        $before = app(BalanceSheetService::class)->build($year->ends_on->toDateString());

        // ⓘ আগের (বন্ধ-না-হওয়া) বছরে ১০,০০০ বিক্রি; চলতি বছরে ৫০,০০০ বিক্রি আর ৩০,০০০ খরচ
        $this->trade(1, $earlier->ends_on->copy()->subMonths(2)->toDateString(), '10000', '0');
        $this->trade(2, $year->starts_on->copy()->addMonths(3)->toDateString(), '50000', '30000');

        $sheet = app(BalanceSheetService::class)->build($year->ends_on->toDateString());

        $pl = app(ReportEngine::class)->run('accounts.profit_loss', [
            'from' => $year->starts_on->toDateString(), 'to' => $year->ends_on->toDateString(),
        ], 1, 500)->totals;
        $plNet = bcsub((string) ($pl['credit'] ?? '0') ?: '0', (string) ($pl['debit'] ?? '0') ?: '0', 4);

        $this->assertSame(0, bccomp($sheet['profit_this_year'], $plNet, 4),
            "⛔ স্থিতিপত্রের চলতি বছরের লাভ {$sheet['profit_this_year']}, লাভ-ক্ষতির নিট লাভ {$plNet}");
        $this->assertSame(0, bccomp(bcsub($sheet['profit_this_year'], $before['profit_this_year'], 4), '20000', 4));
        $this->assertSame(0, bccomp(bcsub($sheet['profit_earlier'], $before['profit_earlier'], 4), '10000', 4),
            '⛔ আগের বছরের বন্ধ-না-হওয়া লাভ নিজের সারিতে আসেনি');
        $this->assertSame(0, bccomp($sheet['profit'], bcadd($sheet['profit_this_year'], $sheet['profit_earlier'], 4), 4));
        $this->assertTrue($sheet['agrees'], 'স্থিতিপত্র মেলে না — বেমিল '.$sheet['difference']);

        $this->get(route('accounts.balance_sheet', ['as_of' => $year->ends_on->toDateString()]))
            ->assertOk()
            ->assertSee(__('accounts::field.profit_earlier_years'))
            ->assertSee(__('accounts::field.profit_this_year'));
    }

    /** ⓘ ইঞ্জিনের স্থিতিপত্র (রিপোর্টের পাতা, ফোন, নির্ধারিত রিপোর্ট) একই দুই ভাগ বলে — পাতার স্থিতিপত্রের অঙ্কে ([[CoreReports::unclosedSplit()]]) */
    public function test_the_engine_balance_sheet_splits_the_same_way(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $year = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $earlier = FinancialYear::query()->where('ends_on', $year->starts_on->copy()->subDay()->toDateString())->first()
            ?? FinancialYear::query()->create([
                'name' => 'earlier', 'starts_on' => $year->starts_on->copy()->subYear()->toDateString(),
                'ends_on' => $year->starts_on->copy()->subDay()->toDateString(), 'is_closed' => false, 'is_current' => false,
            ]);

        $this->trade(1, $earlier->ends_on->copy()->subMonths(2)->toDateString(), '10000', '0');
        $this->trade(2, $year->starts_on->copy()->addMonths(3)->toDateString(), '50000', '30000');

        $asOf = $year->ends_on->toDateString();
        $sheet = app(BalanceSheetService::class)->build($asOf);
        $result = app(ReportEngine::class)->run('accounts.balance_sheet', ['from' => '2000-01-01', 'to' => $asOf], 1, 500);
        $summary = (app(ReportEngine::class)->get('accounts.balance_sheet')->summary)($result->totals, $result->filters);

        $lines = collect($summary['lines'] ?? [])->pluck('value', 'label');

        $this->assertSame(0, bccomp((string) $lines->get(__('accounts::field.profit_this_year')), $sheet['profit_this_year'], 4),
            '⛔ ইঞ্জিনের স্থিতিপত্রের চলতি বছরের লাভ পাতার স্থিতিপত্রের সাথে মেলে না');
        $this->assertSame(0, bccomp((string) $lines->get(__('accounts::field.profit_earlier_years')), $sheet['profit_earlier'], 4),
            '⛔ ইঞ্জিনের স্থিতিপত্রে আগের বন্ধ-না-হওয়া বছরের সারি নেই');
        $this->assertSame(0, bccomp($summary['value'], ltrim($sheet['profit'], '-'), 4), 'মোট বন্ধ-না-হওয়া লাভ আগের মতোই');
    }

    private function trade(int $id, string $date, string $income, string $expense): void
    {
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account;

        app(PostingEngine::class)->post(sourceType: 'test_sale', sourceId: $id, trxDate: $date, lines: [
            ['account_id' => $cash->id, 'debit' => $income],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $income],
        ]);

        if (bccomp($expense, '0', 4) > 0) {
            app(PostingEngine::class)->post(sourceType: 'test_spend', sourceId: $id, trxDate: $date, lines: [
                ['account_id' => StandardChart::find(StandardChart::DISCOUNT_GIVEN)->id, 'debit' => $expense],
                ['account_id' => $cash->id, 'credit' => $expense],
            ]);
        }
    }
}
