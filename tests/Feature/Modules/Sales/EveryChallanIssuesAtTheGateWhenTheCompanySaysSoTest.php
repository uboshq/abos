<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অফিসের চালানও গেট পাসে মাল নামায় — কেবল কাউন্টারের নয় (সমন্বয়ক, ৬ অক্টোবর ২০২৬; মালিকের পরিকল্পনা §৩ ধাপ ৫–৬; ধাপ ১৪-এর পর্দার
 * পরীক্ষায় ধরা: আদেশের "মাল পাঠান" চালানে মাল নিশ্চিতেই নামত আর "বিল করুন" থাকত)।
 *
 * দাবি — একই মানুষ, একই আদেশের দুই চালান: সুইচ চালু — চালান নিশ্চিত হলেও তাকের মাল নামে না, "বিল করুন" নেই; সুইচ বন্ধ —
 * আগের মতো নিশ্চিতেই নামে, বোতাম আছে।
 */
final class EveryChallanIssuesAtTheGateWhenTheCompanySaysSoTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set('customer.credit_limit_enabled', false); // ⓘ সীমা এখানে প্রশ্ন নয়

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    public function test_an_office_challan_follows_the_company_switch(): void
    {
        $order = app(SalesOrderService::class)->confirm(app(SalesOrderService::class)->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => '10', 'rate' => '10']])->fresh(['lines']));

        // ⭐ সুইচ চালু — গেট পাসে নামবে
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $floor = $this->floor();
        $atGate = $this->challan($order, '4');
        $this->assertTrue((bool) $atGate->issue_at_gate, '⛔ সুইচ চালু, তবু অফিসের চালান গেটে নামার চিহ্ন পেল না।');
        $this->assertSame(0, bccomp($floor, $this->floor(), 4), '⛔ সুইচ চালু, তবু চালান নিশ্চিত হতেই তাকের মাল নামল।');
        $this->get(route('sales.challan.show', $atGate))->assertOk()
            ->assertDontSee('href="'.e(route('sales.invoice.create', ['delivery_challan_id' => $atGate->id])).'"', false);

        // ⭐ একই মানুষ, সুইচ বন্ধ — আগের মতো নিশ্চিতেই নামে, বিলের বোতাম আছে
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', false);
        $floor = $this->floor();
        $now = $this->challan($order, '3');
        $this->assertFalse((bool) $now->issue_at_gate);
        $this->assertSame(0, bccomp(bcsub($floor, '3', 4), $this->floor(), 4), 'সুইচ বন্ধ, তবু চালান নিশ্চিতে মাল নামল না।');
        $this->get(route('sales.challan.show', $now))->assertOk()
            ->assertSee('href="'.e(route('sales.invoice.create', ['delivery_challan_id' => $now->id])).'"', false);
    }

    private function floor(): string
    {
        return (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['floor'];
    }

    private function challan(SalesOrder $order, string $qty): DeliveryChallan
    {
        $line = $order->fresh(['lines'])->lines->first();
        $challans = app(DeliveryChallanService::class);
        $paper = $challans->create([
            'customer_id' => $order->customer_id, 'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id, 'trx_date' => now()->toDateString(),
        ], [['product_id' => $line->product_id, 'sales_order_line_id' => $line->id, 'delivered_qty' => $qty, 'rate' => (string) $line->rate]]);

        return $challans->confirm($paper->fresh(['lines']))->fresh();
    }
}
