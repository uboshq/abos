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
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দামের ভেতরের ভ্যাটে চালান আর ফেরত ভাঙত — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * পণ্যের ভ্যাট ১৫% "দামের ভিতরে", দর ১১৫:
 * ① চালান মজুদে ১১৫ বসাত (ভ্যাটসহ), অথচ বিল ধরে মজুদ-মূল্য ১০০ + ভ্যাট ১৫ — বিলে ১৫ টাকার মিথ্যা দর-পার্থক্য।
 * ② ফেরতের দর বিলের ১১৫ (ভ্যাটসহ), তার উপর ভ্যাটের ভাগ আবার — দুই পিস ফেরতে দেনা কমত ২৬০, যেখানে ২৩০।
 */
final class VatInsideThePriceBrokeReceiptsAndReturnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_bill_and_return_at_a_vat_inclusive_rate_split_net_and_vat_like_the_bill(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('purchase.vat_enabled', true);

        $tax = Tax::query()->create([
            'company_id' => $company->id, 'code' => 'VAT15-IN-R', 'name_en' => 'VAT 15% inclusive', 'name_bn' => 'ভ্যাট ১৫% ভিতরে',
            'rate' => '15', 'kind' => 'vat', 'is_inclusive' => true, 'is_active' => true,
        ]);
        $product = Product::query()->firstOrFail();
        $product->forceFill(['tax_id' => $tax->id])->save();
        $supplier = Supplier::query()->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        // ── চালান: ১০ পিস @ ১১৫ (ভ্যাটসহ) → মজুদে ১,০০০ ──
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '115']],
        ));

        $this->assertSame(0, bccomp((string) $receipt->fresh()->total, '1000', 2),
            "⛔ চালানের মূল্য {$receipt->fresh()->total} — দামের ভেতরের ভ্যাট মজুদে ঢুকল।");

        // ── বিল: একই মাল @ ১১৫ → মোট ১,১৫০, ভ্যাট ১৫০; দর-পার্থক্য শূন্য ──
        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'VAT-IN-1'],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => '115', 'purchase_receipt_line_id' => $receipt->fresh('lines')->lines->first()->id]],
        ));

        $this->assertSame(0, bccomp((string) $bill->total, '1150', 2), 'দাবির ভিত্তি: বিলের মোট ১,১৫০ নয়।');
        $variance = Account::query()->where('code', StandardChart::PURCHASE_PRICE_VARIANCE)->value('id');
        $this->assertFalse(
            LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $bill->id)
                ->where('account_id', $variance)->exists(),
            '⛔ বিলে মিথ্যা দর-পার্থক্য — চালান মজুদে ভ্যাটসহ দাম বসিয়েছিল।',
        );

        // ── ফেরত: ২ পিস → ২০০ + ভ্যাট ৩০ = ২৩০ ──
        $returns = app(PurchaseReturnService::class);
        $return = $returns->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '2', 'purchase_bill_line_id' => $bill->lines->first()->id]],
        );

        $this->assertSame(0, bccomp((string) $return->fresh()->total, '230', 2),
            "⛔ ফেরতের মোট {$return->fresh()->total} — ভ্যাট দুইবার গোনা হলো, দেনা বেশি কমবে।");
    }
}
