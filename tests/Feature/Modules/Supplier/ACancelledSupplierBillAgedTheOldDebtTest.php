<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ সরবরাহকারীর দেনার বয়সে বাতিল বিল পুরনো দেনা "শোধ" করত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::ageing()]])।
 *
 * ⚠️ ৯৫ দিনের ১ লাখ দেনা; কাল ৫০ হাজারের বিল, আজ বাতিল। উল্টো ডেবিট পরিশোধ হয়ে ৯০+ থেকে কাটত, আর মূল বিলটা চলতিতে থাকত।
 * ⭐ এখন মূল আর উল্টো জোড়া ধরে বাদ: ৯০+ = ১ লাখ, চলতি ০।
 */
final class ACancelledSupplierBillAgedTheOldDebtTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cancelled_new_bill_leaves_the_old_debt_in_its_own_bucket(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        DB::table('ledger_entries')->where('party_type', Supplier::drillSourceType())->delete();

        $supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $party = ['party_type' => Supplier::drillSourceType(), 'party_id' => $supplier->id];
        $expense = (int) Account::query()->postable()->active()->where('type', Account::EXPENSE)->value('id');
        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $bill = fn (int $id, string $amount, int $daysAgo) => app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: $id,
            trxDate: now()->subDays($daysAgo)->toDateString(),
            lines: [['account_id' => $expense, 'debit' => $amount], ['account_id' => $payable, 'credit' => $amount] + $party]);

        $bill(94001, '100000', 95);
        $bill(94002, '50000', 1);
        app(PostingEngine::class)->reverse('journal_voucher', 94002, now()->toDateString(), 'ভুল বিল');

        $result = app(ReportEngine::class)->run('supplier.ageing', ['to' => now()->toDateString()], 1, 100);
        $row = (array) collect($result->rows)->first(fn ($r) => (int) ((array) $r)['party_id'] === $supplier->id);

        $this->assertSame(0, bccomp('100000', (string) ($row['bucket_90'] ?? '-1'), 2), '⛔ বাতিল বিল ৯০+ দিনের দেনা কমাল: '.($row['bucket_90'] ?? 'নেই'));
        $this->assertSame(0, bccomp('0', (string) ($row['bucket_current'] ?? '-1'), 2), '⛔ বাতিল বিল চলতি বালতিতে রয়ে গেল: '.($row['bucket_current'] ?? 'নেই'));
        $this->assertSame(0, bccomp('100000', (string) ($row['payable'] ?? '-1'), 2), 'মোট দেনা');
    }
}
