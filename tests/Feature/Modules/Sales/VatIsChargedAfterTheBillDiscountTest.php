<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ ভ্যাট বিলের ছাড়ের পরে — মালিক, ১০ অক্টোবর ২০২৬: *"ছাড় বাদ দিয়ে যে দাম, তার উপর ভ্যাট"*
 * ([[SalesInvoiceService::vatAfterBillDiscount()]])।
 *
 * ⛔ আগে ভ্যাট বসত ছাড়ের আগের দামে: ১,০০০ টাকার মালে ১০০ ছাড় দিলেও ভ্যাট ১৫০, ক্রেতা দিতেন ১,০৫০।
 * এখন ৯০০-র উপর ১৫% = ১৩৫, মোট ১,০৩৫ — বিলের মাথায় আর সারিতেও (ফেরত সারির ভ্যাট পড়ে)।
 */
final class VatIsChargedAfterTheBillDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set('sales.vat_enabled', true);
    }

    public function test_vat_outside_the_price_is_charged_on_the_price_after_the_bill_discount(): void
    {
        $bill = $this->bill('VAB-EX', false, '100', '100');

        $this->assertSame('135.0000', (string) $bill->tax, '⛔ ভ্যাট ছাড়ের আগের দামে বসেছে');
        $this->assertSame('1035.0000', (string) $bill->total, '⛔ ক্রেতা ছাড়ের অংশেরও ভ্যাট দিচ্ছেন');
        $this->assertSame('135.0000', (string) $bill->lines->first()->tax, '⛔ সারির ভ্যাট বিলের সাথে মেলেনি — ফেরতে ভুল ভ্যাট ফিরত');
        $this->assertSame('1135.0000', (string) $bill->lines->first()->amount, 'সারিতে ছাড়ের আগের দাম + কমা ভ্যাট');
    }

    public function test_vat_inside_the_price_shrinks_with_the_bill_discount_and_the_total_falls_by_the_discount_only(): void
    {
        $bill = $this->bill('VAB-IN', true, '115', '115');

        $this->assertSame('135.0000', (string) $bill->tax, '⛔ দামের ভিতরের ভ্যাট ছাড়ের ভাগে কমেনি');
        $this->assertSame('1035.0000', (string) $bill->total);
        $this->assertSame('135.0000', (string) $bill->lines->first()->tax);
    }

    /**
     * ⛔ বাক্সে বেচা, ভ্যাট দামের ভিতরে — মোট কেবল ছাড়টুকুই কমে (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⛔৪)।
     *
     * ⓘ ১ বক্স (১২ পিস) ১০০০ টাকা → পিসের দর ৮৩.৩৩৩৩৩৩, ঘরে বসে ৮৩.৩৩৩৩। আগে "ভিতরে না বাইরে" ঠিক হত সংরক্ষিত দর দিয়ে আবার
     * গুনে, আর ১২ × ৮৩.৩৩৩৩ < amount হওয়ায় সারিটা "বাইরে" ধরা পড়ত — ১০০ টাকা ছাড়ে মোট ৯০০ না হয়ে ~৮৮৬.৯৬।
     */
    public function test_a_boxed_line_with_vat_inside_still_falls_by_the_discount_only(): void
    {
        $tax = Tax::query()->create(['code' => 'VAB-BOX', 'name_en' => 'VAB-BOX', 'name_bn' => 'VAB-BOX', 'rate' => '15', 'kind' => 'vat',
            'is_inclusive' => true, 'is_active' => true]);
        $piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $box = Unit::query()->create(['code' => 'VBOX12', 'name_en' => 'Box', 'name_bn' => 'বাক্স', 'base_unit_id' => $piece->id, 'factor' => '12', 'is_active' => true]);
        $product = app(ProductService::class)->create(['code' => 'VAB-BOX', 'name_en' => 'VAB-BOX', 'name_bn' => 'VAB-BOX',
            'unit_id' => $piece->id, 'purchase_price' => '50', 'sale_price' => '84', 'tax_id' => $tax->id]);

        $invoice = app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'bill_discount' => '100',
        ], [['product_id' => $product->id, 'qty' => '1', 'rate' => '1000', 'unit_id' => $box->id]]);

        $bill = SalesInvoice::query()->with('lines')->findOrFail($invoice->id);

        $this->assertLessThan(0.01, abs((float) bcsub((string) $bill->subtotal, '1000', 4)), "ⓘ দৃশ্যটা বানানো যায়নি — উপমোট {$bill->subtotal}, ১ বক্স ১০০০ টাকা নয়।");
        $this->assertLessThan(0.01, abs((float) bcsub((string) $bill->total, '900', 4)),
            "⛔ ভিতরের ভ্যাট \"বাইরে\" ধরা পড়েছে — মোট {$bill->total}, হওয়ার কথা ৯০০।");
    }

    public function test_a_discount_bigger_than_the_price_before_vat_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->bill('VAB-OVER', false, '100', '1100');
    }

    private function bill(string $code, bool $inclusive, string $rate, string $discount): SalesInvoice
    {
        $tax = Tax::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'rate' => '15', 'kind' => 'vat',
            'is_inclusive' => $inclusive, 'is_active' => true]);
        $product = app(ProductService::class)->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code,
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'), 'purchase_price' => '50', 'sale_price' => $rate, 'tax_id' => $tax->id]);

        $invoice = app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'bill_discount' => $discount,
        ], [['product_id' => $product->id, 'qty' => '10', 'rate' => $rate]]);

        return SalesInvoice::query()->with('lines')->findOrFail($invoice->id);
    }
}
