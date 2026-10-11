<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⛔ টাকাসহ চালান কেবল নিজের একটা পাকা বিলের হিসাব নেয় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (ছাপা ১২; [[SalesPrintController::challanMoney()]])।
 *
 * ⓘ আগে খসড়া বা যেকোনো বিল, ক্রম ছাড়া: খসড়া বিলের মোট, বা দুই আংশিক বিলের একটার মোট চালানে "চালানের টাকা" হয়ে ছাপা হত।
 */
final class TheMoneyChallanShowsItsOwnBillTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse, sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '100');
    }

    public function test_a_draft_bill_lends_nothing_and_the_one_posted_bill_gives_its_figures(): void
    {
        $challan = $this->confirmedChallan('10');
        $draft = $this->bill($challan, '10');

        $this->assertSame([], $this->money($challan), '⛔ খসড়া বিলের হিসাব টাকাসহ চালানে বসল');

        app(SalesInvoiceService::class)->confirm($draft->fresh(['lines']));
        $money = $this->money($challan);
        $this->assertSame((string) Money::format($draft->fresh()->total), $money['total'] ?? null, 'নিজের পাকা বিলের মোট আসার কথা');
    }

    public function test_two_partial_bills_lend_neither_ones_total(): void
    {
        $challan = $this->confirmedChallan('10');
        foreach (['4', '6'] as $qty) {
            app(SalesInvoiceService::class)->confirm($this->bill($challan, $qty)->fresh(['lines']));
        }

        $this->assertSame([], $this->money($challan), '⛔ দুই আংশিক বিলের একটার মোট চালানের টাকা হয়ে ছাপা হলো');
    }

    /** @return array<string, mixed> */
    private function money(DeliveryChallan $challan): array
    {
        return (new ReflectionMethod(SalesPrintController::class, 'challanMoney'))->invoke(app(SalesPrintController::class), $challan->fresh());
    }

    private function confirmedChallan(string $qty): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);

        return $challans->confirm($challans->create(['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(), 'own_transport' => true],
            [['product_id' => $this->product->id, 'delivered_qty' => $qty, 'rate' => '100']])->fresh(['lines']))->fresh(['lines']);
    }

    private function bill(DeliveryChallan $challan, string $qty): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create(['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100', 'delivery_challan_line_id' => $challan->lines->first()->id]]);
    }
}
