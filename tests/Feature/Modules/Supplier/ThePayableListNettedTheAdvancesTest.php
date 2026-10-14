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
 * ⛔ প্রদেয়ের তালিকা আর ঘরের "কোম্পানিকে দিতে হবে" অগ্রিম দিয়ে দেনা কাটত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; IAS 1 ¶৩২)।
 *
 * ⚠️ ক-কে ১,০০০ দেনা, খ-কে ৬০০ অগ্রিম দেওয়া: তালিকায় খ "-৬০০" দেনা, মোট ৪০০; ঘরেও ৪০০। আর গ-কে ৭০০ অগ্রিম আর তার
 * ৭০০-র মাল বিলহীন — দুই ঘরের যোগ ০, তাই সারিটাই হারাত। ⭐ এখন দেনা, বিল-না-আসা মাল আর অগ্রিম তিন ঘরে; ঘর = দেনা + বিল-না-আসা।
 * গ্রাহকের বকেয়ার তালিকার একই নিয়ম ([[TheDueListNettedTheAdvancesTest]])।
 */
final class ThePayableListNettedTheAdvancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_advance_to_one_principal_never_cuts_another_principals_debt(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        DB::table('ledger_entries')->where('party_type', Supplier::drillSourceType())->delete();

        [$owed, $ahead, $waiting] = Supplier::query()->onlySuppliers()->orderBy('id')->take(3)->get()->all();
        $this->assertNotNull($waiting, 'তিনজন প্রিন্সিপাল লাগে');

        $expense = (int) Account::query()->postable()->active()->where('type', Account::EXPENSE)->value('id');
        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $grni = (int) StandardChart::find(StandardChart::GOODS_RECEIVED_NOT_INVOICED)->id;
        $party = fn (Supplier $s) => ['party_type' => Supplier::drillSourceType(), 'party_id' => $s->id];
        $post = fn (int $id, array $lines) => app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: $id,
            trxDate: now()->subDays(2)->toDateString(), lines: $lines);

        $post(99101, [['account_id' => $expense, 'debit' => '1000'], ['account_id' => $payable, 'credit' => '1000'] + $party($owed)]);
        $post(99102, [['account_id' => $payable, 'debit' => '600'] + $party($ahead), ['account_id' => $expense, 'credit' => '600']]);
        $post(99103, [['account_id' => $payable, 'debit' => '700'] + $party($waiting), ['account_id' => $expense, 'credit' => '700']]);
        $post(99104, [['account_id' => $expense, 'debit' => '700'], ['account_id' => $grni, 'credit' => '700'] + $party($waiting)]);

        $result = app(ReportEngine::class)->run('supplier.payable_list', ['to' => now()->toDateString()], 1, 100);
        $rows = collect($result->rows)->map(fn ($r) => (array) $r)->keyBy(fn (array $r) => (int) $r['party_id']);

        $this->assertTrue($rows->has($waiting->id), '⛔ অগ্রিম আর বিল-না-আসা মাল সমান — সারিটাই হারাল');
        $this->assertSame(0, bccomp('1000', (string) $result->totals['payable'], 2), '⛔ তালিকার দেনার মোট অগ্রিমে কাটা: '.$result->totals['payable']);
        $this->assertSame(0, bccomp('0', (string) $rows[$ahead->id]['payable'], 2), '⛔ অগ্রিম ঋণাত্মক দেনা হয়ে বসল');
        $this->assertSame(0, bccomp('600', (string) $rows[$ahead->id]['advance'], 2));
        $this->assertSame(0, bccomp('700', (string) $rows[$waiting->id]['advance'], 2));
        $this->assertSame(0, bccomp('700', (string) $rows[$waiting->id]['not_billed'], 2));

        // ⓘ ঘর = দেনা ১,০০০ + বিল-না-আসা ৭০০ — অগ্রিম কাটে না
        $tile = collect(SupplierWidgets::widgets())->firstWhere('label', __('supplier::widget.owed_to_principals'));
        $this->assertNotNull($tile);
        $this->assertStringContainsString('1,700', $tile->value, '⛔ ঘরের অঙ্ক অগ্রিমে কাটা: '.$tile->value);
    }
}
