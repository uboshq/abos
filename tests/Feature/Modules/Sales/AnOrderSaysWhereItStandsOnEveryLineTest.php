<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\Widget;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Events\SalesOrderCancelled;
use App\Modules\Sales\Events\SalesOrderClosed;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\OrderProgress;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বিক্রয় আদেশ বলে সে কোথায় দাঁড়িয়ে — মাথায় অবস্থা, আর মাথায় ও প্রতি লাইনে চালান আর বিলের অগ্রগতি।
 *
 * ── ⭐ মালিক, ৪ অক্টোবর ২০২৬: *"অবশ্যই ইন্টারন্যাশনাল স্ট্যান্ডার্ড"*; নকশা "DO বিক্রয় আদেশে মেশানো" ধাপ ১ ──────────
 * অবস্থা: draft → submitted → awaiting_approval → approved → credit_held | confirmed → closed, পাশে rejected, cancelled।
 * অগ্রগতি: delivery_status আর billing_status (none | partial | full), চালান আর বিলের পরিমাণ থেকে গোনা; পাশে ব্যাক অর্ডার।
 * §৪.৩: খসড়া ৩ দিন পড়ে থাকলে তালিকায় লাল।
 *
 * ⓘ এই পরীক্ষা ধরে: প্রতিটা ধাপ চালান আর বিলের পরিমাণ থেকে ঠিক গোনা হয়, আর একমাত্র লেখক সেটাই ঘরে লেখে; লাইন আলাদা
 * হলে মাথা আর লাইন আলাদা কথা বলে; নতুন অবস্থাগুলো যেমন জমা তেমন দেখায়, আর পুরনো সারি ডিফল্টে আজকের নিয়মে থাকে;
 * ব্যাক অর্ডার; বাতিলে কারণ লাগে আর থাকে; বন্ধের নিয়ম (চাবি — একই মানুষ, বন্ধ তারপর খোলা); তালিকা আর পাতায় চিপ;
 * তিন দিনের খসড়া লাল আর গোনা (পাশে কাগজের তারিখ), দুই দিনেরটা নয়; বাতিল আর বন্ধ ছাড়ে কেবল নিজের ধরা মাল, আর নিজের ঘটনা
 * ছোটায় ঠিক একবার।
 */
