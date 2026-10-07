<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ খাতের পাতা পাতার আগের সব সারি মেমরিতে তুলত — পুরো-ERP অডিট হিসাব ⚠️১৫-এর একই ফাঁক (৭ অক্টোবর ২০২৬)।
 *
 * ⓘ [[ChartOfAccountsController::show()]] দ্বিতীয় পাতা থেকে আগের সারিগুলো PHP-তে এনে খোলা জের যোগ করত। এখন ডেটাবেসে এক SUM — চলমান
 * জের প্রতিটা পাতায় আগের মতোই।
 */
final class TheAccountPageSumsItsPastInTheDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_second_page_carries_the_first_in_one_sum(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $account = Account::query()->create([
            'company_id' => $company->id, 'code' => '1983', 'name_en' => 'Busy Account', 'name_bn' => 'ব্যস্ত খাত', 'parent_id' => null,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'is_group' => false, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        $other = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;

        // ⓘ ৫৩টা সারি, প্রতিটায় ১০০ — পাতায় ৫০টা, তাই দ্বিতীয় পাতায় ৩টা
        for ($i = 1; $i <= 53; $i++) {
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 870000 + $i, trxDate: now()->subDays(60 - $i)->toDateString(), lines: [
                ['account_id' => $account->id, 'debit' => '100'],
                ['account_id' => $other, 'credit' => '100'],
            ]);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $entries = $this->get(route('accounts.coa.show', [$account, 'page' => 2]))->assertOk()->viewData('entries');
        $balances = $entries->getCollection()->pluck('running_balance')->map(fn ($b) => bcadd((string) $b, '0', 2))->sort()->values()->all();

        $this->assertSame(['5100.00', '5200.00', '5300.00'], $balances, '⛔ দ্বিতীয় পাতার চলমান জের প্রথম পাতার ৫,০০০ থেকে শুরু হয়নি');
        $this->assertNotEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'before_page') && stripos($sql, 'sum(') !== false),
            '⛔ পাতার আগের সারিগুলো ডেটাবেসে যোগ হয়নি — PHP-তে তোলা হচ্ছে');
    }
}
