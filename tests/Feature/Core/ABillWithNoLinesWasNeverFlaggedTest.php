<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Integrity\PurchaseChecks;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Integrity\SalesChecks;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মোট আছে অথচ একটাও লাইন নেই — এমন বিল যাচাইয়ে ধরা পড়ে।
 *
 * ── ⛔ কী ভেঙেছিল (ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬) ──────────
 * বিক্রয় আর ক্রয়ের "বিলের মোট = লাইনের যোগ" যাচাই লাইনের যোগফলের সাথে
 * **ভিতরের জোড়** দিত। হাতে চালানো SQL বা আধেক লেখা ট্রানজেকশনে লাইনগুলো
 * হারালে বিলটা জোড়েই আসত না — ঠিক যে ভাঙার জন্য যাচাইটা লেখা, সেটাই
 * চিরকাল সবুজ।
 */
final class ABillWithNoLinesWasNeverFlaggedTest extends TestCase
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

    public function test_a_sales_invoice_that_lost_all_its_lines_is_flagged(): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '2', 'rate' => '100']],
        );

        $this->assertSame([], SalesChecks::invoiceMatchesItsLines()->run(), 'পরীক্ষার আগেই যাচাইটা লাল।');

        DB::table('sal_invoice_lines')->where('sales_invoice_id', $invoice->id)->delete();

        $findings = SalesChecks::invoiceMatchesItsLines()->run();

        $this->assertCount(1, $findings, 'লাইনহীন বিলটা যাচাইয়ে ধরা পড়েনি।');
        $this->assertSame($invoice->document_no, $findings[0]->what);
    }

    public function test_a_purchase_bill_that_lost_all_its_lines_is_flagged(): void
    {
        $bill = app(PurchaseBillService::class)->create([
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'NO-LINES-1',
        ], [['product_id' => Product::query()->value('id'), 'qty' => '2', 'rate' => '100']]);

        $this->assertSame([], PurchaseChecks::billMatchesItsLines()->run(), 'পরীক্ষার আগেই যাচাইটা লাল।');

        DB::table('pur_bill_lines')->where('purchase_bill_id', $bill->id)->delete();

        $findings = PurchaseChecks::billMatchesItsLines()->run();

        $this->assertCount(1, $findings, 'লাইনহীন ক্রয় বিলটা যাচাইয়ে ধরা পড়েনি।');
        $this->assertSame($bill->document_no, $findings[0]->what);
    }
}
