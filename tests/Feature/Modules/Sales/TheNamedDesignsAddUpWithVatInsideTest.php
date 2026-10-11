<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⛔ নামওয়ালা নকশাতেও (ডিফল্ট `mono_light`-সহ) কাগজের যোগফল মেলে — দামের ভিতরের ভ্যাট সাধারণ "ভ্যাট" সারিতে বসে না (PR #17 রিভিউ ⛔৩,
 * ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ PR #17 "ভ্যাট (দামের ভিতরে)" কেবল সাধারণ [[SalesPrintController::totals()]]-এ বসিয়েছিল। নামওয়ালা নকশা টাকার সারি নেয়
 * [[SalesPrintController::classicFacts()]]-এর `sums` থেকে, আর সেখানে পুরো ভ্যাট সাধারণ সারিতে: ১০০০ টাকার বিল, ভিতরে ১৩০ ভ্যাট →
 * "সর্বমোট ১০০০ · ভ্যাট ১৩০ · দেয় ১০০০"। ভাড়ার সারিও ছিল না।
 */
final class TheNamedDesignsAddUpWithVatInsideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⭐ "ভ্যাট" সারিতে কেবল উপরে যোগ হওয়া অংশ; ভিতরেরটা আর ভাড়া নিজের ঘরে — যেকোনো নকশায় সর্বমোট − ছাড় + ভ্যাট + ভাড়া = দেয়। */
    public function test_the_sums_split_vat_and_carry_the_freight(): void
    {
        $bill = $this->aBill();

        $inside = $this->sums($bill->forceFill(['subtotal' => '1000', 'discount' => '0', 'bill_discount' => '0', 'tax' => '130', 'freight_charge' => '0', 'rounding_amount' => '0', 'total' => '1000']));
        $this->assertSame(Money::format('0'), $inside['vat'], '⛔ দামের ভিতরের ভ্যাট সাধারণ "ভ্যাট" সারিতে — কাগজ মেলে না।');
        $this->assertSame(Money::format('130'), $inside['vat_included']);

        $outside = $this->sums($bill->forceFill(['subtotal' => '1000', 'tax' => '150', 'total' => '1150']));
        $this->assertSame(Money::format('150'), $outside['vat'], 'বাইরের ভ্যাট আগের মতোই "ভ্যাট"।');
        $this->assertSame(Money::format('0'), $outside['vat_included']);

        $freight = $this->sums($bill->forceFill(['subtotal' => '1000', 'tax' => '0', 'freight_charge' => '50', 'total' => '1050']));
        $this->assertSame(Money::format('50'), $freight['freight'], '⛔ ভাড়া টাকার সারিতে নেই — সর্বমোট আর দেয় ৫০ টাকায় অমিল।');
    }

    /** ⭐ আর ভাগের দুই partial সারি দুটো আঁকে — শূন্য হলে আঁকে না। */
    public function test_both_shared_sum_tables_draw_the_rows(): void
    {
        foreach (['sales::print.invoice-mono_light', 'sales::print.invoice-thermal_bangla'] as $template) {
            $with = $this->render($template, ['vat_included' => '130.00', 'freight' => '50.00']);
            $this->assertStringContainsString('data-vat-included', $with, "⛔ {$template}: ভিতরের ভ্যাটের সারি নেই।");
            $this->assertStringContainsString('data-freight', $with, "⛔ {$template}: ভাড়ার সারি নেই।");

            $without = $this->render($template, ['vat_included' => '0.00', 'freight' => '0.00']);
            $this->assertStringNotContainsString('data-vat-included', $without, "{$template}: শূন্যের সারি বসেছে।");
            $this->assertStringNotContainsString('data-freight', $without, "{$template}: শূন্যের সারি বসেছে।");
        }
    }

    private function aBill(): SalesInvoice
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $customer->forceFill(['credit_limit' => '10000000'])->save();
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '1', 'rate' => '100']],
        ));
    }

    /** @return array<string, string> */
    private function sums(SalesInvoice $bill): array
    {
        return (new ReflectionMethod(SalesPrintController::class, 'classicFacts'))->invoke(app(SalesPrintController::class), $bill)['sums'];
    }

    /** @param  array<string, string>  $extra */
    private function render(string $template, array $extra): string
    {
        // ⓘ ভ্যাট দেখানো চালু — নইলে ভিতরের ভ্যাটের সারি ঠিকই লুকায়, আর দাবিটা কিছু মাপে না
        app(SettingsService::class)->set('sales.vat_enabled', true);

        return app(PrintEngine::class)->preview($template, [
            'title' => 'S-0001',
            'doc' => new PrintableDocument(title: 'Invoice'),
            'facts' => [
                'bill_to' => ['name' => 'Rahim Traders', 'point' => '', 'phone' => '', 'address' => ''],
                'transport' => ['carrier' => '', 'driver_phone' => '', 'vehicle' => '', 'delivery_date' => ''],
                'bill' => ['bill_date' => '11-10-2026', 'bill_no' => 'S-0001', 'order_no' => '', 'type' => 'CASH', 'created_by' => ''],
                'total_items' => '1',
                'total_delivery' => '1',
                'items' => ['rows' => [['name' => 'Item', 'code' => '', 'lot' => '', 'rate' => '1,000.00', 'qty' => '1 Pcs', 'free' => '',
                    'total_qty' => '1 Pcs', 'amount' => '1,000.00']], 'totals' => ['qty' => '1 Pcs', 'free' => '', 'total_qty' => '1 Pcs', 'amount' => '1,000.00']],
                'sums' => ['grand_total' => '1,000.00', 'discount' => '0.00', 'vat' => '0.00', 'rounding' => '0.00',
                    'net_payable' => '1,050.00', 'paid' => '0.00', 'invoice_due' => '1,050.00', 'previous_due' => '0.00', 'outstanding' => '1,050.00',
                    ...$extra],
                'words' => 'One Thousand Fifty Taka Only',
                'scan_url' => route('sales.scan', '00000000-0000-7000-8000-000000000000'),
            ],
        ], PaperSize::A4, PrintProfile::for('invoice', app(SettingsService::class)));
    }
}
