<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ক্রয়ের বিলে উৎসে কর্তনের জায়গা ছিল না — পুরো বিল সরবরাহকারীর দেনা হয়ে বসত (হিসাব #১, গুরুতর, ২০ অক্টোবর ২০২৬; fe;
 * আয়কর আইন ২০২৩ ধারা ৮৯, মূসক ও SD আইন ২০১২ ধারা ৪৯; [[WithheldTax]])।
 *
 * দাবি (বিলের ফর্ম আর পাকা করার বোতাম দিয়ে, খতিয়ানের অঙ্কে):
 *  - ১০ × ১,০০০ = ১০,০০০ টাকার সরাসরি বিল, AIT ৫০০, VDS ৫০০: মজুদ Dr ১০,০০০ (মালের দাম মোটে — IAS 2, IAS 1 ¶৩২) ·
 *    দেনা Cr ৯,০০০ সরবরাহকারীর নামে · ২১২১ Cr ৫০০ + ৫০০ আলাদা সারিতে; খাতা মেলে;
 *  - বিলের বাকি ৯,০০০ (মোট নয়), আর "বাকি আছে" ছাঁকনিতে একই; বিলের পাতায় কর্তন আর নিট পাওনা;
 *  - কর্তন মোটের বেশি হলে পাকা হয় না, খতিয়ানে কিছু বসে না;
 *  - কর্তন ছাড়া বিল আগের মতো — দেনা মোটে।
 */
final class TheTaxHeldBackOnABillWasOwedToTheSupplierTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    public function test_the_goods_are_booked_gross_the_supplier_is_owed_net_and_the_withheld_tax_is_the_governments(): void
    {
        $bill = $this->confirmedBill(['ait_amount' => '500', 'vds_amount' => '500']);
        $book = $this->ledgerOf($bill);

        $this->assertMoved($book, StandardChart::INVENTORY, debit: '10000', message: '⛔ মালের দাম মোটে বসেনি');
        $this->assertMoved($book, StandardChart::PAYABLE, credit: '9000', message: '⛔ সরবরাহকারীর দেনা নিট নয় — কাটা কর তাঁর পাওনা দেখাচ্ছে');
        $this->assertMoved($book, StandardChart::TAX_DEDUCTED_AT_SOURCE, credit: '1000', message: '⛔ কেটে রাখা কর সরকারের দায়ে বসেনি');
        $this->assertSame(2, LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $bill->id)
            ->where('account_id', StandardChart::find(StandardChart::TAX_DEDUCTED_AT_SOURCE)->id)->count(), 'AIT আর VDS আলাদা সারিতে নেই');
        $this->assertSame((int) $this->supplier->id, (int) LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())
            ->where('source_id', $bill->id)->where('account_id', StandardChart::find(StandardChart::PAYABLE)->id)->value('party_id'));
        $this->assertBalanced($bill);

        // ⓘ বাকি নিট — বিলে আর তালিকার ছাঁকনিতে একই
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '9000', 4), '⛔ বিলের বাকি কাটা অংশসহ দেখাচ্ছে');
        $listed = PurchaseBill::query()->whereKey($bill->id)->stillOwed()->withPaid()->first();
        $this->assertNotNull($listed);
        $this->assertSame(0, bccomp(bcsub(bcsub((string) $listed->total, $listed->withheldAmount(), 4), (string) ($listed->paid_total ?? 0), 4), '9000', 4));

        $this->get(route('purchase.bill.show', $bill))->assertOk()
            ->assertSee(__('purchase::field.ait_amount'))
            ->assertSee(__('purchase::field.payable_net'))
            ->assertSee('9,000.00');
    }

    public function test_withholding_above_the_bill_total_is_refused_and_nothing_posts(): void
    {
        $entries = LedgerEntry::query()->count();
        $bill = $this->draftBill(['ait_amount' => '9000', 'vds_amount' => '2000']);

        $this->post(route('purchase.bill.confirm', $bill))->assertSessionHasErrors('ait_amount');

        $this->assertNotSame(DocumentStatus::CONFIRMED, $bill->fresh()->status);
        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ মোটের বেশি কর্তনের বিল খতিয়ানে বসল');
    }

    public function test_without_withholding_the_supplier_is_owed_the_whole_bill_as_before(): void
    {
        $bill = $this->confirmedBill([]);
        $book = $this->ledgerOf($bill);

        $this->assertMoved($book, StandardChart::PAYABLE, credit: '10000');
        $this->assertArrayNotHasKey((int) StandardChart::find(StandardChart::TAX_DEDUCTED_AT_SOURCE)->id, $book);
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '10000', 4));
    }

    // ── প্রস্তুতি ──

    /** @param array<string, string> $withheld */
    private function draftBill(array $withheld): PurchaseBill
    {
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();

        $this->post(route('purchase.bill.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'AIT-'.random_int(10000, 99999),
            'lines' => [['product_id' => $product->id, 'qty' => '10', 'rate' => '1000']],
            ...$withheld,
        ])->assertSessionHasNoErrors();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();
        $this->assertSame(0, bccomp((string) $bill->total, '10000', 4), 'পরীক্ষার ছক: বিলের মোট ১০,০০০ নয়');

        return $bill;
    }

    /** @param array<string, string> $withheld */
    private function confirmedBill(array $withheld): PurchaseBill
    {
        $bill = $this->draftBill($withheld);
        $this->post(route('purchase.bill.confirm', $bill))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'বিলটা পাকা হলো না');

        return $bill->fresh();
    }

    /** @return array<int, array{debit: string, credit: string}> */
    private function ledgerOf(PurchaseBill $bill): array
    {
        $book = [];

        LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $bill->id)->get()
            ->each(function (LedgerEntry $entry) use (&$book): void {
                $id = (int) $entry->account_id;
                $book[$id] ??= ['debit' => '0', 'credit' => '0'];
                $book[$id]['debit'] = bcadd($book[$id]['debit'], (string) $entry->debit, 4);
                $book[$id]['credit'] = bcadd($book[$id]['credit'], (string) $entry->credit, 4);
            });

        return $book;
    }

    /** @param array<int, array{debit: string, credit: string}> $book */
    private function assertMoved(array $book, string $code, string $debit = '0', string $credit = '0', string $message = ''): void
    {
        $account = StandardChart::find($code) ?? $this->fail("খাত {$code} ছকে নেই");
        $row = $book[(int) $account->id] ?? null;

        $this->assertNotNull($row, trim($message.' — খাত '.$code.' খতিয়ানে নড়েইনি: '.json_encode($book)));
        $this->assertSame(0, bccomp($row['debit'], $debit, 4), trim($message." — {$code} ডেবিট {$row['debit']}, চাওয়া {$debit}"));
        $this->assertSame(0, bccomp($row['credit'], $credit, 4), trim($message." — {$code} ক্রেডিট {$row['credit']}, চাওয়া {$credit}"));
    }

    private function assertBalanced(PurchaseBill $bill): void
    {
        $row = LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $bill->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        $this->assertSame(0, bccomp((string) $row->d, (string) $row->c, 4), "⛔ খতিয়ান মেলেনি: ডেবিট {$row->d}, ক্রেডিট {$row->c}।");
    }
}