final class AnOrderSaysWhereItStandsOnEveryLineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Product $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(\App\Core\Services\SettingsService::class)->set('sales.reserve_on_order', true); // ⓘ এই দাবির প্রশ্নে আদেশে ধরা আছে — ডিফল্ট এখন চালানে (মালিক, ৬ অক্টোবর ২০২৬)
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(SettingsService::class)->set('sales.screen_orders', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        // ⓘ সীমা বড় — এই পরীক্ষা চালান আর বিলের অগ্রগতি মাপে, বাকির দেয়াল নয়
        Customer::query()->whereKey($this->customer->id)->update(['credit_limit' => '100000000']);
        $this->customer = $this->customer->fresh();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $sellable = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->take(2)->get();
        $this->assertCount(2, $sellable, 'প্রস্তুতিটাই ভুল — দুইটা লট-ছাড়া, দামওয়ালা পণ্য নেই।');
        [$this->product, $this->second] = [$sellable[0], $sellable[1]];

        foreach ([$this->product, $this->second] as $product) {
            app(StockService::class)->move(
                product: $product, warehouse: $this->warehouse,
                sourceType: StockService::ADJUSTMENT, sourceId: $product->id, floor: '50',
            );
        }
    }

    /**
     * ⭐ ১০টার আদেশ: খসড়া → সংরক্ষিত → চালান ৪ (আংশিক চালান) → চালান ৬ (পুরো চালান) → বিল ৪ (আংশিক বিল) →
     * বিল ৬ (পুরো বিল) → বন্ধ (কারণ ছাড়া)। প্রতিটা ধাপ মাথায় আর লাইনে; আর একমাত্র লেখক গোনাটাই ঘরে লেখে।
     */
    public function test_each_step_is_derived_from_the_challans_and_bills(): void
    {
        $order = $this->draft([[$this->product, '10']]);
        $this->assertProgress($order, S::DRAFT, S::NONE, S::NONE);

        $order = app(SalesOrderService::class)->confirm($order->fresh(['lines']));
        $this->assertProgress($order, S::CONFIRMED, S::NONE, S::NONE);

        $first = $this->deliver($order, ['first' => '4']);
        $this->assertProgress($order, S::CONFIRMED, S::PARTIAL, S::NONE);
        $this->assertFalse($this->progressOf($order)['back'], '⛔ তাকে ৪৬টা থাকতে ৬টার জন্য ব্যাক অর্ডার।');

        // ⭐ একমাত্র লেখক — গোনাটাই আদেশ আর লাইনের ঘরে
        app(OrderProgress::class)->refresh($order->fresh(['lines']));
        $this->assertSame(S::PARTIAL, $order->fresh()->delivery_status, '⛔ refresh() আদেশের ঘরে চালানের অগ্রগতি লেখেনি।');
        $this->assertSame(S::PARTIAL, $order->fresh(['lines'])->lines->first()->delivery_status, '⛔ refresh() লাইনের ঘরে লেখেনি।');

        $second = $this->deliver($order, ['first' => '6']);
        $this->assertProgress($order, S::CONFIRMED, S::FULL, S::NONE);

        $this->bill($first, '4');
        $this->assertProgress($order, S::CONFIRMED, S::FULL, S::PARTIAL);

        $this->bill($second, '6');

        // ⭐ পুরো বিল হতেই আদেশ নিজে বন্ধ — কারণ ছাড়া, মানুষ ছাড়া (সমন্বয়ক, ৬ অক্টোবর ২০২৬); ঘরগুলোও তাজা
        $closed = $order->fresh(['lines']);
        $this->assertSame(S::CLOSED, $closed->status, '⛔ পুরো বিল হয়েও আদেশ খোলা।');
        $this->assertNull($closed->close_reason);
        $this->assertNull($closed->closed_by, '⛔ নিজে বন্ধ আদেশে কারো নাম বসেছে।');
        $this->assertSame([S::FULL, S::FULL], [$closed->delivery_status, $closed->billing_status]);
        $this->assertSame(0, bccomp('0', (string) $closed->lines->first()->rejected_qty, 4), '⛔ পুরো যাওয়া লাইনে "আর দেওয়া হবে না" বসেছে।');
        $this->assertProgress($order, S::CLOSED, S::FULL, S::FULL);

        $this->assertSame('আংশিক চালান', __('sales::order_status.delivery.partial', [], 'bn'));
        $this->assertSame('পুরো বিল', __('sales::order_status.billing.full', [], 'bn'));
    }

    /**
     * ⭐ লাইন আলাদা হলে মাথা আর লাইন আলাদা কথা বলে — একটা লাইন পুরো গেলে মাথা "আংশিক", "পুরো" নয়।
     */
    public function test_the_header_and_the_lines_differ_when_the_lines_differ(): void
    {
        $order = $this->approved([[$this->product, '10'], [$this->second, '5']]);
        [$a, $b] = $order->lines->pluck('id')->all();

        $challan = $this->deliver($order, [$a => '10']);
        $p = $this->progressOf($order);
        $this->assertSame(S::PARTIAL, $p['delivery'], '⛔ এক লাইন পুরো গেছে, অন্যটা কিছুই না — মাথা "আংশিক চালান" বলার কথা।');
        $this->assertSame(S::FULL, $p['lines'][$a]['delivery']);
        $this->assertSame(S::NONE, $p['lines'][$b]['delivery']);

        $this->bill($challan, '10');
        $p = $this->progressOf($order);
        $this->assertSame(S::PARTIAL, $p['billing']);
        $this->assertSame(S::FULL, $p['lines'][$a]['billing']);
        $this->assertSame(S::NONE, $p['lines'][$b]['billing']);

        // ⓘ পাতায় মাথার চিপ আর দুইটা লাইনের দুই রকম চিপ
        $html = $this->get(route('sales.order.show', $order))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/data-order-header-state>\s*<span[^>]*data-order-state="confirmed"[^>]*data-delivery-state="partial" data-billing-state="partial"/',
            $html, '⛔ আদেশের পাতায় মাথার চিপ নেই, বা ভুল।');
        $this->assertStringContainsString('data-line-state="open" data-delivery-state="full" data-billing-state="full"', $html,
            '⛔ পুরো যাওয়া লাইনের চিপ নেই।');
        $this->assertStringContainsString('data-line-state="open" data-delivery-state="none" data-billing-state="none"', $html,
            '⛔ কিছু না যাওয়া লাইনের চিপ নেই।');

        // ⓘ তালিকায়ও মাথার চিপ
        $list = $this->get(route('sales.order.index'))->assertOk();
        $this->assertSame(S::PARTIAL, $list->viewData('states')[$order->id]['billing'] ?? null);
        $this->assertStringContainsString('data-order-state="confirmed" data-delivery-state="partial" data-billing-state="partial"', $list->getContent());
    }

    /**
     * ⭐ নতুন অবস্থাগুলো যেমন জমা তেমন দেখায় (তালিকায় চিপ, বাংলায়), শেষ অবস্থাগুলো ইতিহাসে; আর আজকের সারি ডিফল্টে
     * আজকের নিয়মেই থাকে — `ledger`, অগ্রগতি none, লাইন open।
     */
    public function test_every_stored_status_shows_as_it_is_and_old_rows_keep_todays_rule(): void
    {
        $this->assertSame(DocumentStatus::DRAFT, S::DRAFT);
        $this->assertSame(DocumentStatus::CONFIRMED, S::CONFIRMED, '⛔ সংরক্ষিত আদেশের মান বদলালে আজকের সব খোলা আদেশ হারাত।');
        $this->assertSame(DocumentStatus::CLOSED, S::CLOSED);
        $this->assertSame(DocumentStatus::CANCELLED, S::CANCELLED);
        $this->assertContains(S::SOURCE_OFFICE, S::SOURCES, '⛔ অফিসের উৎস নেই (সমন্বয়কের উত্তর, প্রশ্ন ৭)।');

        $fresh = $this->draft([[$this->product, '1']])->fresh(['lines']);
        $this->assertSame(S::HOLD_LEDGER, $fresh->hold_mode, '⛔ নতুন ঘরের ডিফল্ট আজকের নিয়ম নয় — চালান ভুল পথে সংরক্ষণ ছাড়ত।');
        $this->assertSame([S::NONE, S::NONE], [$fresh->delivery_status, $fresh->billing_status]);
        $this->assertSame(S::LINE_OPEN, $fresh->lines->first()->line_status);
        $this->assertSame(0, bccomp('0', (string) $fresh->lines->first()->rejected_qty, 4));

        $orders = [];
        foreach (S::ALL as $status) {
            $orders[$status] = $this->draft([[$this->product, '1']]);
            DB::table('sal_orders')->where('company_id', $this->company->id)->where('id', $orders[$status]->id)
                ->update(['status' => $status]);
        }

        $html = $this->get(route('sales.order.index', ['cancelled' => 1]))->assertOk()->getContent();
        foreach (S::ALL as $status) {
            $this->assertStringContainsString('data-order-state="'.$status.'"', $html, "⛔ '{$status}' অবস্থার চিপ তালিকায় নেই।");
        }

        $this->assertSame('সীমায় আটকে', __('sales::order_status.state.credit_held', [], 'bn'));
        $this->assertSame('অনুমোদনের অপেক্ষায়', __('sales::order_status.state.awaiting_approval', [], 'bn'));

        $history = collect($this->get(route('sales.order.index', ['tab' => OrderTracking::LIST_HISTORY]))->viewData('orders')->items())->pluck('id')->all();
        foreach (S::FINISHED as $status) {
            $this->assertContains($orders[$status]->id, $history, "⛔ '{$status}' আদেশ ইতিহাসে নেই।");
        }
        $this->assertNotContains($orders[S::AWAITING_APPROVAL]->id, $history, '⛔ সইয়ের অপেক্ষার আদেশ ইতিহাসে চলে গেছে।');
    }

    /**
     * ⭐ ব্যাক অর্ডার — সংরক্ষিত লাইনের বাকি মাল আদেশের গুদামের তাকের চেয়ে বেশি (মালিকের ট্যাবের সংজ্ঞা)।
     */
    public function test_a_line_short_of_the_shelf_is_a_back_order(): void
    {
        app(SettingsService::class)->set('sales.allow_negative_stock', true);
        $floor = app(StockService::class)->statesFor($this->second, $this->warehouse)['floor'];

        $order = $this->approved([[$this->product, '2'], [$this->second, bcadd((string) $floor, '5', 4)]]);
        [$a, $b] = $order->lines->pluck('id')->all();

        $p = $this->progressOf($order);
        $this->assertTrue($p['back'], '⛔ তাকে নেই এমন মাল চাওয়া আদেশ ব্যাক অর্ডার নয়।');
        $this->assertTrue($p['lines'][$b]['back']);
        $this->assertFalse($p['lines'][$a]['back'], '⛔ তাকে থাকা মালের লাইনও ব্যাক অর্ডার বলছে।');

        // ⓘ চিপ আর ট্যাব এক কথা বলে
        $back = $this->get(route('sales.order.index', ['tab' => OrderTracking::LIST_BACK]))->assertOk();
        $this->assertContains($order->id, collect($back->viewData('orders')->items())->pluck('id')->all());
        $this->assertStringContainsString('data-back-order', $back->getContent(), '⛔ তালিকায় ব্যাক অর্ডারের চিপ নেই।');
    }

    /**
     * ⭐ বাতিলে কারণ বাধ্যতামূলক, আর কারণটা থাকে — পাতায় দেখা যায়।
     */
    public function test_a_cancel_needs_a_reason_and_keeps_it(): void
    {
        $order = $this->approved([[$this->product, '3']]);

        $this->post(route('sales.order.cancel', $order), [])->assertSessionHasErrors('reason');
        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ কারণ ছাড়াই বাতিল হয়ে গেছে।');

        $this->post(route('sales.order.cancel', $order), ['reason' => 'ডিলার দোকান বন্ধ করেছেন'])->assertRedirect();
        $this->assertSame('ডিলার দোকান বন্ধ করেছেন', $order->fresh()->cancel_reason);
        $this->assertProgress($order, S::CANCELLED, S::NONE, S::NONE);

        $this->get(route('sales.order.show', $order))->assertOk()
            ->assertSee('data-cancel-reason', false)
            ->assertSee('ডিলার দোকান বন্ধ করেছেন')
            ->assertSee('data-order-state="cancelled"', false);
    }

    /**
     * ⭐ বন্ধের নিয়ম — কেবল সংরক্ষিত; কিছু না গেলে বাতিল; কম রেখে বন্ধে কারণ লাগে, বাকিটা "আর দেওয়া হবে না" হয় আর ধরা মাল
     * ছাড়ে; বন্ধ আদেশ বাতিল নয়।
     */
    public function test_the_close_rules(): void
    {
        $orders = app(SalesOrderService::class);
        $reserved = fn (): string => (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];
        $before = $reserved();

        $draft = $this->draft([[$this->product, '1']]);
        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->close($draft->fresh(), 'কারণ'), 'status'));

        $untouched = $this->approved([[$this->product, '2']]);
        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->close($untouched->fresh(), 'কারণ'), 'status'),
            '⛔ কিছুই না যাওয়া আদেশ বন্ধ হয়ে গেল — ওটা বাতিলের কাজ।');
        $orders->cancel($untouched->fresh(), 'পরীক্ষা');

        $order = $this->approved([[$this->product, '10']]);
        $this->deliver($order, ['first' => '4']);
        $this->assertSame(0, bccomp(bcadd($before, '6', 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — ৬টা ধরা থাকার কথা।');

        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->close($order->fresh()), 'close_reason'),
            '⛔ পুরো বিল না হওয়া আদেশ কারণ ছাড়াই বন্ধ হয়ে গেল।');

        $orders->close($order->fresh(), 'ডিলার বাকি ৬টা আর নেবেন না');
        $closed = $order->fresh(['lines']);
        $this->assertSame(S::CLOSED, $closed->status);
        $this->assertSame('ডিলার বাকি ৬টা আর নেবেন না', $closed->close_reason);
        $this->assertSame(0, bccomp('6', (string) $closed->lines->first()->rejected_qty, 4), '⛔ বাকি ৬টা "আর দেওয়া হবে না" হয়নি।');
        $this->assertSame('ডিলার বাকি ৬টা আর নেবেন না', $closed->lines->first()->reject_reason);
        $this->assertSame(S::LINE_CLOSED, $closed->lines->first()->line_status);
        // ⓘ যা যাবে না তা আর পাওনা নয় — চালান "পুরো", বিল বাকি
        $this->assertSame([S::FULL, S::NONE], [$closed->delivery_status, $closed->billing_status]);
        $this->assertSame(0, bccomp($before, $reserved(), 4), '⛔ কম রেখে বন্ধ, অথচ বাকি ৬টা এখনো আদেশের নামে ধরা।');

        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->cancel($order->fresh(), 'আবার'), 'status'),
            '⛔ বন্ধ আদেশ বাতিল হয়ে গেল।');
        $this->assertSame(0, bccomp($before, $reserved(), 4), '⛔ বন্ধ আদেশের বাতিলে মাল দ্বিতীয়বার ছাড়া হয়েছে।');

        // ⓘ বন্ধ আদেশ ইতিহাসে, আংশিকে নয়
        $ids = fn (string $tab) => collect($this->get(route('sales.order.index', ['tab' => $tab]))->viewData('orders')->items())->pluck('id')->all();
        $this->assertContains($order->id, $ids(OrderTracking::LIST_HISTORY), '⛔ বন্ধ আদেশ ইতিহাসে নেই।');
        $this->assertNotContains($order->id, $ids(OrderTracking::LIST_PARTIAL), '⛔ বন্ধ আদেশ এখনো "আংশিক" ট্যাবে।');

        $this->get(route('sales.order.show', $order))->assertOk()
            ->assertSee('data-close-reason', false)
            ->assertSee('data-line-state="closed"', false)
            ->assertDontSee('data-close-order', false);
    }

    /**
     * ⭐ বন্ধের চাবি — একই মানুষ, চাবি বন্ধ তারপর খোলা: দরজা, আর পাতার ফর্ম।
     */
    public function test_closing_asks_for_its_own_key(): void
    {
        $order = $this->approved([[$this->product, '10']]);
        $this->deliver($order, ['first' => '4']);

        $clerk = $this->member();
        $clerk->givePermissionTo(['sales.order.view', 'sales.order.update', 'sales.order.cancel']);
        $clerk = $clerk->fresh();

        $this->actingAs($clerk)->get(route('sales.order.show', $order))->assertOk()->assertDontSee('data-close-order', false);
        $this->actingAs($clerk)->post(route('sales.order.close', $order), ['close_reason' => 'বাকি নেবেন না'])->assertForbidden();
        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ চাবি ছাড়াই আদেশ বন্ধ হয়ে গেছে।');

        $clerk->givePermissionTo('sales.order.close');
        $clerk = $clerk->fresh();

        $this->actingAs($clerk)->get(route('sales.order.show', $order))->assertOk()->assertSee('data-close-order', false);
        $this->actingAs($clerk)->post(route('sales.order.close', $order), ['close_reason' => 'বাকি নেবেন না'])
            ->assertRedirect(route('sales.order.show', $order));
        $this->assertSame(S::CLOSED, $order->fresh()->status, '⛔ চাবি পেয়েও আদেশ বন্ধ হয়নি।');
        $this->assertSame((int) $clerk->id, (int) $order->fresh()->closed_by);
    }

    /**
     * ⭐ তিন দিনের খসড়া তালিকায় লাল, "পুরনো খসড়া" ট্যাবে, আর হোম পর্দার ঘরে গোনা; দুই দিনেরটা নয় — যতক্ষণ না আরেক দিন
     * যায়। জমা দেওয়া পুরনো আদেশ খসড়া নয়। ঘরটা কেবল আদেশ দেখার চাবি যাঁর (একই মানুষ, বন্ধ তারপর খোলা)।
     */
    public function test_a_three_day_old_draft_is_red_and_counted_and_a_two_day_old_is_not(): void
    {
        $baseline = $this->tileValue($this->owner);

        $old = $this->draft([[$this->product, '1']]);
        $young = $this->draft([[$this->product, '1']]);
        $sent = $this->draft([[$this->product, '1']]);
        $this->age($old, 3);
        $this->age($young, 2);
        $this->age($sent, 5);
        DB::table('sal_orders')->where('company_id', $this->company->id)->where('id', $sent->id)
            ->update(['status' => S::SUBMITTED, 'submitted_at' => now()]);

        $list = $this->get(route('sales.order.index'))->assertOk();
        $states = $list->viewData('states');
        $this->assertTrue($states[$old->id]['stale'], '⛔ তিন দিনের খসড়া "পুরনো" নয়।');
        $this->assertFalse($states[$young->id]['stale'], '⛔ দুই দিনের খসড়াই লাল হয়ে গেছে।');
        $this->assertFalse($states[$sent->id]['stale'], '⛔ জমা দেওয়া আদেশ "পুরনো খসড়া" বলছে।');
        $this->assertMatchesRegularExpression('/data-stale-draft\s+class="[^"]*color-badge-danger-bg/', $list->getContent(),
            '⛔ পুরনো খসড়ার চিপ লাল নয়।');
        // ⓘ বয়স লেখার মুহূর্ত থেকে, পাশে কাগজের তারিখ (সমন্বয়ক, ৪ অক্টোবর ২০২৬)
        $this->assertMatchesRegularExpression(
            '/data-stale-paper>[^<]*'.preg_quote(\App\Core\Support\DateFormat::format($old->fresh()->trx_date), '/').'/',
            $list->getContent(), '⛔ পুরনো খসড়ার চিপে কাগজের তারিখ নেই।');

        $tab = $this->get(route('sales.order.index', ['tab' => OrderTracking::LIST_STALE]))->assertOk();
        $shown = collect($tab->viewData('orders')->items())->pluck('id')->all();
        $this->assertContains($old->id, $shown);
        $this->assertNotContains($young->id, $shown);
        $this->assertNotContains($sent->id, $shown);
        $this->assertSame(count($shown), collect($tab->viewData('tabs'))->pluck('count', 'key')->all()[OrderTracking::LIST_STALE] ?? null,
            '⛔ "পুরনো খসড়া" ট্যাবের গোনা আর সারি আলাদা।');

        $this->assertSame($baseline + 1, $this->tileValue($this->owner), '⛔ হোম পর্দার ঘর তিন দিনের খসড়াটা গোনেনি, বা দুই দিনেরটাও গুনেছে।');

        // ⓘ আরেক দিন গেলে দুই দিনেরটাও তিন দিনের
        $this->travel(1)->days();
        $this->assertSame($baseline + 2, $this->tileValue($this->owner), '⛔ একদিন পরে দুই দিনের খসড়াটা গোনায় আসেনি।');

        // ⭐ চাবি — একই মানুষ, বন্ধ তারপর খোলা
        $clerk = $this->member();
        $this->assertNull($this->tileValue($clerk, false), '⛔ আদেশ দেখার চাবি ছাড়াই পুরনো খসড়ার ঘর দেখা যাচ্ছে।');
        $clerk->givePermissionTo('sales.order.view');
        $this->assertNotNull($this->tileValue($clerk->fresh(), false), '⛔ চাবি পেয়েও ঘরটা নেই।');
    }

    /**
     * ⭐ বাতিল আর বন্ধ ছাড়ে কেবল এই আদেশ নিজে যা ধরেছিল (সমন্বয়ক, ৪ অক্টোবর ২০২৬) — একই মানুষ, সংরক্ষণের সুইচ বন্ধ তারপর চালু।
     *
     * ⛔ সুইচ বন্ধে নিশ্চিত হওয়া আদেশ কিছুই ধরেনি; বাতিলে বা বন্ধে সে অন্য আদেশের ধরা মাল ছেড়ে দিলে সেই মাল আবার বেচা যেত।
     */
    public function test_cancel_and_close_release_only_what_this_order_held(): void
    {
        $orders = app(SalesOrderService::class);
        $settings = app(SettingsService::class);
        $reserved = fn (): string => (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];
        $before = $reserved();

        // ⓘ অন্য একটা আদেশ ৫টা ধরে রাখে — এটাই যেন কেউ ভুল করে না ছাড়ে
        $this->approved([[$this->product, '5']]);
        $this->assertSame(0, bccomp(bcadd($before, '5', 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — অন্য আদেশের ৫টা ধরা নেই।');

        // ── সুইচ বন্ধ: নিশ্চিত হওয়া আদেশ কিছু ধরে না, তাই বাতিল আর বন্ধে কিছুই ছাড়ে না
        $settings->set('sales.reserve_on_order', false);
        $quiet = $this->approved([[$this->product, '3']]);
        $this->assertSame(0, bccomp(bcadd($before, '5', 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — সুইচ বন্ধেও মাল ধরা হয়েছে।');
        $orders->cancel($quiet->fresh(), 'পরীক্ষা');
        $this->assertSame(0, bccomp(bcadd($before, '5', 4), $reserved(), 4),
            '⛔ কিছু না ধরা আদেশের বাতিল অন্য আদেশের ধরা মাল ছেড়ে দিয়েছে।');

        $short = $this->approved([[$this->product, '4']]);
        $this->deliver($short, ['first' => '1']);
        /*
         * ⚠️ মাপা হয় বন্ধের ঠিক আগে-পরে: চালান নিশ্চিত হলে সে নিজেই আদেশের নামে "ধরা" ১টা ছাড়ে, আদেশ কিছু না ধরলেও
         * ([[DeliveryChallanService::releasableQty()]]) — সেটা চালানের ভুল, নকশার ধাপ ৫-এ (abos-86) ঠিক হবে; এই দাবি কেবল বন্ধের।
         */
        $atClose = $reserved();
        $orders->close($short->fresh(), 'বাকি নেবেন না');
        $this->assertSame(0, bccomp($atClose, $reserved(), 4),
            '⛔ কিছু না ধরা আদেশের বন্ধ অন্য আদেশের ধরা মাল ছেড়ে দিয়েছে।');

        // ── সুইচ চালু, একই মানুষ: এবার আদেশ নিজে ধরে, আর বাতিলে ঠিক ততটাই ছাড়ে
        $settings->set('sales.reserve_on_order', true);
        $base = $reserved();
        $held = $this->approved([[$this->product, '3']]);
        $this->assertSame(0, bccomp(bcadd($base, '3', 4), $reserved(), 4));
        $orders->cancel($held->fresh(), 'পরীক্ষা');
        $this->assertSame(0, bccomp($base, $reserved(), 4), '⛔ চালু সুইচে ধরা ৩টা বাতিলে ছাড়েনি।');
    }

    /**
     * ⛔ আংশিক চালানের পরে "এই আদেশ কতটা ধরে আছে" — মূল ধরা নয়, অবশিষ্ট (abos-86, ৪ অক্টোবর ২০২৬)।
     * ⓘ চালানের ছাড়া বসে চালানের নিজের উৎসে; শুধু আদেশের উৎস গুনলে ১০ ধরে ৬ ছাড়ার পরেও ১০ দেখাত, আর চালানের পথ
     * সেই সংখ্যা ধরে অন্য কাগজের মাল ছাড়ত। ⭐ বাতিলে ঠিক অবশিষ্ট ৪টা ছাড়ে, অন্য আদেশের ৫টা অক্ষত।
     */
    public function test_after_a_part_delivery_the_order_holds_only_what_is_left(): void
    {
        $orders = app(SalesOrderService::class);
        app(SettingsService::class)->set('sales.reserve_on_order', true);
        $reserved = fn (): string => (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];

        $this->approved([[$this->product, '5']]);
        $base = $reserved();

        $order = $this->approved([[$this->product, '10']]);
        $this->assertSame(0, bccomp(bcadd($base, '10', 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — আদেশ ১০টা ধরেনি।');

        $this->deliver($order, ['first' => '6']);
        $this->assertSame(0, bccomp(bcadd($base, '4', 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — চালান ৬টা ছাড়েনি।');

        // ⛔ একই আইডিতে অন্য নম্বরের "আদেশের" ধরা — ডেমো-বীজ যেমন বসায় (SO-000001, আইডি ১) — এই আদেশের নয় (৫ অক্টোবর ২০২৬)
        app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse,
            sourceType: SalesOrder::STOCK_SOURCE, sourceId: (int) $order->id, reserved: '7', documentNo: 'SO-SEED-'.$order->id);
        $base = bcadd($base, '7', 4);

        $held = $orders->heldByThisOrder($order->fresh());
        $this->assertSame(0, bccomp('4', $held[(int) $this->product->id] ?? '0', 4),
            '⛔ ৬টা চালানে যাওয়ার পরেও আদেশ বলছে সে '.($held[(int) $this->product->id] ?? '0').' ধরে আছে — অবশিষ্ট ৪।');

        $orders->cancel($order->fresh(), 'বাকি নেবেন না');
        $this->assertSame(0, bccomp($base, $reserved(), 4), '⛔ বাতিলে অবশিষ্ট ৪টাই ছাড়ার কথা — অন্য আদেশের ৫টা অক্ষত থাকবে।');
    }

    /**
     * ⛔ খসড়া চালান থাকা অবস্থায় আদেশ বাতিল — ধরা পুরোটাই ছাড়ে, আর খসড়া পরে বাতিল হলেও কিছু আটকে থাকে না
     * (অডিট ম১৫, ৬ অক্টোবর ২০২৬)। ⓘ খসড়া কিছু বের করেনি, তাই তার অংশের ধরাও আদেশের।
     */
    public function test_cancelling_an_order_with_a_draft_challan_releases_all_it_held(): void
    {
        app(SettingsService::class)->set('sales.reserve_on_order', true);
        $reserved = fn (): string => (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];
        $base = $reserved();

        $order = $this->approved([[$this->product, '10']]);
        $line = $order->fresh(['lines'])->lines->first();
        $draft = app(DeliveryChallanService::class)->create([
            'customer_id' => $order->customer_id, 'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id, 'trx_date' => now()->toDateString(),
        ], [['product_id' => $line->product_id, 'sales_order_line_id' => $line->id, 'delivered_qty' => '4', 'rate' => (string) $line->rate]]);
        $this->assertSame(0, bccomp(bcadd($base, '10', 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — খসড়া চালান ধরায় হাত দিল।');

        app(SalesOrderService::class)->cancel($order->fresh(), 'ডিলার না করেছেন');
        $this->assertSame(0, bccomp($base, $reserved(), 4), '⛔ খসড়া চালানের ৪টার ধরা আদেশ বাতিলের পরেও আটকে আছে।');

        app(DeliveryChallanService::class)->cancel($draft->fresh(), 'আদেশ বাতিল');
        $this->assertSame(0, bccomp($base, $reserved(), 4), '⛔ খসড়া বাতিলের পরে ধরা বদলাল।');
    }

    /**
     * ⛔ গেটে মাল ছাড়ার সুইচ চালু — নিশ্চিত চালানের ধরা (গেটের অপেক্ষায়) আদেশ বাতিলে ছাড়ে না; ছাড়ে কেবল যা বেরোয়নি
     * (অডিট ম১৫-এর সতর্কতা, ৬ অক্টোবর ২০২৬)। ⓘ "আদেশের সব ধরা ছাড়ো" হলে গেটে দাঁড়ানো মালও বিক্রিযোগ্য হয়ে যেত।
     */
    public function test_with_goods_issued_at_the_gate_a_confirmed_challan_keeps_its_hold_on_cancel(): void
    {
        app(SettingsService::class)->set('sales.reserve_on_order', true);
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $reserved = fn (): string => (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];

        $order = $this->approved([[$this->product, '10']]);
        $this->deliver($order, ['first' => '4']);
        $before = $reserved();

        app(SalesOrderService::class)->cancel($order->fresh(), 'বাকি নেবেন না');
        $this->assertSame(0, bccomp(bcsub($before, '6', 4), $reserved(), 4),
            '⛔ বাতিলে কেবল না-বেরোনো ৬টা ছাড়ার কথা — গেটের অপেক্ষার ৪টা ধরা থাকবে। আগে '.$before.', পরে '.$reserved());
    }

    /**
     * ⭐ পুরো বিল হলে আদেশ নিজেই বন্ধ — কারণ ছাড়া, মানুষ ছাড়া; আংশিক বিলে খোলা (সমন্বয়ক, ৬ অক্টোবর ২০২৬; ধাপ ১৪-এর পর্দার পরীক্ষা)।
     */
    public function test_an_order_closes_itself_once_everything_is_billed(): void
    {
        $order = $this->approved([[$this->product, '4']]);
        $challan = $this->deliver($order, ['first' => '4']);

        $this->bill($challan->fresh(['lines']), '2');
        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ অর্ধেক বিলেই আদেশ বন্ধ হয়ে গেল।');

        $this->bill($challan->fresh(['lines']), '2');
        $closed = $order->fresh();
        $this->assertSame(S::CLOSED, $closed->status, '⛔ পুরো বিল হয়েও আদেশ খোলা রইল।');
        $this->assertNull($closed->closed_by, 'নিজে বন্ধ — কোনো মানুষের নামে নয়।');
        $this->assertNull($closed->close_reason);
        $this->assertNotNull($closed->closed_at);
        // ⭐ হাতে বন্ধের একই পথ — লাইনগুলোও বন্ধ ([[SalesOrderService::close()]]-এর `itself`)
        $this->assertSame([S::LINE_CLOSED], $closed->lines()->pluck('line_status')->unique()->values()->all(), '⛔ আদেশ বন্ধ, অথচ লাইন খোলা।');
    }

    /**
     * ⭐ বন্ধ আর বাতিল নিজের ঘটনা ছোটায় — ঠিক একবার, আর থেমে যাওয়া কাজে একবারও নয় (abos-86-এর হোল্ড ছাড়ার জন্য)।
     */
    public function test_close_and_cancel_announce_themselves_once_and_never_when_refused(): void
    {
        Event::fake([SalesOrderClosed::class, SalesOrderCancelled::class]);
        $orders = app(SalesOrderService::class);

        $order = $this->approved([[$this->product, '10']]);
        $this->deliver($order, ['first' => '4']);

        // ⓘ থামা বন্ধ — কারণ নেই
        $this->refused(fn () => $orders->close($order->fresh()), 'close_reason');
        Event::assertNotDispatched(SalesOrderClosed::class);

        $orders->close($order->fresh(), 'বাকি নেবেন না');
        Event::assertDispatchedTimes(SalesOrderClosed::class, 1);
        Event::assertDispatched(SalesOrderClosed::class, fn (SalesOrderClosed $e) => $e->payload['sales_order_id'] === (int) $order->id
            && $e->payload['reason'] === 'বাকি নেবেন না' && $e->payload['hold_mode'] === S::HOLD_LEDGER);

        // ⓘ থামা বাতিল — বন্ধ আদেশ
        $this->refused(fn () => $orders->cancel($order->fresh(), 'আবার'), 'status');
        Event::assertNotDispatched(SalesOrderCancelled::class);

        $other = $this->approved([[$this->product, '2']]);
        $orders->cancel($other->fresh(), 'ডিলার না করেছেন');
        Event::assertDispatchedTimes(SalesOrderCancelled::class, 1);
        Event::assertDispatched(SalesOrderCancelled::class, fn (SalesOrderCancelled $e) => $e->payload['sales_order_id'] === (int) $other->id
            && $e->payload['reason'] === 'ডিলার না করেছেন');
        Event::assertDispatchedTimes(SalesOrderClosed::class, 1);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function progressOf(SalesOrder $order): array
    {
        return app(OrderProgress::class)->of(SalesOrder::query()->with('lines')->findOrFail($order->id));
    }

    /** একলাইনের (বা সব লাইন একই) আদেশের অবস্থা আর অগ্রগতি — মাথায় আর প্রতি লাইনে। */
    private function assertProgress(SalesOrder $order, string $status, string $delivery, string $billing): void
    {
        $p = $this->progressOf($order);
        $this->assertSame($status, $p['status'], "⛔ অবস্থা '{$p['status']}', হওয়ার কথা '{$status}'।");
        $this->assertSame($delivery, $p['delivery'], "⛔ মাথার চালান '{$p['delivery']}', হওয়ার কথা '{$delivery}'।");
        $this->assertSame($billing, $p['billing'], "⛔ মাথার বিল '{$p['billing']}', হওয়ার কথা '{$billing}'।");

        foreach ($p['lines'] as $line) {
            $this->assertSame([$delivery, $billing], [$line['delivery'], $line['billing']], '⛔ লাইনের অগ্রগতি মাথার সাথে মেলে না।');
        }
    }

    /** @param  list<array{0: Product, 1: string}>  $lines */
    private function draft(array $lines): SalesOrder
    {
        return app(SalesOrderService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], array_map(fn (array $l) => [
            'product_id' => $l[0]->id,
            'ordered_qty' => $l[1],
            'rate' => (string) $l[0]->sale_price,
        ], $lines));
    }

    /** @param  list<array{0: Product, 1: string}>  $lines */
    private function approved(array $lines): SalesOrder
    {
        return app(SalesOrderService::class)->confirm($this->draft($lines)->fresh(['lines']))->fresh(['lines']);
    }

    /**
     * আদেশের লাইন ধরে চালান — নিশ্চিত। ⓘ চাবি 'first' মানে প্রথম লাইন (একলাইনের আদেশে), নাহলে লাইনের আইডি।
     *
     * @param  array<int|string, string>  $qty
     */
    private function deliver(SalesOrder $order, array $qty): DeliveryChallan
    {
        $order = $order->fresh(['lines.product']);
        $rows = [];

        foreach ($qty as $key => $n) {
            /** @var SalesOrderLine $line */
            $line = $key === 'first' ? $order->lines->first() : $order->lines->firstWhere('id', (int) $key);
            $rows[] = [
                'product_id' => $line->product_id,
                'sales_order_line_id' => $line->id,
                'delivered_qty' => $n,
                'rate' => (string) $line->rate,
            ];
        }

        $challans = app(DeliveryChallanService::class);
        $paper = $challans->create([
            'customer_id' => $order->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
            'trx_date' => now()->toDateString(),
        ], $rows);

        return $challans->confirm($paper->fresh(['lines']))->fresh(['lines']);
    }

    /** চালানের প্রথম লাইনের বিল — পাকা। */
    private function bill(DeliveryChallan $challan, string $qty): void
    {
        $line = $challan->lines->first();
        $invoices = app(SalesInvoiceService::class);

        $invoice = $invoices->create([
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $line->product_id,
            'delivery_challan_line_id' => $line->id,
            'qty' => $qty,
            'rate' => (string) $line->rate,
        ]]);

        $invoices->confirm($invoice->fresh(['lines']));
    }

    private function member(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function refused(callable $act, string $field): string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) ($e->errors()[$field][0] ?? '');
        }

        $this->fail("⛔ কাজটা থামার কথা ছিল ('{$field}')।");
    }

    private function age(SalesOrder $order, int $days): void
    {
        DB::table('sal_orders')->where('company_id', $this->company->id)->where('id', $order->id)
            ->update(['created_at' => now()->subDays($days)->subMinute()]);
    }

    /** হোম পর্দার "পুরনো খসড়া আদেশ" ঘরের সংখ্যা — ঘর না থাকলে null (বা `$must` হলে ব্যর্থ)। */
    private function tileValue(User $user, bool $must = true): ?int
    {
        $label = __('sales::order_status.tile_stale');

        /** @var Widget|null $tile */
        $tile = collect(app(DashboardRegistry::class)->forUser($user)['todo'] ?? [])
            ->first(fn (Widget $w) => $w->label === $label);

        if ($tile === null) {
            if ($must) {
                $this->fail('⛔ হোম পর্দায় পুরনো খসড়া আদেশের ঘর নেই।');
            }

            return null;
        }

        $this->assertStringContainsString('tab='.OrderTracking::LIST_STALE, $tile->href, '⛔ ঘর চাপলে "পুরনো খসড়া" ট্যাব খোলে না।');

        return (int) $tile->value;
    }
}
