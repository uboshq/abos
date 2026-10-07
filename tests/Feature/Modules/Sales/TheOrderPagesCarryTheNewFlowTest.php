<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * আদেশের তালিকা আর পাতা নতুন ধারা বহন করে (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৮; মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান)।
 *
 * ⓘ তালিকা: খসড়া · সইয়ের অপেক্ষায় (ভিতরে "কেবল আমার") · সীমায় আটকে · ডিপো যাচাইয়ে, আর খোলা DO থাকলে "পুরনো DO"।
 * ⓘ পাতা: "জমা দিন", সইয়ের অপেক্ষা, সীমায় আটকে, ধরা মাল, সুপারভাইজারের পরিমাণ, এক লাইনের বাকিটা বন্ধ। প্রতিটা দরজা —
 * একই মানুষ, চাবি বন্ধ তারপর খোলা।
 */
final class TheOrderPagesCarryTheNewFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(SettingsService::class)->set('sales.screen_orders', true);
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        Customer::query()->whereKey($this->customer->id)->update(['credit_limit' => '100000000']);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    /**
     * ⭐ চারটা নতুন ট্যাব — প্রতিটায় কেবল তার নিজের আদেশ, আর পাশের গোনা সারির সাথে মেলে; সইয়ের ট্যাবে "কেবল আমার"।
     */
    public function test_each_new_tab_shows_only_its_own_orders(): void
    {
        $draft = $this->draft('2');

        $this->replaceDo(true);
        // ⓘ ছকের আগে — ছক থাকলে আদেশ সইয়ের অপেক্ষায় থামত, ডিপোতে পৌঁছাত না
        $depot = $this->confirmedNewFlow('5');
        $depot->enterDepotCheck();
        // ⓘ সংরক্ষিত, কিন্তু ডিপোতে তোলা হয়নি — "ডিপো যাচাইয়ে"-র সারিতেও নয়, গোনাতেও নয়
        $reservedOnly = $this->confirmedNewFlow('1');
        $this->assertNull($reservedOnly->fresh()->depot_check_at);

        $signer = $this->signerFlow();
        $orders = app(SalesOrderService::class);
        $awaiting = $orders->submit($this->draft('3')->fresh(['lines']));

        $poor = Customer::query()->create(['code' => 'OS8-POOR', 'name_en' => 'Short Limit', 'name_bn' => 'কম সীমা', 'is_active' => true]);
        Customer::query()->whereKey($poor->id)->update(['credit_limit' => '0']);
        $held = $orders->submit($this->draft('4', $poor)->fresh(['lines']));

        $this->assertSame([S::DRAFT, S::AWAITING_APPROVAL, S::CREDIT_HELD, S::CONFIRMED],
            [$draft->fresh()->status, $awaiting->fresh()->status, $held->fresh()->status, $depot->fresh()->status],
            'প্রস্তুতিটাই ভুল — চার আদেশ চার অবস্থায় নেই।');

        foreach ([
            OrderTracking::LIST_DRAFT => [$draft->id],
            OrderTracking::LIST_AWAITING => [$awaiting->id],
            OrderTracking::LIST_CREDIT_HELD => [$held->id],
            OrderTracking::LIST_DEPOT => [$depot->id],
        ] as $tab => $want) {
            $page = $this->get(route('sales.order.index', ['tab' => $tab]))->assertOk();
            $shown = collect($page->viewData('orders')->items())->pluck('id')->sort()->values()->all();
            $this->assertSame($want, $shown, "⛔ '{$tab}' ট্যাবে ভুল আদেশ।");
            $this->assertSame(count($want), collect($page->viewData('tabs'))->pluck('count', 'key')->all()[$tab] ?? null,
                "⛔ '{$tab}' ট্যাবের গোনা আর সারি আলাদা।");
        }

        // ⓘ "কেবল আমার সইয়ের অপেক্ষায়" — সইদাতা দেখেন, অন্য কেউ দেখেন না
        $clerk = $this->member(['sales.order.view']);
        $mine = fn (User $u) => collect($this->actingAs($u)->get(route('sales.order.index', ['tab' => OrderTracking::LIST_AWAITING, 'mine' => 1]))
            ->assertOk()->viewData('orders')->items())->pluck('id')->all();
        $signer->givePermissionTo('sales.order.view');
        $this->assertSame([$awaiting->id], $mine($signer->fresh()), '⛔ সইদাতা নিজের সইয়ের অপেক্ষার আদেশ দেখেন না।');
        $this->assertSame([], $mine($clerk), '⛔ যিনি সই দেন না, "কেবল আমার"-এ তিনিও আদেশ দেখেন।');
    }

    /**
     * ⭐ "পুরনো DO" ট্যাব — কেবল খোলা DO থাকলে আর DO দেখার চাবি থাকলে; একই মানুষ, চাবি বন্ধ তারপর খোলা।
     */
    public function test_the_old_do_tab_shows_only_with_open_dos_and_the_key(): void
    {
        $user = $this->member(['sales.order.view']);
        $keys = fn () => collect($this->actingAs($user->fresh())->get(route('sales.order.index'))->assertOk()->viewData('tabs'))->pluck('key')->all();

        DeliveryOrder::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'DO-OS8-1', 'customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(),
            'status' => DeliveryOrderStatus::ACCOUNTS_APPROVED, 'subtotal' => '100', 'total' => '100',
        ]);

        $this->assertNotContains('old_do', $keys(), '⛔ DO দেখার চাবি ছাড়াই "পুরনো DO" ট্যাব।');

        $user->givePermissionTo('sales.do.view');
        $tabs = collect($this->actingAs($user->fresh())->get(route('sales.order.index'))->viewData('tabs'))->keyBy('key');
        $this->assertTrue($tabs->has('old_do'), '⛔ চাবি আর খোলা DO থাকতেও "পুরনো DO" ট্যাব নেই।');
        $this->assertSame(1, $tabs['old_do']['count']);
        $this->assertSame(route('sales.delivery_order.index'), $tabs['old_do']['url'], '⛔ ট্যাবটা DO-র ডেস্কে যায় না।');

        DeliveryOrder::query()->update(['status' => DeliveryOrderStatus::INVOICED]);
        $this->assertNotContains('old_do', $keys(), '⛔ সব DO শেষ, তবু "পুরনো DO" ট্যাব।');
    }

    /**
     * ⭐ সুপারভাইজার পাতা থেকে পরিমাণ কমান — চাবি বন্ধে দরজা ৪০৩, খুললে কমে; ফর্ম কেবল এখনকার অনুমোদনকারী দেখেন; বাড়ানো নয়।
     */
    public function test_the_supervisor_lowers_the_quantity_on_the_page(): void
    {
        $this->replaceDo(true);
        $signer = $this->signerFlow();
        $order = app(SalesOrderService::class)->submit($this->draft('10')->fresh(['lines']));
        $line = $order->lines->first();
        $this->assertSame(S::AWAITING_APPROVAL, $order->status);

        // ⓘ চাবি বন্ধ — আদেশ দেখার চাবিই নেই
        $this->actingAs($signer)->post(route('sales.order.quantities', $order), ['qty' => [$line->id => '6']])->assertForbidden();
        $this->assertSame(0, bccomp('10', (string) $line->fresh()->ordered_qty, 4));

        $signer->givePermissionTo('sales.order.view');
        $signer = $signer->fresh();

        $html = $this->actingAs($signer)->get(route('sales.order.show', $order))->assertOk()->getContent();
        $this->assertStringContainsString('data-order-approval-box', $html, '⛔ সইয়ের অপেক্ষার বাক্স নেই।');
        $this->assertStringContainsString('data-lower-quantities', $html, '⛔ অনুমোদনকারী পরিমাণের ফর্ম পান না।');

        $this->actingAs($signer)->post(route('sales.order.quantities', $order), ['qty' => [$line->id => '11']])
            ->assertSessionHasErrors('lines.'.$line->id);
        $this->actingAs($signer)->post(route('sales.order.quantities', $order), ['qty' => [$line->id => '6']])
            ->assertRedirect(route('sales.order.show', $order));
        $this->assertSame(0, bccomp('6', (string) $line->fresh()->ordered_qty, 4), '⛔ পাতা থেকে পরিমাণ কমেনি।');

        // ⓘ যিনি সই দেন না — ফর্ম নেই, দরজা ৪০৩
        $clerk = $this->member(['sales.order.view']);
        $this->assertStringNotContainsString('data-lower-quantities',
            $this->actingAs($clerk)->get(route('sales.order.show', $order))->assertOk()->getContent(),
            '⛔ অনুমোদনকারী নন এমন মানুষ পরিমাণের ফর্ম পান।');
        $this->actingAs($clerk)->post(route('sales.order.quantities', $order), ['qty' => [$line->id => '1']])->assertForbidden();

        $html = $this->actingAs($this->owner)->get(route('sales.order.show', $order))->getContent();
        $this->assertStringContainsString(__('sales::order_status.requested', ['qty' => '10']), $html, '⛔ চাওয়া পরিমাণ পাতায় নেই।');
    }

    /**
     * ⭐ এক লাইনের বাকিটা বন্ধ — পাতা থেকে; চাবি বন্ধে ফর্ম নেই আর দরজা ৪০৩, খুললে বন্ধ হয়; কারণ ছাড়া নয়।
     */
    public function test_closing_the_rest_of_a_line_on_the_page_asks_for_its_key_and_a_reason(): void
    {
        $order = app(SalesOrderService::class)->confirm($this->draft('10')->fresh(['lines']));
        $line = $order->fresh(['lines'])->lines->first();
        $this->deliver($order, $line->id, '4');

        $user = $this->member(['sales.order.view']);
        $this->assertStringNotContainsString('data-reject-rest',
            $this->actingAs($user)->get(route('sales.order.show', $order))->assertOk()->getContent(),
            '⛔ চাবি ছাড়াই বাকি বন্ধের ফর্ম।');
        $this->actingAs($user)->post(route('sales.order.reject_rest', $order),
            ['line_id' => $line->id, 'reject_qty' => '2', 'reject_reason' => 'কারণ'])->assertForbidden();

        $user->givePermissionTo('sales.order.close');
        $user = $user->fresh();

        $this->assertStringContainsString('data-reject-rest',
            $this->actingAs($user)->get(route('sales.order.show', $order))->assertOk()->getContent(),
            '⛔ চাবি পেয়েও বাকি বন্ধের ফর্ম নেই।');
        $this->actingAs($user)->post(route('sales.order.reject_rest', $order),
            ['line_id' => $line->id, 'reject_qty' => '2', 'reject_reason' => ''])->assertSessionHasErrors('reject_reason');
        $this->actingAs($user)->post(route('sales.order.reject_rest', $order),
            ['line_id' => $line->id, 'reject_qty' => '2', 'reject_reason' => 'ডিলার দুইটা কম নেবেন'])
            ->assertRedirect(route('sales.order.show', $order));
        $this->assertSame(0, bccomp('2', (string) $line->fresh()->rejected_qty, 4), '⛔ পাতা থেকে বাকিটা বন্ধ হয়নি।');
    }

    /**
     * ⭐ পাতার বাক্স আর বোতাম — নতুন ধারায় "জমা দিন"; সীমায় আটকে থাকলে কত কম; সংরক্ষিত আদেশে ধরা মাল।
     */
    public function test_the_page_says_submit_shows_the_credit_hold_and_the_held_stock(): void
    {
        app(\App\Core\Services\SettingsService::class)->set('sales.reserve_on_order', true); // ⓘ এই দাবির প্রশ্ন আদেশে ধরা — ডিফল্ট এখন চালানে (মালিক, ৬ অক্টোবর ২০২৬)
        $ledger = app(SalesOrderService::class)->confirm($this->draft('3')->fresh(['lines']));
        $html = $this->get(route('sales.order.show', $ledger))->assertOk()->getContent();
        $this->assertStringContainsString('data-held-stock', $html, '⛔ সংরক্ষিত আদেশের পাতায় ধরা মালের বাক্স নেই।');

        $draft = $this->draft('2');
        $page = $this->get(route('sales.order.show', $draft))->assertOk();
        $this->assertStringNotContainsString('>'.__('sales::order_status.submit').'<', $page->getContent(), '⛔ পুরনো ধারায় "জমা দিন"।');

        $this->replaceDo(true);
        $page = $this->get(route('sales.order.show', $draft))->assertOk();
        $this->assertMatchesRegularExpression('/data-overview-trigger[^>]*>\s*'.preg_quote(__('sales::order_status.submit'), '/').'\s*</',
            $page->getContent(), '⛔ নতুন ধারায় বোতাম "জমা দিন" বলে না।');

        Customer::query()->whereKey($this->customer->id)->update(['credit_limit' => '0']);
        $held = app(SalesOrderService::class)->submit($this->draft('4')->fresh(['lines']));
        $this->assertSame(S::CREDIT_HELD, $held->status, 'প্রস্তুতিটাই ভুল — আদেশ সীমায় আটকায়নি।');
        $this->get(route('sales.order.show', $held))->assertOk()->assertSee('data-credit-held', false);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function replaceDo(bool $on): void
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, $on);
        $this->rebuild();
    }

    private function rebuild(): void
    {
        foreach ([ApprovalEngine::class, DocumentApproval::class, SalesOrderService::class] as $class) {
            app()->forgetInstance($class);
        }
    }

    private function signerFlow(): User
    {
        $signer = $this->member([]);
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'order']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id,
        ]);
        $this->rebuild();

        return $signer;
    }

    /** @param  list<string>  $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        if ($keys !== []) {
            $user->givePermissionTo($keys);
        }

        return $user->fresh();
    }

    private function draft(string $qty, ?Customer $customer = null): SalesOrder
    {
        return app(SalesOrderService::class)->create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => (string) $this->product->sale_price]]);
    }

    /** নতুন ধারায় জমা, সই ছাড়া অনুমোদিত, মাল ধরে সংরক্ষিত (abos-86-এর শ্রোতা) */
    private function confirmedNewFlow(string $qty): SalesOrder
    {
        $order = app(SalesOrderService::class)->submit($this->draft($qty)->fresh(['lines']));
        $order = $order->fresh();

        if ($order->status === S::APPROVED) {
            $order = app(SalesOrderService::class)->markConfirmed($order);
        }

        return $order->fresh(['lines']);
    }

    private function deliver(SalesOrder $order, int $lineId, string $qty): void
    {
        $challans = app(DeliveryChallanService::class);
        $paper = $challans->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'sales_order_line_id' => $lineId, 'delivered_qty' => $qty, 'rate' => (string) $this->product->sale_price]]);

        $challans->confirm($paper->fresh(['lines']));
    }
}
