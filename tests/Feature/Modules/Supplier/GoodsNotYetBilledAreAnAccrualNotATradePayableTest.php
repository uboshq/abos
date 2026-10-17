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
use App\Modules\Supplier\Dashboard\SupplierWidgets;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ বিল-না-আসা মাল জমে থাকা দায়, ট্রেড-প্রদেয় নয় — মালিক, ১৩ অক্টোবর ২০২৬: *"int standard onuzayi koro"* (fe-র সিদ্ধান্ত;
 * Supplier নিরীক্ষা)।
 *
 * ⓘ ১,০০০-এর মাল এসেছে বিলহীন (২১৬০), আর ৫০০-র বিল (দেনা)। আগে প্রদেয়ের তালিকা, বয়স আর সেটলমেন্ট সব খাত যোগ করে ১,৫০০ "দেনা"
 * বলত — বয়সে বিলহীন মালও "মেয়াদি" হয়ে পুরনো হত। ⭐ এখন:
 *   · প্রদেয়ের তালিকা — দেনা ৫০০, পাশে আলাদা ঘরে "বিল-না-আসা মাল" ১,০০০ (দুইয়ের যোগ ঘরের অঙ্ক)।
 *   · বয়স — কেবল ৫০০।
 *   · ঘরের "কোম্পানিকে দিতে হবে" — মোট ১,৫০০, আর তার মধ্যে বিল-না-আসা ১,০০০ আলাদা নামে।
 */
final class GoodsNotYetBilledAreAnAccrualNotATradePayableTest extends TestCase
{
    use RefreshDatabase;

    public function test_goods_awaiting_their_bill_stand_apart_from_the_trade_payable(): void
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

        $engine->post(sourceType: 'journal_voucher', sourceId: 99001, trxDate: now()->subDays(3)->toDateString(), lines: [
            ['account_id' => $expense, 'debit' => '1000'],
            ['account_id' => (int) StandardChart::find(StandardChart::GOODS_RECEIVED_NOT_INVOICED)->id, 'credit' => '1000'] + $party,
        ]);
        $engine->post(sourceType: 'journal_voucher', sourceId: 99002, trxDate: now()->subDays(3)->toDateString(), lines: [
            ['account_id' => $expense, 'debit' => '500'],
            ['account_id' => (int) StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => '500'] + $party,
        ]);

        $row = fn (string $report) => (array) collect(app(ReportEngine::class)->run($report, ['to' => now()->toDateString()], 1, 100)->rows)
            ->first(fn ($r) => (int) ((array) $r)['party_id'] === $supplier->id);

        $list = $row('supplier.payable_list');
        $this->assertSame(0, bccomp('500', (string) ($list['payable'] ?? '-1'), 2), '⛔ প্রদেয়ের তালিকার দেনায় বিল-না-আসা মাল মিশল: '.($list['payable'] ?? 'নেই'));
        $this->assertSame(0, bccomp('1000', (string) ($list['not_billed'] ?? '-1'), 2), '⛔ বিল-না-আসা মাল আলাদা ঘরে নেই');

        $ageing = $row('supplier.ageing');
        $this->assertSame(0, bccomp('500', (string) ($ageing['payable'] ?? '-1'), 2), '⛔ বয়সের তালিকায় বিল-না-আসা মাল: '.($ageing['payable'] ?? 'নেই'));

        $owed = collect(SupplierWidgets::widgets())->firstWhere('label', __('supplier::widget.owed_to_principals'));
        $this->assertNotNull($owed);
        $this->assertStringContainsString('1,500', $owed->value, 'ঘরের মোট দুটোই নয়');
        $this->assertStringContainsString('1,000', (string) $owed->hint, '⛔ ঘরে বিল-না-আসা মাল আলাদা নামে নেই');
    }
}
