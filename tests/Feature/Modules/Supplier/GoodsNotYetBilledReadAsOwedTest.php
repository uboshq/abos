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
 * ⛔ বিল-না-আসা মাল প্রদেয়ের তালিকায় "দেনা" হয়ে বসত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::payableList()]])।
 *
 * ⚠️ মাল এলে রসিদ ২১৬০-এ সরবরাহকারীর নামে ক্রেডিট বসায়; বিল এলে তবে দেনা (২১১০)। সরবরাহকারীর পাতা ১০ অক্টোবর থেকে দুটো
 * আলাদা দেখায়, কিন্তু প্রদেয়ের তালিকা, বয়সের তালিকা আর ঘরের "মূল কোম্পানির কাছে দেনা" সব খাত যোগ করত — ১,০০০-এর মাল
 * বিলহীন আর ৫০০-র বিল থাকলে বলত ১,৫০০ দিতে হবে, আর ওই তালিকা দেখে টাকা দিলে বেশি যেত।
 */
final class GoodsNotYetBilledReadAsOwedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_payable_list_and_the_ageing_count_only_the_payable(): void
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
        $engine = app(PostingEngine::class);

        // ⓘ ১,০০০-এর মাল এসেছে, বিল আসেনি (২১৬০); আর একটা ৫০০-র বিল (দেনা)
        $engine->post(sourceType: 'journal_voucher', sourceId: 93001, trxDate: now()->subDays(3)->toDateString(), lines: [
            ['account_id' => $expense, 'debit' => '1000'],
            ['account_id' => (int) StandardChart::find(StandardChart::GOODS_RECEIVED_NOT_INVOICED)->id, 'credit' => '1000'] + $party,
        ]);
        $engine->post(sourceType: 'journal_voucher', sourceId: 93002, trxDate: now()->subDays(3)->toDateString(), lines: [
            ['account_id' => $expense, 'debit' => '500'],
            ['account_id' => (int) StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => '500'] + $party,
        ]);

        $this->assertSame(0, bccomp('500', $supplier->payable(), 2), 'দৃশ্যটাই বানানো যায়নি — সরবরাহকারীর পাতার দেনা ৫০০ নয়');

        foreach (['supplier.payable_list', 'supplier.ageing'] as $report) {
            $result = app(ReportEngine::class)->run($report, ['to' => now()->toDateString()], 1, 100);
            $row = (array) collect($result->rows)->first(fn ($r) => (int) ((array) $r)['party_id'] === $supplier->id);

            $this->assertSame(0, bccomp('500', (string) ($row['payable'] ?? '-1'), 2),
                "⛔ {$report}: বিল-না-আসা মাল দেনায় মিশল — ".($row['payable'] ?? 'নেই'));
        }
    }
}
