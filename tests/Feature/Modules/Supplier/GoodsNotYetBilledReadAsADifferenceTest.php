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
 * ⛔ খাতা-মিলানোর রিপোর্টে বিল-না-আসা মাল "অমিল" হয়ে বসত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[LedgerCheckReports]])।
 *
 * ⚠️ রিপোর্টের কাজ সরবরাহকারীর নামে ভুল খাতে বসা টাকা ধরা। ২১৬০-এ রসিদের ক্রেডিট ভুল নয় — তবু "সরবরাহকারীর খাতায়" ঘরে যোগ হয়ে
 * প্রতিটা অপেক্ষার রসিদ পার্থক্য দেখাত। ⭐ এখন ৫০০-র বিল আর ১,০০০-এর বিলহীন মালে পার্থক্য ০।
 */
final class GoodsNotYetBilledReadAsADifferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_goods_awaiting_their_bill_are_not_a_difference(): void
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

        $engine->post(sourceType: 'journal_voucher', sourceId: 96001, trxDate: now()->toDateString(), lines: [
            ['account_id' => $expense, 'debit' => '1000'],
            ['account_id' => (int) StandardChart::find(StandardChart::GOODS_RECEIVED_NOT_INVOICED)->id, 'credit' => '1000'] + $party,
        ]);
        $engine->post(sourceType: 'journal_voucher', sourceId: 96002, trxDate: now()->toDateString(), lines: [
            ['account_id' => $expense, 'debit' => '500'],
            ['account_id' => (int) StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => '500'] + $party,
        ]);

        $result = app(ReportEngine::class)->run('supplier.ledger_check', ['to' => now()->toDateString()], 1, 100);
        $row = (array) collect($result->rows)->first(fn ($r) => (int) (((array) $r)['party_id'] ?? 0) === $supplier->id);

        $this->assertSame(0, bccomp('500', (string) ($row['party_total'] ?? '-1'), 2), '⛔ বিল-না-আসা মাল সরবরাহকারীর খাতায় যোগ হল: '.($row['party_total'] ?? 'নেই'));
        $this->assertSame(0, bccomp('0', (string) ($row['difference'] ?? '-1'), 2), '⛔ বিল-না-আসা মাল অমিল হয়ে বসল: '.($row['difference'] ?? 'নেই'));
    }
}
