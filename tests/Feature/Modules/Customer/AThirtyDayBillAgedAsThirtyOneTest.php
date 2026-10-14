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
 * ⛔ বকেয়ার বয়সের ধাপ এক দিন সরে ছিল (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::ageing()]])।
 *
 * ⚠️ ঘরের নাম "০–৩০ দিন", কিন্তু হিসাব ছিল ০–২৯: ঠিক ৩০ দিনের বিল — সাধারণ বাকির মেয়াদের শেষ দিন — বসত "৩১–৬০"-এ; ঠিক ৯০
 * দিনেরটা "৯০+"-এ। ⭐ এখন নামের মতোই: ৩০ → ০–৩০, ৩১ → ৩১–৬০, ৯০ → ৬১–৯০, ৯১ → ৯০+।
 */
final class AThirtyDayBillAgedAsThirtyOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_bill_lands_in_the_bucket_its_label_names(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->delete();

        $customer = Customer::query()->create(['company_id' => CompanyContext::id(), 'code' => 'EDGE-X', 'name_en' => 'Edge Shop', 'is_active' => true]);
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $party = ['party_type' => Customer::drillSourceType(), 'party_id' => $customer->id];

        foreach ([30 => '1', 31 => '10', 90 => '100', 91 => '1000'] as $daysAgo => $amount) {
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 95000 + $daysAgo, trxDate: now()->subDays($daysAgo)->toDateString(),
                lines: [['account_id' => $receivable, 'debit' => $amount] + $party, ['account_id' => $income, 'credit' => $amount]]);
        }

        $result = app(ReportEngine::class)->run('customer.ageing', ['to' => now()->toDateString()], 1, 100);
        $row = (array) collect($result->rows)->first(fn ($r) => (int) ((array) $r)['party_id'] === $customer->id);

        $this->assertSame(0, bccomp('1', (string) $row['bucket_current'], 2), '⛔ ৩০ দিনের বিল ০–৩০-এ নেই: '.$row['bucket_current']);
        $this->assertSame(0, bccomp('10', (string) $row['bucket_30'], 2), '⛔ ৩১ দিনের বিল ৩১–৬০-এ নেই: '.$row['bucket_30']);
        $this->assertSame(0, bccomp('100', (string) $row['bucket_60'], 2), '⛔ ৯০ দিনের বিল ৬১–৯০-এ নেই: '.$row['bucket_60']);
        $this->assertSame(0, bccomp('1000', (string) $row['bucket_90'], 2), '⛔ ৯১ দিনের বিল ৯০+-এ নেই: '.$row['bucket_90']);
    }
}
