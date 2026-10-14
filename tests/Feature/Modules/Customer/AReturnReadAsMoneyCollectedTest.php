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
 * ⛔ আদায়ের রিপোর্টে বিক্রয় ফেরত "আদায়" হয়ে বসত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::collection()]])।
 *
 * ⚠️ ১০ হাজারের বিল, ৩ হাজারের মাল ফেরত, কোনো টাকা আসেনি — রিপোর্ট বলত "বিল ১০ হাজার, আদায় ৩ হাজার"। ফেরতের ক্রেডিট গ্রাহকের
 * খাতায় বসে ([[SalesReturnService]]), আর রিপোর্ট প্রতিটা ক্রেডিটকে আদায় ধরত। ⭐ এখন ফেরত বিল কমায়: বিল ৭ হাজার, আদায় ০; ফেরতটা
 * বাতিল হলে বিল আবার ১০ হাজার।
 */
final class AReturnReadAsMoneyCollectedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_return_lowers_the_bill_and_is_never_collected(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->delete();

        $customer = Customer::query()->create(['company_id' => CompanyContext::id(), 'code' => 'RET-X', 'name_en' => 'Returned Goods Shop', 'is_active' => true]);
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $party = ['party_type' => Customer::drillSourceType(), 'party_id' => $customer->id];

        $engine = app(PostingEngine::class);
        $engine->post(sourceType: 'journal_voucher', sourceId: 92001, trxDate: now()->toDateString(),
            lines: [['account_id' => $receivable, 'debit' => '10000'] + $party, ['account_id' => $income, 'credit' => '10000']]);
        $engine->post(sourceType: 'sales_return', sourceId: 92002, trxDate: now()->toDateString(),
            lines: [['account_id' => $income, 'debit' => '3000'], ['account_id' => $receivable, 'credit' => '3000'] + $party]);

        $row = function () use ($customer): array {
            $result = app(ReportEngine::class)->run('customer.collection', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()], 1, 100);

            return (array) collect($result->rows)->first(fn ($r) => (int) ((array) $r)['party_id'] === $customer->id);
        };

        $r = $row();
        $this->assertSame(0, bccomp('0', (string) ($r['collected'] ?? '-1'), 2), '⛔ মাল ফেরত আদায় হয়ে বসল: '.($r['collected'] ?? 'নেই'));
        $this->assertSame(0, bccomp('7000', (string) ($r['billed'] ?? '-1'), 2), '⛔ ফেরত বিল কমাল না: '.($r['billed'] ?? 'নেই'));
        $this->assertSame(0, bccomp('7000', (string) ($r['movement'] ?? '-1'), 2), 'নিট বদল');

        // ⓘ ফেরতটা বাতিল — বিল আবার পুরো, আদায় তখনো ০
        $engine->reverse('sales_return', 92002, now()->toDateString(), 'ভুল ফেরত');
        $r = $row();
        $this->assertSame(0, bccomp('0', (string) ($r['collected'] ?? '-1'), 2), '⛔ বাতিল ফেরতের উল্টো আদায় কাটল: '.($r['collected'] ?? 'নেই'));
        $this->assertSame(0, bccomp('10000', (string) ($r['billed'] ?? '-1'), 2), '⛔ বাতিল ফেরতে বিল ফিরল না: '.($r['billed'] ?? 'নেই'));
    }
}
