<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Reports\ExpenseAnalysisReport;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খরচের বিশ্লেষণ — কোন খাতে হঠাৎ বাড়ল, সবচেয়ে বড় খরচ কত। রিপোর্ট সেন্টার ধাপ ৪, ২ অক্টোবর ২০২৬
 * ([[ExpenseAnalysisReport]])।
 *
 * নতুন একটা খাত, যাতে ডেমোর কিছু নেই। দশ দিনের সময়: এই সময়ে ১০০ আর ৪০০; ঠিক আগের দশ দিনে ২০০; তারও আগে
 * ৭,০০০ — যেটা কোনো ঘরেই পড়ার কথা নয়।
 */
final class TheExpenseThatJumpedIsNamedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_period_is_compared_with_the_one_before_and_the_biggest_entry_is_shown(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        CompanyContext::set($company->id, $home->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $rent = StandardChart::find(StandardChart::RENT);
        $head = tap($rent->replicate(['public_id']), fn (Account $a) => $a->forceFill([
            'code' => '5299-EXA', 'name_en' => 'Jump Head', 'name_bn' => 'Jump Head',
        ])->save());
        $cash = Account::query()->ofMoneyKind(Account::CASH)->postable()->active()->orderBy('id')->firstOrFail();

        foreach ([[0, '400'], [2, '100'], [15, '200'], [25, '7000']] as [$ago, $amount]) {
            app(PostingEngine::class)->post(
                sourceType: 'test:expense', sourceId: random_int(1, 9_999_999), trxDate: now()->subDays($ago)->toDateString(),
                lines: [['account_id' => $head->id, 'debit' => $amount], ['account_id' => $cash->id, 'credit' => $amount]],
                branchId: $home->id,
            );
        }

        $row = collect(app(ReportEngine::class)->run(ExpenseAnalysisReport::KEY, [
            'from' => now()->subDays(9)->toDateString(), 'to' => now()->toDateString(),
        ], perPage: 500)->rows)->first(fn ($r) => str_contains((string) $r['account_name'], 'Jump Head'));

        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — খাতের সারি নেই।');
        $this->assertSame(0, bccomp((string) $row['spent'], '500', 2), '⛔ এই সময়ের খরচ ভুল — আগের সময় ঢুকে পড়েছে?');
        $this->assertSame(0, bccomp((string) $row['previous'], '200', 2), '⛔ আগের সময়ের খরচ ভুল — তারও আগের ৭,০০০ ঢুকেছে?');
        $this->assertSame(0, bccomp((string) $row['change'], '150', 1), '⛔ বদল ১৫০% হওয়ার কথা।');
        $this->assertSame(0, bccomp((string) $row['biggest'], '400', 2), '⛔ সবচেয়ে বড় খরচ ৪০০ — আগের সময়ের কিছু নয়।');
        $this->assertSame(2, (int) $row['entries'], '⛔ এই সময়ে দাখিলা দুটো।');

        $this->get(route('accounts.report.show', ['slug' => 'expense-analysis']))->assertOk();
    }
}
