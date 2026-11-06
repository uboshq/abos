<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ চেকলিস্ট ভা-১৩ — খতিয়ানের প্রতিটা লাইনের বিবরণে পক্ষ, সরবরাহকারীর বিল নম্বর আর মাল গ্রহণ (GRN) (৭ নভেম্বর ২০২৬;
 * SAP-এর line item text আর reference, Odoo-র move line label)।
 *
 * ⛔ আগে লাইনে কেবল আমাদের বিল নম্বর ("BILL-0007 — মাল গুদামে ঢুকল") — কার বিল, তাঁর কোন বিল, কোন চালানের মাল, জানতে কাগজ খুলতে হত।
 */
final class ABillLineSaysWhoseBillAndWhichGoodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_line_of_a_posted_bill_names_the_supplier_their_bill_and_the_goods_receipt(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $supplier = Supplier::query()->firstOrFail();
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'received_qty' => '4', 'rate' => '250']],
        ))->fresh('lines');

        $bills = app(PurchaseBillService::class);
        $bill = $bills->fromReceipt($receipt);
        $this->assertNotNull($bill, 'প্রস্তুতিটাই ভুল — চালান থেকে বিল হলো না।');
        $bill->forceFill(['supplier_bill_no' => 'SUP-INV-7781'])->save();
        $bill = $bill->status === 'draft' ? $bills->confirm($bill->fresh()) : $bill;

        $lines = LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $bill->id)->pluck('narration');
        $this->assertNotEmpty($lines, 'প্রস্তুতিটাই ভুল — বিল খাতায় বসেনি।');

        foreach ($lines as $narration) {
            $this->assertStringContainsString((string) $bill->document_no, (string) $narration, '⛔ খাতার লাইনে আমাদের বিল নম্বর হারাল।');
            $this->assertStringContainsString($supplier->name(), (string) $narration, '⛔ খাতার লাইনে সরবরাহকারীর নাম নেই।');
            $this->assertStringContainsString('SUP-INV-7781', (string) $narration, '⛔ খাতার লাইনে সরবরাহকারীর নিজের বিল নম্বর নেই।');
            $this->assertStringContainsString((string) $receipt->document_no, (string) $narration, '⛔ খাতার লাইনে মাল গ্রহণের (GRN) নম্বর নেই।');
        }
    }
}
