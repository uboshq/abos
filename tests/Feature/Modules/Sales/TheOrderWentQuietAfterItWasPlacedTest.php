<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * আদেশ দেওয়ার পর সেটা চুপ হয়ে যেত — কেউ বলতে পারত না মাল গেল কি না।
 *
 * ── ⭐ মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"এখানে Order Tracking-এর ব্যবস্থা করতে হবে… অ্যাপ ১০০% হওয়ার পর সব
 * customer তার নিজের, employee তার অধীনের সকল order ট্রেস করতে পারবে।"*
 *
 * ⓘ আজকের পাতাটা কর্মীদের, আর সত্যিকারের — প্রতিটা আদেশের ধাপ, কতটা মাল
 * গেছে, বিল হয়েছে কি না। ⚠️ গ্রাহকের নিজের আদেশ দেখার পথ পরের ধাপ।
 */
final class TheOrderWentQuietAfterItWasPlacedTest extends TestCase
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
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ কিছু না গেলে আদেশটা "আদেশ হয়েছে" ধাপেই — আর পাতাটা খোলে।
     */
    public function test_a_fresh_order_stands_at_placed(): void
    {
        $order = $this->order();

        $page = $this->get(route('sales.order.track'))->assertOk();

        $page->assertSee($order->document_no);
        $page->assertSee(__('sales::field.stage_placed'));

        $this->assertSame(OrderTracking::PLACED, $this->stageOf($order));
    }

    /**
     * ⭐ ট্যাব ধরে ছাঁকা, আর পাশের সংখ্যাটা তালিকার সাথে মেলে।
     *
     * ⚠️ দাবিটা দুইটা একসাথে মাপে, কারণ ঐ দুই সংখ্যা আলাদা হলে মানুষ
     * বুঝতে পারেন না কোনটা সত্যি — আর সেটাই সবচেয়ে সহজ ভুল।
     */
    public function test_the_tabs_and_their_counts_agree(): void
    {
        $this->order();
        $this->order();

        $counts = app(OrderTracking::class)->counts(null);

        $this->assertSame(2, $counts['all']);
        $this->assertSame(2, $counts[OrderTracking::PLACED]);
        $this->assertSame(0, $counts[OrderTracking::BILLED]);

        $rows = $this->get(route('sales.order.track', ['stage' => OrderTracking::PLACED]))
            ->assertOk()
            ->viewData('orders');

        $this->assertSame(2, $rows->total(), 'ট্যাবের সংখ্যা আর তালিকার সারি আলাদা কথা বলছে।');

        $this->assertSame(0, $this->get(route('sales.order.track', ['stage' => OrderTracking::BILLED]))
            ->viewData('orders')->total());
    }

    /**
     * ⭐ মাল গেলে আর বিল হলে ধাপটাও এগোয়।
     *
     * ⓘ সরাসরি বিক্রয় এক চাপে চালান ও বিল দুইটাই বানায়, কিন্তু সেটা
     * আদেশের সাথে বাঁধা নয়। ⚠️ তাই এখানে আদেশ ধরেই চালান হয়, নাহলে
     * ধাপটা কখনো এগোত না — আর ঠিক ঐ ফাঁদটাই এই পাতার কারণ।
     */
    public function test_the_stage_moves_when_the_goods_go_and_the_bill_is_made(): void
    {
        $order = $this->order();

        // মাল গেল — আদেশের সারি ধরে চালান, আর সেটা নিশ্চিত
        $challan = app(\App\Modules\Sales\Services\DeliveryChallanService::class);

        $line = $order->lines()->first();

        $paper = $challan->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'sales_order_line_id' => $line->id,
            'delivered_qty' => '10',
            'rate' => '100',
        ]]);

        $challan->confirm($paper->fresh(['lines']));

        $this->assertSame(OrderTracking::DELIVERED, $this->stageOf($order),
            'পুরো মাল গেছে, তবু ধাপ বলছে অন্য কিছু।');

        // বিল হলো ঐ চালানের সারি ধরে
        app(\App\Modules\Sales\Services\SalesInvoiceService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'delivery_challan_line_id' => $paper->fresh(['lines'])->lines->first()->id,
            'qty' => '10',
            'rate' => '100',
        ]]);

        $this->assertSame(OrderTracking::BILLED, $this->stageOf($order),
            'বিল হয়ে গেছে, তবু ধাপ বলছে মাল কেবল গেছে।');
    }

    /**
     * ⛔ বাতিল আদেশ তালিকায় নেই — ওটা আর কারও অপেক্ষার জিনিস নয়।
     */
    public function test_a_cancelled_order_is_not_tracked(): void
    {
        $order = $this->order();

        app(SalesOrderService::class)->cancel($order->fresh(), 'পরীক্ষা');

        $this->assertSame(0, app(OrderTracking::class)->counts(null)['all']);
    }

    private function order(): SalesOrder
    {
        $order = app(SalesOrderService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'ordered_qty' => '10',
            'rate' => '100',
        ]]);

        return app(SalesOrderService::class)->confirm($order->fresh(['lines']));
    }

    private function stageOf(SalesOrder $order): string
    {
        $row = collect(app(OrderTracking::class)->rows(null, 'all')->items())
            ->firstWhere('id', $order->id);

        return app(OrderTracking::class)->stageOf($row);
    }
}
