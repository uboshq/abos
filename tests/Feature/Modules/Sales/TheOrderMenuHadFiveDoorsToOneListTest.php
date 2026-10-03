<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Http\Controllers\PlannedScreenController;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অর্ডারের মেনুতে পাঁচটা দরজা ছিল, অথচ ঘর একটাই।
 *
 * ── ⭐ নকশার পর্যালোচনা, ধাপ ৭-এর ২ (মালিক, ১ অক্টোবর ২০২৬: *"ok kore daw"*) ─────────────
 * "একই তালিকার ছাঁকনি-মেনুগুলো ("অপেক্ষমাণ", "আংশিক", "ব্যাক অর্ডার", "ইতিহাস", "খোঁজ") এক তালিকার ট্যাবে"।
 *
 * ⓘ এই পরীক্ষা চারটা কথা ধরে: মেনুতে পরিবারটার একটাই সারি; প্রতিটা ট্যাবে কেবল তার নিজের আদেশ, আর পাশের
 * গোনা তালিকার সাথে মেলে; চাবি না থাকলে সারি আর ট্যাব কোনোটাই নেই (একই মানুষ, চাবি বন্ধ তারপর খোলা);
 * আর পুরনো চারটা ঠিকানা ঠিক ট্যাবে নামে।
 */
