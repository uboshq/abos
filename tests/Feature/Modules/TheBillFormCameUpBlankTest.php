<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলের ফর্ম খালি আসত, আর কারণ বলত না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * মালিকের রিপোর্ট: *"PO থেকে সরাসরি Bill-এ গেলে ফর্ম খালি আসে"*।
 *
 * ব্রাউজারে দেখে বেরোল দুইটা আলাদা আচরণ:
 *   · **নিশ্চিত** আদেশে ফর্ম ঠিকই ভরে — সরবরাহকারী ও লাইন দুইটাই
 *   · **খসড়া** আদেশে ফর্ম সম্পূর্ণ খালি, আর একটাও শব্দ নয়
 *
 * নিয়মটা ঠিকই ছিল — খসড়া আদেশ সরবরাহকারীকে পাঠানোই হয়নি, তাই তার
 * বিপরীতে বিল আসার কথা নয়। ভাঙা ছিল **নীরবতাটা**: `chosenOrder()`
 * চুপচাপ `null` ফেরাত, আর পর্দা দেখতে ভাঙা লাগত।
 *
 * ── কেউ ওই অবস্থায় পৌঁছায় কীভাবে ────────────────────────────────────
 * আদেশের পাতা খসড়ায় লিংকটা দেখায়ই না। কিন্তু বুকমার্ক, ব্রাউজারের
 * পেছনে যাওয়া, বা কাউকে পাঠানো একটা লিংক — তিনটাতেই পৌঁছানো যায়।
 * আর তখন মানুষ ভাবেন ব্যবস্থাটা ভেঙেছে, অথচ ব্যবস্থাটা ঠিক কাজটাই
 * করছে।
 */
class TheBillFormCameUpBlankTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function order(bool $confirm): PurchaseOrder
    {
        $this->actingAs($this->owner);

        $order = app(PurchaseOrderService::class)->create([
            'supplier_id' => Supplier::query()->value('id'),
            'trx_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::query()->value('id'),
        ], [[
            'product_id' => Product::query()->value('id'),
            'ordered_qty' => '10',
            'rate' => '100',
        ]]);

        return $confirm ? app(PurchaseOrderService::class)->confirm($order) : $order;
    }

    /**
     * নিশ্চিত আদেশে ফর্ম সত্যিই ভরে।
     *
     * এটা আগে কোথাও পরীক্ষা করা ছিল না — তাই "ফর্ম খালি আসে" রিপোর্টটা
     * পেয়ে বলার উপায়ই ছিল না কোনটা ভাঙা: ভরার কোডটা, নাকি ব্যবহারের
     * পথটা।
     */
    public function test_a_confirmed_order_fills_the_bill_form(): void
    {
        $order = $this->order(confirm: true);

        $this->assertSame(DocumentStatus::CONFIRMED, $order->status);

        $this->actingAs($this->owner)
            ->get(route('purchase.bill.create', ['purchase_order_id' => $order->id]))
            ->assertOk()
            ->assertViewHas('order', fn (?PurchaseOrder $o) => $o?->id === $order->id)
            ->assertDontSee(__('purchase::message.order_not_confirmed', ['no' => $order->document_no]));
    }

    /**
     * খসড়া আদেশে ফর্ম খালিই থাকে — কিন্তু **কারণটা বলে**।
     *
     * এটাই এই ফাইলের আসল পরীক্ষা। খালি থাকাটা ঠিক; না বলাটা ছিল ভুল।
     */
    public function test_a_draft_order_says_why_instead_of_going_blank(): void
    {
        $order = $this->order(confirm: false);

        $this->assertSame(DocumentStatus::DRAFT, $order->status);

        $this->actingAs($this->owner)
            ->get(route('purchase.bill.create', ['purchase_order_id' => $order->id]))
            ->assertOk()
            ->assertViewHas('order', null)
            ->assertSee(__('purchase::message.order_not_confirmed', ['no' => $order->document_no]));
    }

    /** নেই এমন আদেশেও একটা বাক্য — শূন্য পর্দা নয়। */
    public function test_an_unknown_order_says_so_too(): void
    {
        $this->actingAs($this->owner)
            ->get(route('purchase.bill.create', ['purchase_order_id' => 999999]))
            ->assertOk()
            ->assertSee(__('purchase::message.order_not_found'));
    }

    /**
     * আদেশ না চাইলে কোনো বার্তা নয়।
     *
     * "নতুন বিল" চেপে আসা মানুষটা কোনো আদেশ চাননি — তাঁকে আদেশ নিয়ে
     * কিছু বলা মানে একটা ভুল বার্তা, আর ভুল বার্তা পরেরবার সত্যিকারেরটাও
     * উপেক্ষা করায়।
     */
    public function test_a_plain_new_bill_says_nothing_about_orders(): void
    {
        $this->actingAs($this->owner)
            ->get(route('purchase.bill.create'))
            ->assertOk()
            ->assertViewHas('why', null)
            ->assertDontSee(__('purchase::message.order_not_found'));
    }
}
