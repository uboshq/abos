<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বকেয়ার বয়সে বাতিল বিল পুরনো বকেয়া "শোধ" করত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::ageing()]])।
 *
 * ⚠️ ১২০ দিনের ১ লাখ বকেয়া; কাল ৫০ হাজারের বিল, আজ বাতিল। উল্টো সারির ক্রেডিট আদায় হয়ে ৯০+ থেকে কাটত, আর মূল বিলটা
 * ০–৩০-এ থাকত: ৯০+ = ৫০ হাজার, ০–৩০ = ৫০ হাজার। সত্যি: ৯০+ = ১ লাখ, চলতি কিছু নেই। ⭐ এখন মূল আর উল্টো জোড়া ধরে বাদ।
 */
final class ACancelledBillAgedTheOldDueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cancelled_new_bill_leaves_the_old_due_in_its_own_bucket(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->delete();

        $customer = Customer::query()->create(['company_id' => CompanyContext::id(), 'code' => 'AGE-X', 'name_en' => 'Old Due Shop', 'is_active' => true]);
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $party = ['party_type' => Customer::drillSourceType(), 'party_id' => $customer->id];
        $bill = fn (int $id, string $amount, int $daysAgo) => app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: $id,
            trxDate: now()->subDays($daysAgo)->toDateString(),
            lines: [['account_id' => $receivable, 'debit' => $amount] + $party, ['account_id' => $income, 'credit' => $amount]]);

        $bill(92001, '100000', 120);
        $bill(92002, '50000', 1);
        app(PostingEngine::class)->reverse('journal_voucher', 92002, now()->toDateString(), 'ভুল বিল');

        $result = app(ReportEngine::class)->run('customer.ageing', ['to' => now()->toDateString()], 1, 100);
        $row = (array) collect($result->rows)->first(fn ($r) => (int) ((array) $r)['party_id'] === $customer->id);

        $this->assertSame(0, bccomp('100000', (string) ($row['bucket_90'] ?? '-1'), 2), '⛔ বাতিল বিল ৯০+ দিনের বকেয়া কমাল: '.($row['bucket_90'] ?? 'নেই'));
        $this->assertSame(0, bccomp('0', (string) ($row['bucket_current'] ?? '-1'), 2), '⛔ বাতিল বিল চলতি বালতিতে রয়ে গেল: '.($row['bucket_current'] ?? 'নেই'));
        $this->assertSame(0, bccomp('100000', (string) ($row['outstanding'] ?? '-1'), 2), 'মোট বকেয়া');
    }
}
