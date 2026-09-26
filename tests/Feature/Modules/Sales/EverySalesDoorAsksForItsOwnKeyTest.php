<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * বিক্রয়ের আটটা দরজায় কেউ কোনোদিন HTTP দিয়ে কড়া নাড়েনি।
 *
 * ── ⛔ যা ধরা পড়েছিল (abos-d3, ২৬ সেপ্টেম্বর ২০২৬) ─────────────────────
 * চালান, আদেশ, বিল আর আদায়ের নিশ্চিত/বাতিলের দরজাগুলোর চাবি বসানো আছে
 * কন্ট্রোলারের `middleware()`-এ — কিন্তু কোনো পরীক্ষা ঐ রুটগুলোতে একটাও
 * অনুরোধ পাঠায় না। ⚠️ সেবার পরীক্ষাগুলো সেবা সরাসরি ডাকে, তাই `can:`
 * লাইনটা মুছে গেলেও সবকিছু সবুজ থাকত, আর দরজাটা সবার জন্য খুলে যেত।
 *
 * ── ⓘ প্রতিটা দরজায় জোড়া দাবি, একই মানুষ, একই কাগজে ──────────────────
 *   ১. তাঁর হাতে **ঐ একটা চাবি ছাড়া সব** আছে → ৪০৩, আর কাগজের অবস্থা
 *      অক্ষত।
 *   ২. একই মানুষকে ঐ চাবিটা দেওয়া হয় → কাজটা হয়, অবস্থা বদলায়।
 *
 * ⚠️ ক্রমটা ইচ্ছাকৃত: (২) যদি না চলত, তাহলে (১)-এর "অবস্থা অক্ষত"
 * কথাটা কিছুই প্রমাণ করত না — কাগজটা হয়তো এমনিতেই বদলানোর মতো
 * অবস্থায় ছিল না, বা পর্দার সুইচ সবাইকেই আটকাচ্ছিল। ⓘ আর দুই অনুরোধের
 * মধ্যে তফাত কেবল ঐ একটা চাবি, তাই দরজাটা ঠিক **ঐ চাবিরই**।
 *
 * ⚠️ রুটের নাম আর চাবির নাম route:list আর কন্ট্রোলারের `middleware()`
 * থেকে নেওয়া, অনুমান থেকে নয়। ⓘ আদেশ নিশ্চিত করার চাবি `update`,
 * `create` নয় — চালান, বিল আর আদায়ে `create`।
 */
