<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ক্রয় ফেরতের দর আর ভ্যাট — অডিট গ১৮, ৪ অক্টোবর ২০২৬।
 *
 * ⛔ (১) ফেরতের ভ্যাট ঘরে যা লেখা হত তা-ই নিত: ৳১০০-র ফেরতে ভ্যাট ৫ লাখ লিখলে দেনা ৫ লাখ কমত।
 * ⛔ (২) দর আসত ছাড়ের আগের তালিকা-দর থেকে: ৯০-এ কেনা মাল ফেরতে দেনা কমত ১০০।
 */
class TheReturnTookAnyVatAndTheListPriceTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        // ⓘ সইয়ের ছক এই পরীক্ষার বিষয় নয়
        ApprovalFlow::query()->where('module', 'purchase')->delete();
    }

    /** (২) ১০টা ১০০ দরে, ১০০ ছাড়ে — ফেরতের দর ৯০, ৪টার ফেরত ৩৬০। */
    public function test_the_return_is_priced_after_the_discount(): void
    {
        $bill = $this->bill(discount: '100');

        $return = $this->draft($bill, '4');

        $this->assertSame(0, bccomp('90', (string) $return->lines->first()->rate, 4),
            'ফেরতের দর '.$return->lines->first()->rate.' — ছাড়ের আগের দর নেওয়া হয়েছে।');
        $this->assertSame(0, bccomp('360', (string) $return->total, 4),
            'ফেরতের মোট '.$return->total.', হওয়ার কথা ৩৬০।');
    }

    /** (১) ভ্যাট চালু: বিলে ১০টায় ভ্যাট ৫০ — ৪টা ফেরতে খালি ঘর মানে ২০। */
    public function test_a_blank_vat_takes_the_bills_share(): void
    {
        app(SettingsService::class)->set('purchase.vat_enabled', true);
        $bill = $this->bill(tax: '50');

        $return = $this->draft($bill, '4');

        $this->assertSame(0, bccomp('20', (string) $return->tax, 4), 'ফেরতের ভ্যাট '.$return->tax.', হওয়ার কথা ২০।');
    }

    /** (১) ভ্যাট চালু: বিলের অংশের বেশি লিখলে থামে। */
    public function test_vat_above_the_bills_share_is_refused(): void
    {
        app(SettingsService::class)->set('purchase.vat_enabled', true);
        $bill = $this->bill(tax: '50');

        try {
            $this->draft($bill, '4', tax: '500000');
            $this->fail('৪টার ফেরতে ৫ লাখ ভ্যাট নেওয়া হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $this->assertSame(0, PurchaseReturn::query()->count(), 'থামার পরেও ফেরতের কাগজ রয়ে গেল।');
    }

    /** (১) অংশের সমান বা কম লিখলে চলে — নিষেধ যেন সব দরজা বন্ধ না করে। */
    public function test_vat_within_the_share_is_kept(): void
    {
        app(SettingsService::class)->set('purchase.vat_enabled', true);
        $bill = $this->bill(tax: '50');

        $return = $this->draft($bill, '4', tax: '15');

        $this->assertSame(0, bccomp('15', (string) $return->tax, 4));
    }

    /** (১) ভ্যাট বন্ধ কোম্পানিতে ফেরতেও ভ্যাট নয়। */
    public function test_vat_off_refuses_any_vat_on_a_return(): void
    {
        app(SettingsService::class)->set('purchase.vat_enabled', false);
        $bill = $this->bill();

        $this->expectException(ValidationException::class);

        $this->draft($bill, '4', tax: '10');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function bill(string $discount = '0', ?string $tax = null): PurchaseBill
    {
        $service = app(PurchaseBillService::class);
        $line = ['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'discount' => $discount];

        if ($tax !== null) {
            $line['tax'] = $tax;
        }

        return $service->confirm(
            $service->create(['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()], [$line])
        )->load('lines');
    }

    private function draft(PurchaseBill $bill, string $qty, ?string $tax = null): PurchaseReturn
    {
        $line = ['product_id' => $this->product->id, 'purchase_bill_line_id' => $bill->lines->first()->id, 'qty' => $qty];

        if ($tax !== null) {
            $line['tax'] = $tax;
        }

        return app(PurchaseReturnService::class)->create(
            ['supplier_id' => $bill->supplier_id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [$line],
        )->load('lines');
    }
}
