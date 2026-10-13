<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ কাগজের খাতা খসড়া চালান আর খসড়া বিল গুনত — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা ([[SalesRegisterReports]])।
 *
 * ⓘ ১০টার অর্ডার: পাকা চালানে ৬টা, খসড়া চালানে ৩টা; সেই ৬টার বিল খসড়ায় → ডেলিভারি ৬০% (৯০% নয়), বিল ০% (৬০% নয়)।
 * বিল পাকা হলে তবেই ৬০%।
 */
final class TheRegisterCountsOnlyPostedPapersTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_challans_and_draft_bills_are_not_progress(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
        $head = ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()];

        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create($head,
            [['product_id' => $product->id, 'ordered_qty' => '10', 'rate' => '100']]))->load('lines');
        $orderLine = $order->lines->first()->id;

        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create([...$head, 'sales_order_id' => $order->id, 'own_transport' => true], [[
            'product_id' => $product->id, 'delivered_qty' => '6', 'rate' => '100', 'sales_order_line_id' => $orderLine,
        ]]))->load('lines');

        // ⓘ খসড়া চালান — মাল এখনো গুদামে
        $challans->create([...$head, 'sales_order_id' => $order->id, 'own_transport' => true], [[
            'product_id' => $product->id, 'delivered_qty' => '3', 'rate' => '100', 'sales_order_line_id' => $orderLine,
        ]]);

        // ⓘ খসড়া বিল — খাতায় কিছু বসেনি
        $invoice = app(SalesInvoiceService::class)->create($head, [[
            'product_id' => $product->id, 'qty' => '6', 'rate' => '100',
            'delivery_challan_line_id' => $challan->lines->first()->id,
        ]]);
        $this->assertSame(DocumentStatus::DRAFT, $invoice->fresh()->status, 'প্রস্তুতিটাই ভুল — বিলটা খসড়া নয়।');

        $this->assertSame(['60', '0'], $this->pct($order->document_no), '⛔ খসড়া চালান "ডেলিভারি" বা খসড়া বিল "বিল হয়েছে" গোনা হলো');

        $invoice->forceFill(['status' => DocumentStatus::CONFIRMED])->saveQuietly();

        $this->assertSame(['60', '60'], $this->pct($order->document_no), 'পাকা বিল গোনা হলো না।');
    }

    /** @return array{0: string, 1: string} */
    private function pct(string $documentNo): array
    {
        $rows = app(ReportEngine::class)->run('sales.register', [
            'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(),
        ], perPage: 500)->rows;

        foreach ($rows as $row) {
            $row = (array) $row;

            if (($row['document_no'] ?? null) === $documentNo) {
                return array_map(fn ($v) => $v === null ? '' : (string) (int) $v, [$row['delivered_pct'], $row['invoiced_pct']]);
            }
        }

        $this->fail("{$documentNo} খাতায় নেই।");
    }
}
