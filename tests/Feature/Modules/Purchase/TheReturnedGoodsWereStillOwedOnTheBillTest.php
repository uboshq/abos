<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ফেরত দেওয়া মালের টাকাও বিলে বাকি দেখাত — পুরো ERP অডিট, ক্রয় ⚠️৬, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ নিশ্চিত ফেরত খাতায় দেনা কমাত, অথচ বিলের বাকি (একক পাতা, তালিকা, পরিশোধের ভাগ, বাকির রিপোর্ট) কেবল মোট − পরিশোধ —
 * ফেরত দেওয়া মালের টাকাও পুরো পরিশোধ করা যেত। ⭐ এখন বিলে বাঁধা পাকা ফেরত বাকি থেকে বাদ, দুই পথেই একই অঙ্ক।
 */
final class TheReturnedGoodsWereStillOwedOnTheBillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_confirmed_return_comes_off_what_the_bill_still_owes(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();

        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'RET-'.fake()->unique()->numberBetween(10000, 99999),
        ], [['product_id' => $product->id, 'qty' => '10', 'rate' => '60', 'sales_price' => '60', 'tax' => '0']])['bill'];
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '600', 4), 'প্রস্তুতি: বিল ৬০০ বাকি নয়।');

        $returns = app(PurchaseReturnService::class);
        $draft = $returns->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '3', 'purchase_bill_line_id' => $bill->lines()->firstOrFail()->id]],
        );

        // ⓘ খসড়া ফেরত বাকি বদলায় না
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '600', 4), '⛔ খসড়া ফেরতেই বাকি কমল।');

        $returns->confirm($draft->fresh());

        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '420', 4), '⛔ পাকা ফেরতের ১৮০ বিলের বাকি থেকে কমেনি।');

        $listed = PurchaseBill::query()->whereKey($bill->id)->withPaid()->firstOrFail();
        $this->assertSame(0, bccomp($listed->dueAmount(), '420', 4), '⛔ তালিকার পথে বাকি আলাদা।');
        $this->assertSame(0, bccomp((string) $listed->getAttribute('returned_total'), '180', 4));

        // ⓘ বাকির রিপোর্টও একই অঙ্ক — সরবরাহকারীর অন্য বিল না থাকলে মোট বাকি ঠিক এই বিলের
        $before = PurchaseBill::query()->posted()->where('supplier_id', $supplier->id)->whereKeyNot($bill->id)->withPaid()->get()
            ->reduce(fn (string $sum, PurchaseBill $b) => bcadd($sum, $b->dueAmount(), 4), '0');
        $row = collect(app(\App\Core\Engines\Report\ReportEngine::class)->run(\App\Modules\Purchase\Reports\PaymentDueReport::KEY,
            ['supplier_id' => (string) $supplier->id], perPage: 500)->rows)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp(bcsub((string) data_get($row, 'total_due'), $before, 4), '420', 4), '⛔ বাকির রিপোর্টে ফেরত বাদ যায়নি।');
    }
}
