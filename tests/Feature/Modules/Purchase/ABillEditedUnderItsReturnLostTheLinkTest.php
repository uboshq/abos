<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseReturnLine;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ ফেরত বসা পাকা বিল সম্পাদনা করলে ফেরতের সংযোগ ছিঁড়ত (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PurchaseBillService::updatePosted()]])।
 *
 * ⚠️ সম্পাদনা বিলের লাইন মুছে নতুন বানায়; ফেরতের সারির `purchase_bill_line_id` শূন্য হয়ে যেত, আর "বিলের বেশি ফেরত নয়" যাচাই আগের
 * ফেরত আর দেখত না — একই মাল আবার ফেরত, দেনা আবার কমত। ⭐ এখন এমন বিল সম্পাদনা থামে, কারণসহ।
 */
final class ABillEditedUnderItsReturnLostTheLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_posted_bill_with_a_return_on_its_line_cannot_be_edited_and_the_link_stays(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $bills = app(PurchaseBillService::class);

        // ⓘ রসিদের বিল — বিল মাল ছোঁয় না, তাই "মাল বেরিয়ে গেছে" দেয়াল খাটে না; লাইন নতুন হলে ফেরতের সংযোগই একমাত্র পাহারা
        $receipts = app(\App\Modules\Purchase\Services\PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '50']],
        ));
        $bill = $bills->fromReceipt(\App\Modules\Purchase\Models\PurchaseReceipt::query()->with('lines.product.tax')->findOrFail($receipt->id));
        $bill = $bill->status === \App\Core\Support\DocumentStatus::DRAFT ? $bills->confirm($bill) : $bill;
        $billLine = $bill->lines()->firstOrFail();
        $head = ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => $bill->supplier_bill_no];
        $lines = [['product_id' => $product->id, 'qty' => '10', 'rate' => '50', 'purchase_receipt_line_id' => $billLine->purchase_receipt_line_id]];

        $returns = app(PurchaseReturnService::class);
        $return = $returns->confirm($returns->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '3', 'purchase_bill_line_id' => $billLine->id]],
        ));

        try {
            $bills->update($bill->fresh(), $head, $lines, repost: true);
            $this->fail('⛔ ফেরত বসা পাকা বিল সম্পাদনা হলো — ফেরতের সংযোগ ছিঁড়ল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'অন্য কারণে থামল।');
        }

        $this->assertSame((int) $billLine->id, (int) PurchaseReturnLine::query()->where('purchase_return_id', $return->id)->value('purchase_bill_line_id'),
            '⛔ ফেরতের সারি আর বিলের লাইনকে চেনে না।');
    }
}
