<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * আয়ের খাতগুলোর নিজের কোনো পাতা ছিল না।
 *
 * ── ⓘ অর্থের মানচিত্র §১০, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * "আয়ের শ্রেণি" লাইনটা "বাকি" লেখা ছিল, অথচ খরচের দিকে হুবহু একই পাতা
 * ([[CoreReports::expenseByHead()]]) অনেক দিন ধরেই আছে। ⓘ প্রশ্নটা
 * রোজকার: *"এই মাসে ভাড়া থেকে কত এল, সুদ থেকে কত"*।
 *
 * ── ⚠️ চিহ্নটাই এই পাতার একমাত্র ফাঁদ ─────────────────────────────────
 * আয় ক্রেডিটে বাড়ে। ⛔ খরচের সূত্র (debit − credit) নকল করলে প্রতিটা
 * সংখ্যা ঋণাত্মক হত, আর সবচেয়ে বড় আয়টা তালিকার নিচে পড়ে থাকত।
 */
final class TheIncomeHeadsHadNoPageOfTheirOwnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $vouchers = app(VoucherService::class);

        // ⓘ দুইটা আয়ের খাতে টাকা — বিক্রয়ে বেশি, সুদে কম
        foreach ([
            [StandardChart::SALES, '20000.00'],
            [StandardChart::INTEREST_INCOME, '5000.00'],
        ] as [$code, $amount]) {
            $head = (int) Account::query()->where('code', $code)->value('id');

            $vouchers->post($vouchers->create(
                ['type' => 'receipt', 'trx_date' => '2026-08-10', 'narration' => 'seed'],
                $vouchers->twoLineEntry('receipt', $head, $till, $amount, 'seed'),
            ));
        }
    }

    /**
     * ⭐ খাত ধরে আয়, আর সংখ্যাগুলো ধনাত্মক — বড়টা উপরে।
     */
    public function test_the_report_adds_income_up_by_head(): void
    {
        $rows = collect(app(ReportEngine::class)->run('accounts.income_by_head', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ])->rows);

        $earned = $rows->pluck('earned', 'account_name')
            ->map(fn ($n) => (string) $n);

        $this->assertSame(0, bccomp($earned->first(), '20000', 2),
            'সবচেয়ে বড় আয়টা উপরে নেই, নাকি চিহ্ন উল্টো বসেছে।');

        $this->assertSame(0, bccomp((string) $earned->sum(fn ($n) => (float) $n), '25000', 2),
            'দুই খাতের আয় মিলে ২৫,০০০ হওয়ার কথা।');
    }

    /**
     * ⛔ খরচ এই পাতায় নেই — খাতের ধরনই তালিকা ঠিক করে।
     */
    public function test_expenses_stay_out_of_it(): void
    {
        $expense = Account::query()->where('type', Account::EXPENSE)->where('is_group', false)->first();

        $rows = app(ReportEngine::class)->run('accounts.income_by_head', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ])->rows;

        $this->assertNotContains($expense?->id, array_column($rows, 'account_id'),
            'খরচের খাত আয়ের তালিকায় ঢুকেছে।');
    }

    /**
     * ⭐ পর্দাটা খোলে — রিপোর্ট লেখা হয়েও ঠিকানা থেকে পৌঁছানো যায়নি, এমন
     * ঘটনা এই মডিউলে আগে ঘটেছে ([[ReportController::SLUGS]]-এর মন্তব্য)।
     */
    public function test_the_screen_opens_at_its_own_address(): void
    {
        $this->get(route('accounts.report.show', ['slug' => 'income-by-head']))
            ->assertOk()
            ->assertSee(__('accounts::field.income_head'));
    }
}
