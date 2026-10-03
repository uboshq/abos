<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\DeliveryOrderLine;
use App\Modules\Sales\Models\DeliveryOrderStockHold;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Reports\SalesOrderBookReports;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পরিকল্পনা সংস্করণ ২ §৯-এর তিন রিপোর্ট — মালিক, ৪ অক্টোবর ২০২৬ ([[SalesOrderBookReports]])।
 *
 *   · খোলা আদেশ: অনুমোদিত ১০, মজুদে ধরা ৬ → ব্যাক অর্ডার ৪; অবস্থা নামে, চাবিতে নয়; বন্ধ DO আসে না।
 *   · সীমায় আটকানো: কেবল `accounts_held` — কত কম আর কবে থেকে; অনুমোদিত DO আসে না।
 *   · বিক্রয় খাতা: প্রতিটা ইনভয়েস এক সারি; বাতিল-ইনভয়েসে উল্টানোটার পাশে CXL নম্বর, বাকি শূন্য।
 */
final class TheOrderBookReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $dealer;

    private Product $biscuit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    public function test_open_orders_show_what_is_held_and_what_is_still_owed(): void
    {
        $open = $this->order('DO-T-1', DeliveryOrderStatus::ACCOUNTS_APPROVED, '1000');
        $line = DeliveryOrderLine::query()->create(['delivery_order_id' => $open->id, 'product_id' => $this->biscuit->id,
            'qty' => '12', 'approved_qty' => '10', 'rate' => '100', 'line_total' => '1000']);
        DeliveryOrderStockHold::query()->create([
            'company_id' => $this->company->id, 'delivery_order_id' => $open->id, 'delivery_order_line_id' => $line->id,
            'product_id' => $this->biscuit->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'wanted_qty' => '10', 'qty' => '6', 'kind' => 'good', 'held_at' => now(),
        ]);
        $closed = $this->order('DO-T-2', DeliveryOrderStatus::INVOICED, '500');
        DeliveryOrderLine::query()->create(['delivery_order_id' => $closed->id, 'product_id' => $this->biscuit->id,
            'qty' => '5', 'rate' => '100', 'line_total' => '500']);

        $rows = $this->book(SalesOrderBookReports::OPEN_ORDERS);

        $this->assertCount(1, $rows, '⛔ বন্ধ DO খোলা আদেশে এসেছে।');
        $this->assertSame([0, 0, 0], [
            bccomp((string) $rows[0]['wanted_qty'], '10', 4), bccomp((string) $rows[0]['held_qty'], '6', 4), bccomp((string) $rows[0]['back_order_qty'], '4', 4),
        ], '⛔ চাওয়া/ধরা/ব্যাক অর্ডার ভুল।');
        $this->assertSame(DeliveryOrderStatus::label(DeliveryOrderStatus::ACCOUNTS_APPROVED), $rows[0]['status'], '⛔ অবস্থা কাঁচা চাবিতে।');
    }

    public function test_credit_blocked_lists_only_held_orders_with_the_shortfall(): void
    {
        $held = $this->order('DO-T-3', DeliveryOrderStatus::ACCOUNTS_HELD, '9000');
        $held->forceFill(['accounts_short' => '2500', 'accounts_held_at' => now()->subDays(2)])->save();
        $this->order('DO-T-4', DeliveryOrderStatus::ACCOUNTS_APPROVED, '100');

        $rows = $this->book(SalesOrderBookReports::CREDIT_BLOCKED);

        $this->assertCount(1, $rows);
        $this->assertSame('DO-T-3', $rows[0]['document_no']);
        $this->assertSame(0, bccomp((string) $rows[0]['accounts_short'], '2500', 4));
        $this->assertSame(2, (int) $rows[0]['days_held']);
    }

    public function test_the_sales_book_has_one_row_per_invoice_and_names_the_cancellation(): void
    {
        $kept = $this->sell('3');
        $wrong = $this->sell('2');
        $cxl = app(SalesInvoiceCancellationService::class)->request($wrong, $this->owner, 'ভুল বিল');

        $rows = collect($this->book(SalesOrderBookReports::INVOICE_BOOK))->keyBy('document_no');

        $this->assertTrue($rows->has($kept->document_no) && $rows->has($wrong->document_no), '⛔ ইনভয়েস খাতায় নেই।');
        $this->assertSame($cxl->document_no, $rows[$wrong->document_no]['cxl_no'], '⛔ বাতিল-ইনভয়েসের নম্বর নেই।');
        $this->assertSame(0, bccomp((string) $rows[$wrong->document_no]['due'], '0', 4), '⛔ বাতিল ইনভয়েসে বাকি দেখাচ্ছে।');
        $this->assertSame(0, bccomp((string) $rows[$kept->document_no]['total'], '30', 4));
        $this->assertNull($rows[$kept->document_no]['cxl_no']);
    }

    public function test_the_three_pages_open(): void
    {
        foreach (['open-orders', 'credit-blocked', 'invoice-book'] as $slug) {
            $this->get(route('sales.report.show', ['slug' => $slug]))->assertOk();
        }
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function book(string $key): array
    {
        return app(ReportEngine::class)->run($key, ['from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString()], perPage: 500)->rows;
    }

    private function order(string $no, string $status, string $total): DeliveryOrder
    {
        return DeliveryOrder::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => $no, 'customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(),
            'status' => $status, 'subtotal' => $total, 'total' => $total,
        ]);
    }

    private function sell(string $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete([
            'customer_id' => $this->dealer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'own_transport' => '1',
        ], [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']])['invoice']->fresh();
    }
}
