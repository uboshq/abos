<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\AutoDues;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ বিলের নাম ছাড়া নেওয়া টাকা — লেজারের জের নতুন বিলে, আগেরগুলো আপনা থেকে শোধ (মালিক, ২৫ নভেম্বর ২০২৬; [[AutoDues]])।
 */
final class TheBillsFollowTheLedgerByThemselvesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_balance_rests_on_the_newest_bills(): void
    {
        $bills = [['id' => 3, 'due' => '10000'], ['id' => 2, 'due' => '10000'], ['id' => 1, 'due' => '10000']];

        $this->assertSame([2 => '10000.0000', 1 => '10000.0000', 3 => '5000.0000'], self::byId(AutoDues::spread('5000', $bills), [2, 1, 3]));
        $this->assertSame([], AutoDues::spread('30000', $bills));
        // ⓘ জের বিলের চেয়ে বেশি (শুরুর জের) — কোনো বিল শোধ নয়
        $this->assertSame([], AutoDues::spread('99999', $bills));
        // ⓘ অগ্রিম — সব শোধ
        $this->assertCount(3, AutoDues::spread('-50', $bills));
    }

    /** ⭐ আসল পথে: খাতায় টাকা বসলেই বিলের বাকি কমে, খাতার সাথে মেলে; কোনো রসিদ-বিলের সারি লেখা হয় না */
    public function test_a_receipt_without_a_bill_settles_the_oldest_by_itself(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $customer = Customer::query()->create(['company_id' => $company->id, 'code' => 'AUTO-1', 'name_en' => 'Auto Dealer', 'is_active' => true,
            'branch_id' => $company->defaultBranch()?->id]);
        $bill = function (int $n) use ($company, $customer): SalesInvoice {
            $invoice = (new SalesInvoice)->forceFill([
                'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'customer_id' => $customer->id,
                'document_no' => 'AUTO-'.$n, 'trx_date' => now()->subDays(10 - $n)->toDateString(), 'total' => '10000', 'status' => 'confirmed',
            ]);
            $invoice->saveQuietly();
            app(PostingEngine::class)->post(sourceType: 'sales_invoice', sourceId: (int) $invoice->id, trxDate: now()->subDays(10 - $n), lines: [
                ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '10000', 'party_type' => 'customer', 'party_id' => $customer->id],
                ['account_id' => (int) StandardChart::find(StandardChart::SALES)->id, 'credit' => '10000'],
            ]);

            return $invoice;
        };
        [$one, $two, $three] = [$bill(1), $bill(2), $bill(3)];
        $due = fn (SalesInvoice $i) => SalesInvoice::query()->withoutGlobalScopes()->findOrFail($i->id)->dueAmount();
        $this->assertSame(['10000.0000', '10000.0000', '10000.0000'], [$due($one), $due($two), $due($three)], 'প্রস্তুতিটাই ভুল');

        app(PostingEngine::class)->post(sourceType: 'test.money', sourceId: 1, trxDate: now(), lines: [
            ['account_id' => (int) Account::query()->postable()->where('money_kind', Account::CASH)->firstOrFail()->id, 'debit' => '25000'],
            ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '25000', 'party_type' => 'customer', 'party_id' => $customer->id],
        ]);

        $this->assertSame(['0.0000', '0.0000', '5000.0000'], [$due($one), $due($two), $due($three)], '⛔ নাম-ছাড়া টাকা পুরোনো বিল শোধ করল না');
        $sum = SalesInvoice::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->withCollected()->get()
            ->reduce(fn (string $s, SalesInvoice $i) => bcadd($s, $i->dueAmount(), 4), '0');
        $balance = (string) DB::table('ledger_entries')->where('company_id', $company->id)->where('party_type', 'customer')->where('party_id', $customer->id)
            ->selectRaw('SUM(debit - credit) as b')->value('b');
        $this->assertSame(0, bccomp($sum, $balance, 4), '⛔ বিলের বাকির যোগ খাতার জেরের সমান নয়');
        $this->assertSame(0, DB::table('acc_voucher_allocations')->count(), '⛔ রসিদ-বিলের সারি লেখা হলো — অটো বাকি কেবল গোনা');
        // ⓘ আসল আদায় নড়ে না — বাতিলের দেয়াল একেই দেখে
        $this->assertSame(0, bccomp($one->fresh()->collectedAmount(), '0', 4));
    }

    /** @param  array<int, string>  $a  @param  list<int>  $order  @return array<int, string> */
    private static function byId(array $a, array $order): array
    {
        $out = [];

        foreach ($order as $id) {
            $out[$id] = $a[$id] ?? '0';
        }

        return $out;
    }
}
