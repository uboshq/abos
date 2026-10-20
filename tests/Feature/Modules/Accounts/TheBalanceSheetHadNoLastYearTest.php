<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ স্থিতিপত্রে কেবল এক দিনের অঙ্ক ছিল, আগের বছরের পাশাপাশি কলাম নেই (ERP ১০০%-এর হিসাবের ফাঁক #৭, ২০ অক্টোবর ২০২৬)।
 *
 * <p>IAS 1.38: আর্থিক বিবরণীর প্রতিটা অঙ্কের সাথে আগের সময়ের অঙ্ক। লাভ-ক্ষতির পাতায় তুলনা ছিল, স্থিতিপত্রে ছিল না — ব্যাংক
 * বা নিরীক্ষক "গত বছরের শেষে কত ছিল" জানতে আলাদা তারিখে আবার পাতা খুলতেন, আর দুই পাতা পাশে রেখে মেলাতেন। এখন পাশে
 * আগের অর্থবছরের শেষ দিনের (১ জুলাইয়ের বছরে ৩০ জুন) অঙ্ক, একই শাখায়।
 */
final class TheBalanceSheetHadNoLastYearTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Account $till;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ আগের অর্থবছর — নমুনায় কেবল ২০২৬-২৭
        FinancialYear::query()->firstOrCreate(
            ['company_id' => $company->id, 'starts_on' => '2025-07-01'],
            ['name' => '2025-2026', 'ends_on' => '2026-06-30', 'is_closed' => false, 'is_current' => false],
        );

        $this->till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        $this->bank = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1102-CMP', 'name_en' => 'Compare Bank', 'name_bn' => 'তুলনা ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK,
        ]);

        // গত বছর: নগদে ৫,০০০, ব্যাংকে ৩,০০০। এ বছর: নগদে আরও ২,০০০, আর ব্যাংকের ৩,০০০ তুলে নগদে — ব্যাংক এখন ০
        $this->putMoneyIn($this->till, '5000', '2026-06-15');
        $this->putMoneyIn($this->bank, '3000', '2026-06-15');
        $this->putMoneyIn($this->till, '2000', '2026-08-01');
        app(PostingEngine::class)->post(
            sourceType: 'test:bank-to-till', sourceId: random_int(1, PHP_INT_MAX), trxDate: '2026-08-02',
            lines: [
                ['account_id' => $this->till->id, 'debit' => '3000', 'narration' => 'পরীক্ষা'],
                ['account_id' => $this->bank->id, 'credit' => '3000', 'narration' => 'পরীক্ষা'],
            ],
        );
    }

    public function test_every_line_carries_last_years_closing_figure_and_a_line_only_last_year_still_shows(): void
    {
        $sheets = app(BalanceSheetService::class);
        $this->assertSame('2026-06-30', $sheets->priorDate('2026-10-20'), '⛔ তুলনার দিন আগের অর্থবছরের শেষ দিন নয়');

        $sheet = $sheets->withComparative($sheets->build('2026-10-20'), $sheets->build('2026-06-30'));
        $lines = collect($sheet['assets'])->flatMap(fn ($group) => $group['lines'])->keyBy(fn ($line) => $line['account']->id);

        // ⓘ সারি ছকের দ্বিতীয় স্তর (১১০১ হাতে নগদ, ১১০২ ব্যাংক), নিচের টিল আর ব্যাংক তার ঘরে যোগ হয় ([[BalanceSheetService::side()]])
        $till = $lines->get($this->till->parent_id);
        $this->assertNotNull($till, 'প্রস্তুতিটাই ভুল — নগদের সারি নেই');
        $this->assertSame(0, bccomp($till['amount'], '10000', 4), 'এ বছরের নগদ ৫,০০০ + ২,০০০ + ৩,০০০ নয়');
        $this->assertSame(0, bccomp($till['prior'], '5000', 4), '⛔ নগদের পাশে গত বছরের শেষের ৫,০০০ নেই');

        $bank = $lines->get($this->bank->parent_id);
        $this->assertNotNull($bank, '⛔ কেবল গত বছরে টাকা থাকা ব্যাংকের সারি তুলনায় হারিয়ে গেল');
        $this->assertSame(0, bccomp($bank['amount'], '0', 4));
        $this->assertSame(0, bccomp($bank['prior'], '3000', 4));

        // ⛔ প্রতিটা মাথার আগের বছরের অঙ্ক তার সারিগুলোর যোগ, আর পক্ষের যোগ মাথাগুলোর যোগ — কিছু হারায়নি
        $headsPrior = '0';
        foreach ($sheet['assets'] as $group) {
            $headsPrior = bcadd($headsPrior, $group['prior'], 4);
        }
        $this->assertSame(0, bccomp($headsPrior, $sheet['prior']['totals']['assets'], 4), '⛔ আগের বছরের মাথাগুলোর যোগ মোট সম্পদ নয়');
        $this->assertSame(0, bccomp($sheet['prior']['totals']['assets'], '8000', 4), 'গত বছরের শেষে মোট সম্পদ ৫,০০০ + ৩,০০০ নয়');
        $this->assertTrue($sheet['prior']['agrees'], 'গত বছরের স্থিতিপত্রও মেলে');
    }

    public function test_the_page_shows_both_dates_and_last_years_figures_beside_this_years(): void
    {
        $html = (string) $this->get(route('accounts.balance_sheet', ['as_of' => '2026-10-20']))->assertOk()->getContent();

        $this->assertStringContainsString(DateFormat::format('2026-06-30'), $html, '⛔ তুলনার কলামের তারিখ পাতায় নেই');
        $this->assertStringContainsString(DateFormat::format('2026-10-20'), $html);
        $this->assertMatchesRegularExpression('/data-prior[^>]*>\s*'.preg_quote(Money::format('3000'), '/').'\s*</', $html,
            '⛔ ব্যাংকের গত বছরের ৩,০০০ পাশের কলামে নেই');
        $this->assertMatchesRegularExpression('/data-prior[^>]*>\s*'.preg_quote(Money::format('8000'), '/').'\s*</', $html,
            '⛔ গত বছরের শেষের মোট সম্পদ পাশের কলামে নেই');
    }
}
