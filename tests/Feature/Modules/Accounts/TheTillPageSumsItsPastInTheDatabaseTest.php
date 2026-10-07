<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ টিলের খাতার পাতা পুরো ইতিহাস মেমরিতে তুলত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৫)।
 *
 * ⓘ [[CashTillController::show()]] পাতার আগের সব সারি PHP-তে এনে খোলা জের যোগ করত; ব্যস্ত বাক্সে প্রথম পাতাতেই হাজার সারি। এখন
 * ডেটাবেসে এক SUM — আর চলমান জের প্রতিটা পাতায় আগের মতোই ঠিক।
 */
final class TheTillPageSumsItsPastInTheDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_keeps_the_running_balance_and_the_past_is_one_sum(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $till = app(CashTillService::class)->create(['code' => 'BUSY-15', 'name_en' => 'Busy Box', 'name_bn' => 'ব্যস্ত বাক্স']);
        $other = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;

        // ⓘ ৬১টা সারি, দিনে একটা: ১০০ করে ঢোকে, প্রতি পঞ্চমটায় ৩০ বেরোয়
        $expected = [];
        $sum = '0';

        for ($i = 1; $i <= 61; $i++) {
            $out = $i % 5 === 0;
            $amount = $out ? '30' : '100';
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 900000 + $i, trxDate: now()->subDays(70 - $i)->toDateString(), lines: $out
                ? [['account_id' => $other, 'debit' => $amount], ['account_id' => $till->account_id, 'credit' => $amount]]
                : [['account_id' => $till->account_id, 'debit' => $amount], ['account_id' => $other, 'credit' => $amount]]);
            $sum = $out ? bcsub($sum, $amount, 4) : bcadd($sum, $amount, 4);
            $expected[$i] = $sum;
        }

        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $first = $this->get(route('accounts.till.show', $till))->assertOk()->viewData('entries');

        // ⓘ প্রথম পাতা — নতুন ৫০টা (৬১ … ১২); সবচেয়ে পুরনোটার জের ১২ সারির পরের জের
        $this->assertSame(0, bccomp($expected[61], (string) $first->getCollection()->first()->running_balance, 4), '⛔ প্রথম পাতার শেষ জের ভুল');
        $this->assertSame(0, bccomp($expected[12], (string) $first->getCollection()->last()->running_balance, 4), '⛔ পাতার আগের ১১ সারির খোলা জের ভুল');
        $this->assertNotEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'before_page') && stripos($sql, 'sum(') !== false),
            '⛔ পাতার আগের সারিগুলো ডেটাবেসে যোগ হয়নি — PHP-তে তোলা হচ্ছে');

        $second = $this->get(route('accounts.till.show', [$till, 'page' => 2]))->assertOk()->viewData('entries');
        $this->assertSame(0, bccomp($expected[11], (string) $second->getCollection()->first()->running_balance, 4));
        $this->assertSame(0, bccomp($expected[1], (string) $second->getCollection()->last()->running_balance, 4), 'শেষ পাতার শুরু শূন্য থেকে');
    }
}
