<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ভ্যাট বন্ধের পরে পুরনো ভ্যাট-বিলের ফেরত ভ্যাট হারাত (fe, ১৩ অক্টোবর ২০২৬; [[PurchaseReturnService::returnTax()]])।
 *
 * ⚠️ ফেরতের দর বিলের দর থেকে ভ্যাট বাদ দিয়েই নেয়, অথচ সুইচ বন্ধ হলে ফেরতের ভ্যাট জোর করে ০ — ১১৫-র বিলের ২টা ফেরতে দেনা কমত ২০০,
 * ২৩০ নয়; ৩০ টাকার উপকরণ-ভ্যাট আর কখনো উল্টাত না। ⭐ এখন বিলে ভ্যাট থাকলে ফেরত বিলের ভাগটাই নেয়।
 */
final class AReturnLostItsBillsVatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_return_after_vat_is_switched_off_still_takes_back_the_bills_own_vat(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('purchase.vat_enabled', true);

        $tax = Tax::query()->create(['company_id' => $company->id, 'code' => 'VAT15-IN-OFF', 'name_en' => 'VAT 15% inclusive',
            'name_bn' => 'ভ্যাট ১৫% ভিতরে', 'rate' => '15', 'kind' => 'vat', 'is_inclusive' => true, 'is_active' => true]);
        $product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
        $product->forceFill(['tax_id' => $tax->id])->save();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'VOFF-1'],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => '115']],
        ));
        $this->assertSame(0, bccomp((string) $bill->total, '1150', 2), 'দৃশ্যটাই বানানো যায়নি — বিল ১,১৫০ নয়');

        app(SettingsService::class)->set('purchase.vat_enabled', false);
        app(SettingsService::class)->flush();

        $returns = app(PurchaseReturnService::class);
        $return = $returns->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '2', 'purchase_bill_line_id' => $bill->lines()->firstOrFail()->id]],
        );

        $this->assertSame(0, bccomp((string) $return->fresh()->tax, '30', 2), "⛔ ভ্যাট বন্ধের পরে ফেরতের ভ্যাট {$return->fresh()->tax}, বিলের ভাগ ৩০ নয়।");
        $this->assertSame(0, bccomp((string) $return->fresh()->total, '230', 2), '⛔ ফেরতে দেনা কমছে ২৩০ নয় — বিলের ভ্যাট হারাল।');
    }
}
