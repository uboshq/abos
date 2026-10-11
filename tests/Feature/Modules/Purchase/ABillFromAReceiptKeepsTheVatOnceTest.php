<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ভ্যাট-ভিতরের চালান থেকে বানানো বিল ভ্যাট দুইবার বের করত (cb-র রিভিউ, cloud/purchase-fixes মেশানোর আগে, ১০ অক্টোবর ২০২৬)।
 *
 * চালান ১০ পিস @ ১১৫ (ভ্যাট ১৫% ভিতরে) → চালানের দর ১০০, মূল্য ১,০০০। বিল দুই পথে — এক চাপে চালান থেকে
 * ([[PurchaseBillService::fromReceipt()]]) আর বিলের পর্দা চালান থেকে খুললে (ফর্মের বীজ) — দুটোতেই দর ১১৫ যায়, তাই বিলের
 * মোট ১,১৫০, ভ্যাট ১৫০, দর-পার্থক্য নেই। ⚠️ আগে দর ১০০ যেত `tax` খালি রেখে: মোট ১,০০০, ভ্যাট ১৩০.৪৩, আর মিথ্যা পার্থক্য।
 */
final class ABillFromAReceiptKeepsTheVatOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_ways_of_billing_a_receipt_take_the_vat_out_once(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('purchase.vat_enabled', true);

        $tax = Tax::query()->create([
            'company_id' => $company->id, 'code' => 'VAT15-IN-B', 'name_en' => 'VAT 15% inclusive', 'name_bn' => 'ভ্যাট ১৫% ভিতরে',
            'rate' => '15', 'kind' => 'vat', 'is_inclusive' => true, 'is_active' => true,
        ]);
        $product = Product::query()->firstOrFail();
        $product->forceFill(['tax_id' => $tax->id])->save();
        $supplier = Supplier::query()->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $receipts = app(PurchaseReceiptService::class);
        $receive = fn () => $receipts->confirm($receipts->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '115']],
        ))->fresh('lines.product.tax');

        // ① এক চাপে চালান থেকে বিল
        $bill = app(PurchaseBillService::class)->fromReceipt($receive());
        $this->assertNotNull($bill, 'প্রস্তুতিটাই ভুল — চালান থেকে বিল হলো না।');
        $bill = $bill->status === 'draft' ? app(PurchaseBillService::class)->confirm($bill) : $bill;
        $this->assertSame(0, bccomp((string) $bill->fresh()->total, '1150', 2), "⛔ চালানের বিলের মোট {$bill->fresh()->total} — ভ্যাট দুইবার বের হলো, দেনা কম।");
        $variance = Account::query()->where('code', StandardChart::PURCHASE_PRICE_VARIANCE)->value('id');
        $this->assertFalse(LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $bill->id)
            ->where('account_id', $variance)->exists(), '⛔ চালানের বিলে মিথ্যা দর-পার্থক্য।');

        // ② বিলের পর্দা চালান থেকে খুললে — বীজের দর ভ্যাটসহ
        $second = $receive();
        $this->assertSame('100.0000', (string) $second->lines->first()->rate, 'প্রস্তুতিটাই ভুল — চালানের দর নিট নয়।');
        $html = (string) $this->get(route('purchase.bill.create', ['purchase_receipt_id' => $second->id]))->assertOk()->getContent();
        $this->assertStringContainsString('115.0000', $html, '⛔ পর্দার বীজে চালানের নিট দর গেল — বিল আবার ভ্যাট বের করবে।');

        // ③ লাইভের পুরনো চালান — দর আগেই ভ্যাটসহ ১১৫, সারির ভ্যাট শূন্য: বিলের দর ১১৫-ই, পণ্যের হার দিয়ে ফুলে ১৩২.২৫ নয়
        $old = $receipts->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '115']],
        );
        \Illuminate\Support\Facades\DB::table('pur_receipt_lines')->where('purchase_receipt_id', $old->id)
            ->update(['rate' => '115', 'amount' => '1150', 'tax' => '0']);
        $oldBill = app(PurchaseBillService::class)->fromReceipt($receipts->confirm($old->fresh())->fresh('lines.product.tax'));
        $this->assertNotNull($oldBill);
        $this->assertSame(0, bccomp((string) $oldBill->fresh()->total, '1150', 2),
            "⛔ পুরনো চালানের বিলের মোট {$oldBill->fresh()->total}, ১,১৫০ নয় — দর ফুলে গেল।");
    }
}
