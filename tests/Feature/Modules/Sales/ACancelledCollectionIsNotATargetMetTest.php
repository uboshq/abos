<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CustomerTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বাতিল আদায় ডিলারের লক্ষ্য পূরণ দেখাত — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা ([[CustomerTargetService::achievedQuery()]])।
 *
 * ⓘ ৮০০ টাকার আদায় আর একই মাসে তার বাতিল (`collection:reversal`), আর ৫০০ টাকার রসিদ যা পরে বাতিল হয়ে উল্টায়নি → অর্জন ৫০০, ১,৩০০ নয়।
 */
final class ACancelledCollectionIsNotATargetMetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reversed_collection_is_taken_back_from_the_achievement(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $account = (int) Account::query()->where('is_group', false)->orderBy('id')->value('id');
        $year = (int) FinancialYear::query()->where('company_id', $company->id)->orderByDesc('starts_on')->value('id');

        foreach ([
            ['2019-04-05', 'collection', 961, 0, 800],
            ['2019-04-09', 'collection:reversal', 961, 800, 0],
            ['2019-04-12', 'receipt_voucher', 962, 0, 500],
        ] as [$date, $source, $id, $debit, $credit]) {
            DB::table('ledger_entries')->insert([
                'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'financial_year_id' => $year,
                'account_id' => $account, 'trx_date' => $date, 'source_type' => $source, 'source_id' => $id,
                'party_type' => 'customer', 'party_id' => $customer, 'debit' => $debit, 'credit' => $credit,
            ]);
        }

        $achieved = app(CustomerTargetService::class)->achievedFor([$customer], Carbon::parse('2019-04-01'), Carbon::parse('2019-04-30'));

        $this->assertSame('500.0000', $achieved[$customer], '⛔ বাতিল আদায়ের টাকাও লক্ষ্যের অর্জনে গোনা হলো');
    }
}