final class TheOrderMenuHadFiveDoorsToOneListTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ ট্যাবগুলো অর্ডারের পর্দার — সুইচটা খোলা থাকতে হবে (বন্ধ পর্দা ৪০৪)
        app(SettingsService::class)->set('sales.screen_orders', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ মেনুতে পরিবারটার একটাই সারি — অর্ডার তালিকা; অপেক্ষমাণ/আংশিক/ব্যাক/ইতিহাসের আলাদা সারি নেই।
     */
    public function test_the_menu_has_one_row_for_the_order_list_family(): void
    {
        $html = $this->get(route('sales.invoice.index'))->assertOk()->getContent();

        /*
         * ⓘ পুরো লিংক, শেষের উদ্ধৃতিসহ — `/sales/orders` নিজেই `/sales/orders/create`-এর শুরু।
         * ⚠️ মেনুটা পাতায় একাধিকবার আঁকা হয় (বার, ফোনের ড্রয়ার …), তাই "একবার" মানে একই ভাঁজের আরেকটা
         * এক-সারির সমান বার — ডেলিভারি ট্র্যাকিং, একই চাবি আর একই সুইচ।
         */
        $at = fn (string $url): int => substr_count($html, 'href="'.e($url).'"');
        $this->assertGreaterThan(0, $at(route('sales.tracking.index')), 'প্রস্তুতিটাই ভুল — ট্র্যাকিংয়ের সারি নেই।');
        $this->assertSame($at(route('sales.tracking.index')), $at(route('sales.order.index')),
            '⛔ মেনুতে অর্ডার তালিকার সারি ঠিক একটা থাকার কথা।');

        foreach (array_keys(PlannedScreenController::FOLDED) as $screen) {
            $this->assertStringNotContainsString('href="'.e(route('sales.planned', ['screen' => $screen])).'"', $html,
                "⛔ '{$screen}' এখনো মেনুর আলাদা সারি — ওটা অর্ডার তালিকার ট্যাব হওয়ার কথা।");
        }
    }

    /**
     * ⭐ প্রতিটা ট্যাবে কেবল তার নিজের আদেশ, আর পাশের গোনা তালিকার সারির সাথে মেলে।
     */
    public function test_each_tab_shows_only_its_own_orders_and_its_count_agrees(): void
    {
        $pending = $this->order('2');                       // কিছুই যায়নি
        $partial = $this->deliver($this->order('2'), '1');  // অর্ধেক গেছে
        $done = $this->deliver($this->order('2'), '2');     // সব গেছে → ইতিহাস
        $cancelled = $this->order('2');
        app(SalesOrderService::class)->cancel($cancelled->fresh(), 'পরীক্ষা');

        // ব্যাক অর্ডার: তাকে যা আছে তার চেয়ে বেশি চাওয়া (রাস্তার মালের সুইচ খুলে)
        app(SettingsService::class)->set('sales.allow_negative_stock', true);
        $floor = app(StockService::class)->statesFor($this->product, $this->warehouse)['floor'];
        $back = $this->order(bcadd($floor, '5', 4));

        $expected = [
            OrderTracking::LIST_ALL => [$pending, $partial, $done, $back],
            OrderTracking::LIST_PENDING => [$pending, $back],
            OrderTracking::LIST_PARTIAL => [$partial],
            OrderTracking::LIST_BACK => [$back],
            OrderTracking::LIST_HISTORY => [$done, $cancelled],
        ];

        foreach ($expected as $tab => $orders) {
            $page = $this->get(route('sales.order.index', $tab === OrderTracking::LIST_ALL ? [] : ['tab' => $tab]))->assertOk();

            $shown = collect($page->viewData('orders')->items())->pluck('id')->sort()->values()->all();
            $want = collect($orders)->pluck('id')->sort()->values()->all();
            $this->assertSame($want, $shown, "⛔ '{$tab}' ট্যাবে ভুল আদেশ।");

            $counts = collect($page->viewData('tabs'))->pluck('count', 'key')->all();
            $this->assertSame(count($want), $counts[$tab] ?? null,
                "⛔ '{$tab}' ট্যাবের পাশের সংখ্যা আর তালিকার সারি আলাদা কথা বলছে।");

            $this->assertSame($tab, $page->viewData('tab'));
            $this->assertMatchesRegularExpression('/data-tab="'.$tab.'"\s*aria-current="page"/', $page->getContent(),
                "⛔ '{$tab}' ট্যাবটা খোলা দেখাচ্ছে না।");
        }

        // ⓘ ফোনের চওড়ায় সারিটা নিজে পাশে সরে, পাতা নয়
        $html = $this->get(route('sales.order.index'))->getContent();
        $this->assertMatchesRegularExpression('/<nav data-list-tabs[^>]*overflow-x-auto/', $html,
            '⛔ ট্যাবের সারি ফোনে পাশে সরে না — পাতাটাই চওড়া হয়ে যেত।');
    }

    /**
     * ⭐ চাবি না থাকলে মেনুর সারিও নেই, ট্যাবও নেই, দরজাও বন্ধ — একই মানুষ, চাবি বন্ধ তারপর খোলা।
     */
    public function test_without_the_key_neither_the_row_nor_the_tabs_show(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo('sales.invoice.view');

        $this->assertNotContains(route('sales.order.index'), $this->menuUrls($clerk),
            '⛔ চাবি ছাড়াই মেনুতে অর্ডার তালিকা।');
        $this->actingAs($clerk)->get(route('sales.order.index', ['tab' => 'pending']))->assertForbidden();
        $this->actingAs($clerk)->get(route('sales.planned', ['screen' => 'order_pending']))->assertForbidden();

        $clerk->givePermissionTo('sales.order.view');
        $clerk = $clerk->fresh();

        $urls = $this->menuUrls($clerk);
        $this->assertContains(route('sales.order.index'), $urls, '⛔ চাবি পেয়েও মেনুতে অর্ডার তালিকা নেই।');
        foreach (array_keys(PlannedScreenController::FOLDED) as $screen) {
            $this->assertNotContains(route('sales.planned', ['screen' => $screen]), $urls,
                "⛔ '{$screen}' এখনো মেনুর আলাদা সারি।");
        }

        $html = $this->actingAs($clerk)->get(route('sales.order.index'))->assertOk()->getContent();
        foreach (array_keys(OrderTracking::LIST_TABS) as $tab) {
            $this->assertStringContainsString('data-tab="'.$tab.'"', $html, "⛔ চাবি পেয়েও '{$tab}' ট্যাব নেই।");
        }
    }

    /**
     * ⭐ পুরনো চারটা ঠিকানা মরেনি — বুকমার্ক ঠিক ট্যাবে নামে।
     */
    public function test_the_old_addresses_land_on_their_tab(): void
    {
        foreach (PlannedScreenController::FOLDED as $screen => $tab) {
            $this->get(route('sales.planned', ['screen' => $screen]))
                ->assertRedirect(route('sales.order.index', ['tab' => $tab]));

            $this->get(route('sales.order.index', ['tab' => $tab]))
                ->assertOk()
                ->assertViewHas('tab', $tab);
        }

        $this->assertSame(['pending', 'partial', 'back', 'history'], array_values(PlannedScreenController::FOLDED));
    }

    /** @return list<string> */
    private function menuUrls(User $user): array
    {
        $urls = [];

        foreach (app()->make(MenuBuilder::class)->forUser($user) as $module) {
            foreach ($module['groups'] as $rows) {
                foreach ($rows as $row) {
                    if (($row['url'] ?? null) !== null) {
                        $urls[] = $row['url'];
                    }
                }
            }
        }

        return $urls;
    }

    private function order(string $qty): SalesOrder
    {
        $order = app(SalesOrderService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'ordered_qty' => $qty,
            'rate' => '100',
        ]]);

        return app(SalesOrderService::class)->confirm($order->fresh(['lines']));
    }

    private function deliver(SalesOrder $order, string $qty): SalesOrder
    {
        $challans = app(DeliveryChallanService::class);

        $paper = $challans->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'sales_order_line_id' => $order->lines()->first()->id,
            'delivered_qty' => $qty,
            'rate' => '100',
        ]]);

        $challans->confirm($paper->fresh(['lines']));

        return $order;
    }
}