final class EverySalesDoorAsksForItsOwnKeyTest extends TestCase
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

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /*
         * ⚠️ চালানে এখন বাকির সীমার দেয়াল আছে। ⓘ সীমাটা বড় রাখা হয় যাতে
         * দরজার দাবিটা দেয়ালে না ঠেকে — এখানে মাপা হচ্ছে চাবি, সীমা নয়।
         */
        $this->customer->forceFill(['credit_limit' => '100000000'])->save();

        /*
         * ⚠️ পর্দার সুইচ অনুমতির **আগে** চলে ([[RefuseSwitchedOffScreens]])।
         * ⓘ দুইটাই আজ ডিফল্টে চালু, তবু স্পষ্ট করে বসানো — ডিফল্ট বদলালে
         * দরজা সবার জন্য বন্ধ হত, আর "চাবি ছাড়া ঢোকা যায় না" দাবিটা
         * ভুল কারণে সবুজ থাকত। বিল আর আদায়ের কোনো সুইচ নেই।
         */
        app(SettingsService::class)->set('sales.screen_orders', true);
        app(SettingsService::class)->set('sales.screen_challans', true);
    }

    // ── আদেশ ─────────────────────────────────────────────────────────────

    public function test_confirming_an_order_asks_for_the_order_update_key(): void
    {
        $order = $this->draftOrder();

        $this->knock('sales.order.update', 'sales.order.confirm', $order, [],
            fn () => $order->fresh()->status, DocumentStatus::DRAFT, DocumentStatus::CONFIRMED);
    }

    public function test_cancelling_an_order_asks_for_the_order_cancel_key(): void
    {
        $order = app(SalesOrderService::class)->confirm($this->draftOrder());

        $this->knock('sales.order.cancel', 'sales.order.cancel', $order, ['reason' => 'দরজার পরীক্ষা'],
            fn () => $order->fresh()->status, DocumentStatus::CONFIRMED, DocumentStatus::CANCELLED);
    }

    // ── চালান ────────────────────────────────────────────────────────────

    public function test_confirming_a_challan_asks_for_the_challan_create_key(): void
    {
        $challan = $this->draftChallan();

        $this->knock('sales.challan.create', 'sales.challan.confirm', $challan, [],
            fn () => $challan->fresh()->status, DocumentStatus::DRAFT, DocumentStatus::CONFIRMED);
    }

    public function test_cancelling_a_challan_asks_for_the_challan_cancel_key(): void
    {
        /* ⚠️ নিশ্চিত চালান — বাতিলে মাল গুদামে ফেরে, বিপদটা ওখানেই */
        $challan = app(DeliveryChallanService::class)->confirm($this->draftChallan()->fresh(['lines']));

        $this->knock('sales.challan.cancel', 'sales.challan.cancel', $challan, ['reason' => 'দরজার পরীক্ষা'],
            fn () => $challan->fresh()->status, DocumentStatus::CONFIRMED, DocumentStatus::CANCELLED);
    }

    // ── বিল ──────────────────────────────────────────────────────────────

    public function test_cancelling_an_invoice_asks_for_the_invoice_cancel_key(): void
    {
        $this->actingAs($this->owner);

        /** @var SalesInvoice $invoice */
        $invoice = app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'deposit' => '0',
            ],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100']],
        )['invoice'];

        $this->knock('sales.invoice.cancel', 'sales.invoice.cancel', $invoice, ['reason' => 'দরজার পরীক্ষা'],
            fn () => $invoice->fresh()->status, DocumentStatus::CONFIRMED, DocumentStatus::CANCELLED);
    }

    // ── আদায় ─────────────────────────────────────────────────────────────

    public function test_confirming_a_collection_asks_for_the_collection_create_key(): void
    {
        $collection = $this->draftCollection();

        $this->knock('sales.collection.create', 'sales.collection.confirm', $collection, [],
            fn () => $collection->fresh()->status, DocumentStatus::DRAFT, DocumentStatus::CONFIRMED);
    }

    public function test_cancelling_a_collection_asks_for_the_collection_cancel_key(): void
    {
        /* ⚠️ নিশ্চিত আদায় — বাতিলে দাখিলা উল্টে গ্রাহক আবার দেনাদার হন */
        $collection = app(CollectionService::class)->confirm($this->draftCollection());

        $this->knock('sales.collection.cancel', 'sales.collection.cancel', $collection, ['reason' => 'দরজার পরীক্ষা'],
            fn () => $collection->fresh()->status, DocumentStatus::CONFIRMED, DocumentStatus::CANCELLED);
    }

    public function test_bouncing_a_receipt_cheque_asks_for_the_collection_cancel_key(): void
    {
        /*
         * ⓘ চেক ফেরত মানে আদায়ের কাগজ বাতিল, তাই চাবিও আদায় বাতিলের।
         * ⚠️ দুইটা অবস্থাই মাপা হয়: চেক আর তার আদায়।
         */
        $collection = app(CollectionService::class)->confirm($this->draftCollection());

        $cheque = app(ChequeService::class)->record([
            'amount' => (string) $collection->amount,
            'cheque_no' => 'DOOR-0001',
            'cheque_date' => now()->toDateString(),
            'bank_name' => 'দরজা ব্যাংক',
            'party_type' => 'customer',
            'party_id' => $this->customer->id,
            'collection_id' => $collection->id,
        ]);

        $this->knock('sales.collection.cancel', 'sales.collection.cheque_bounce', $cheque,
            ['bounce_reason' => 'দরজার পরীক্ষা'],
            fn () => $cheque->fresh()->status.' / '.$collection->fresh()->status,
            Cheque::PENDING.' / '.DocumentStatus::CONFIRMED,
            Cheque::BOUNCED.' / '.DocumentStatus::CANCELLED);
    }

    // ── দরজায় কড়া নাড়া ───────────────────────────────────────────────────

    /**
     * একই মানুষ, একই কাগজ, দুইবার: আগে ঐ চাবিটা ছাড়া, তারপর চাবিটা হাতে।
     *
     * ⚠️ দুইজন আলাদা মানুষ নিলে ৪০৩-টা সুইচ, সদস্যপদ বা ভুল রুটের
     * কারণেও আসতে পারত। ⓘ একই মানুষ হলে দুই অনুরোধের মধ্যে তফাত কেবল
     * ঐ একটা চাবি — তাই দরজা খুললে বোঝা যায় আগে বন্ধ ছিল চাবির জন্যই।
     *
     * @param  array<string, mixed>  $form
     * @param  callable(): string  $state
     */
    private function knock(
        string $key,
        string $route,
        object $document,
        array $form,
        callable $state,
        string $before,
        string $after,
    ): void {
        $this->assertSame($before, $state(),
            "প্রস্তুতিটাই ভুল — {$route}-এর কাগজ শুরুতে '{$before}' অবস্থায় নেই।");

        $user = $this->withEverythingBut($key);

        $this->assertFalse($user->can($key),
            "প্রস্তুতিটাই ভুল — '{$key}' না দেওয়া সত্ত্বেও ব্যবহারকারীর হাতে চাবিটা আছে।");

        $this->postAs(route($route, $document), $form, $user)->assertForbidden();

        $this->assertSame($before, $state(),
            "⛔ {$route}: '{$key}' ছাড়া ব্যবহারকারীকে ৪০৩ দেওয়া হয়েছে ঠিকই, তবু কাগজের অবস্থা বদলে গেছে।");

        $user->givePermissionTo($key);
        $user = $user->fresh();

        $this->assertTrue($user->can($key), "প্রস্তুতিটাই ভুল — '{$key}' দেওয়ার পরেও হাতে আসেনি।");

        $this->postAs(route($route, $document), $form, $user)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($after, $state(),
            "⛔ {$route}: একই মানুষ '{$key}' পাওয়ার পরেও কাজটা হয়নি — তাহলে উপরের ৪০৩ কিছুই প্রমাণ করে না।");
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function postAs(string $url, array $form, User $as): TestResponse
    {
        return $this->actingAs($as)->from($url)->call('POST', $url, $form);
    }

    /**
     * ঐ একটা চাবি ছাড়া বাকি সব — তাই ৪০৩ হলে দায়ী কেবল ঐ চাবিটা।
     *
     * ⓘ প্রতিটা দাবিতে নতুন মানুষ — spatie অনুমতি ক্যাশ করে, তাই একজনকে
     * দাবি থেকে দাবিতে ঘোরালে আগের চাবি হাতে থেকে যেত।
     */
    private function withEverythingBut(string $key): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);

        $user->givePermissionTo(Permission::query()->where('name', '<>', $key)->get());

        return $user->fresh();
    }

    // ── কাগজ ─────────────────────────────────────────────────────────────

    private function draftOrder(): SalesOrder
    {
        $this->actingAs($this->owner);

        return app(SalesOrderService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'ordered_qty' => '1', 'rate' => '100']],
        );
    }

    private function draftChallan(): DeliveryChallan
    {
        $this->actingAs($this->owner);

        return app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'delivered_qty' => '1', 'rate' => '100']],
        );
    }

    private function draftCollection(): Collection
    {
        $this->actingAs($this->owner);

        return app(CollectionService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'trx_date' => now()->toDateString(),
                'amount' => '500',
            ],
            [],
        );
    }
}
