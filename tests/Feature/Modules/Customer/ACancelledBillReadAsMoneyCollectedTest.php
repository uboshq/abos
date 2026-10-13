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
 * ⛔ আদায়ের রিপোর্টে বাতিল বিল "আদায়" হয়ে বসত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::collection()]])।
 *
 * ⚠️ ৫০ হাজারের বিল একই মাসে বাতিল — খাতায় বিলের ডেবিট আর তার উল্টো ক্রেডিট। রিপোর্ট কাঁচা ডেবিট আর কাঁচা ক্রেডিট যোগ করত,
 * তাই দেখাত "বিল ৫০ হাজার, আদায় ৫০ হাজার" — অথচ একটা পয়সাও আসেনি। ⭐ এখন উল্টো সারি নিজের ঘর থেকে বিয়োগ।
 */
final class ACancelledBillReadAsMoneyCollectedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bill_cancelled_in_the_same_month_is_neither_billed_nor_collected(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->delete();

        $customer = Customer::query()->create(['company_id' => CompanyContext::id(), 'code' => 'COL-X', 'name_en' => 'Cancelled Bill Shop', 'is_active' => true]);
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $party = ['party_type' => Customer::drillSourceType(), 'party_id' => $customer->id];

        // ⓘ ৫০ হাজারের বিল, তারপর ২০ হাজার সত্যিকারের আদায়, তারপর বিলটা বাতিল
        $engine = app(PostingEngine::class);
        $engine->post(sourceType: 'journal_voucher', sourceId: 91001, trxDate: now()->toDateString(),
            lines: [['account_id' => $receivable, 'debit' => '50000'] + $party, ['account_id' => $income, 'credit' => '50000']]);
        $engine->post(sourceType: 'receipt_voucher', sourceId: 91002, trxDate: now()->toDateString(),
            lines: [['account_id' => $income, 'debit' => '20000'], ['account_id' => $receivable, 'credit' => '20000'] + $party]);
        $engine->reverse('journal_voucher', 91001, now()->toDateString(), 'ভুল বিল');

        $result = app(ReportEngine::class)->run('customer.collection', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()], 1, 100);
        $row = (array) collect($result->rows)->first(fn ($r) => (int) ((array) $r)['party_id'] === $customer->id);

        $this->assertSame(0, bccomp('20000', (string) ($row['collected'] ?? '-1'), 2), '⛔ বাতিল বিল আদায় হয়ে বসল: '.($row['collected'] ?? 'নেই'));
        $this->assertSame(0, bccomp('0', (string) ($row['billed'] ?? '-1'), 2), '⛔ বাতিল বিল এখনো বিল হয়ে আছে: '.($row['billed'] ?? 'নেই'));
        $this->assertSame(0, bccomp('-20000', (string) ($row['movement'] ?? '-1'), 2), 'নিট বদল');
    }
}
