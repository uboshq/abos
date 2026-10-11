<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Services\BudgetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * একই খাতের বাজেট দুই চাপে একসাথে — একই মাসের দুটো সারি (পুরো-ERP অডিট, অর্থ M29, ১০ অক্টোবর ২০২৬)।
 *
 * ⭐ সংরক্ষণ খাতের সারিতে তালা দিয়ে শুরু, তারপর মাসের ঘর খোঁজা — দ্বিতীয় চাপ প্রথমটার ঘর দেখে বদলায়, নতুন বসায় না।
 */
final class TwoSavesDoubledOneBudgetMonthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⭐ অর্থবছর — জুলাই থেকে এ মাস, বছর পেরিয়ে; বাজেট আর প্রকৃত দুটোই সেই পরিসরে (অর্থ M29, fe ১০ অক্টোবর ২০২৬)।
     * ⓘ ফেব্রুয়ারি ২০২৭-এ: অর্থবছর জুলাই ২০২৬ – ফেব্রুয়ারি ২০২৭; জুন ২০২৬-এর বাজেট বাদ, আগস্ট ২০২৬ আর জানুয়ারি ২০২৭ ভেতরে।
     */
    public function test_the_financial_year_scope_reaches_across_the_new_year(): void
    {
        $this->travelTo('2027-02-15 10:00:00');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $account = Account::query()->create([
            'code' => '5999-FY', 'name_en' => 'FY test expense', 'parent_id' => Account::query()->where('type', Account::EXPENSE)->where('is_group', true)->orderBy('code')->value('id'),
            'type' => Account::EXPENSE, 'nature' => Account::DEBIT, 'is_active' => true,
        ]);
        app(BudgetService::class)->saveYear(2026, (int) $account->id, null, [6 => '9000', 8 => '1000']);
        app(BudgetService::class)->saveYear(2027, (int) $account->id, null, [1 => '500']);

        $cash = Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
        // ⓘ জুন ২০২৬ কোনো অর্থবছরে নেই (প্রথমটা জুলাই থেকে) — তাই খরচ কেবল সেপ্টেম্বর আর জানুয়ারিতে; জুনের বাজেট তবু বাইরে থাকে
        foreach ([['2026-09-10', '300'], ['2027-01-12', '200']] as $i => [$day, $amount]) {
            app(\App\Core\Engines\Posting\PostingEngine::class)->post(sourceType: 'test:fy', sourceId: $i + 1, trxDate: $day, lines: [
                ['account_id' => $account->id, 'debit' => $amount], ['account_id' => $cash->id, 'credit' => $amount],
            ]);
        }

        $page = $this->get(route('finance.budget.actual', ['scope' => 'fy']))->assertOk()->assertSee(__('finance::budget.scope_fy'));
        $row = collect($page->viewData('rows'))->first(fn ($r) => (int) $r['account']->id === (int) $account->id);

        $this->assertNotNull($row, '⛔ অর্থবছরের পরিসরে খাতটাই নেই');
        $this->assertSame(0, bccomp($row['budget'], '1500', 4), '⛔ অর্থবছরের বাজেট আগস্ট ২০২৬ + জানুয়ারি ২০২৭ নয়: '.$row['budget']);
        $this->assertSame(0, bccomp($row['actual'], '500', 4), '⛔ অর্থবছরের প্রকৃত সেপ্টেম্বর ২০২৬ + জানুয়ারি ২০২৭ নয়: '.$row['actual']);

        // ⓘ আগের পরিসর বদলায়নি — চলতি বছরের শুরু থেকে কেবল জানুয়ারি ২০২৭
        $ytd = collect($this->get(route('finance.budget.actual', ['year' => 2027]))->assertOk()->viewData('rows'))
            ->first(fn ($r) => (int) $r['account']->id === (int) $account->id);
        $this->assertSame(0, bccomp($ytd['budget'], '500', 4));
    }

    public function test_the_account_is_locked_before_the_month_cells_are_looked_up(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $account = Account::query()->postable()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        app(BudgetService::class)->saveYear(2026, (int) $account->id, null, [1 => '1000', 2 => '2000']);

        $lockAt = collect($queries)->search(fn ($sql) => str_contains($sql, 'from `accounts`') && str_contains($sql, 'for update'));
        $askAt = collect($queries)->search(fn ($sql) => str_contains($sql, 'from `fin_budgets`'));
        $this->assertNotFalse($askAt, 'দৃশ্যটাই বানানো যায়নি — মাসের ঘরের খোঁজ পাওয়া গেল না');
        $this->assertNotFalse($lockAt, '⛔ খাতের সারিতে তালা নেই — দুই চাপ একই মাস দুবার বসাতে পারে');
        $this->assertLessThan($askAt, $lockAt, '⛔ ঘরের খোঁজ তালার আগে');

        // ⓘ আবার সংরক্ষণ — একই মাসে একটাই সারি, অঙ্ক বদলায়
        app(BudgetService::class)->saveYear(2026, (int) $account->id, null, [1 => '1500', 2 => '2000']);
        $this->assertSame(1, Budget::query()->where('account_id', $account->id)->where('year', 2026)->where('month', 1)->count());
        $this->assertSame(0, bccomp((string) Budget::query()->where('account_id', $account->id)->where('year', 2026)->where('month', 1)->value('amount'), '1500', 4));
    }
}
