<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ট্রাকটা দুইবার গোনা হত — একবার ট্রাকের পাশে, একবার কাগজে।
 *
 * ── ⓘ জোড়ার উপরের প্রান্ত আগেই বসেছিল ───────────────────────────────
 * [[PurchaseOrderSync]] যন্ত্রে পাঠায় *"কী আসার কথা"*। ⚠️ কিন্তু ফেরার
 * পথ ছিল না: গুদামের লোক গুনে শেষ করে অফিসে গিয়ে আবার সব টাইপ করতেন,
 * আর ঐ **দ্বিতীয় টাইপিংটাই** ভুলের আসল উৎস।
 *
 * ── ⛔ খসড়া বসে, নিশ্চিত হয় না — আর এটাই আসল সিদ্ধান্ত ───────────────
 * নিশ্চিত করা মানে খতিয়ানে দাখিলা আর গুদামে চলাচল। ⚠️ ঠেলা একদিন
 * হারায়, দুইবার আসে, বা পুরনো ঘড়ি নিয়ে আসে — ⛔ আর *"eventually
 * consistent ledger"* বলে কিছু নেই।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. যন্ত্র থেকে পাঠানো গ্রহণ সত্যিই বসে, আর আদেশের সাথে বাঁধা থাকে
 *   ২. ওটা **খসড়া** থাকে — খতিয়ান বা মজুদ নড়ে না
 *   ৩. দরটা আদেশ থেকে আসে, ঠেলা থেকে নয়
 *   ৪. খসড়া বা বাতিল আদেশের বিপরীতে কিছুই বসে না
 *   ৫. আদেশে নেই এমন পণ্য ফিরিয়ে দেওয়া হয়
 *
 * ⓘ (৩) সবচেয়ে নীরব: একটা বদলে দেওয়া ঠেলা ক্রয়মূল্য বদলে দিতে পারত,
 * আর ঐ সংখ্যাটা সরাসরি মজুদের স্তরে বসে।
 */
final class TheLorryWasCountedTwiceOnceOnPaperTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'warehouse-handset-77';

    private string $token = '';

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    // ── ১ ও ২ · বসে, আর খসড়াই থাকে ───────────────────────────────────

    public function test_a_receipt_pushed_from_the_handset_lands_against_its_order(): void
    {
        $order = $this->aConfirmedOrder();

        $this->push($order, '7')->assertSuccessful();

        $receipt = PurchaseReceipt::query()
            ->where('purchase_order_id', $order->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($receipt,
            'যন্ত্র থেকে পাঠানো গ্রহণটা বসেনি — গুদামের লোককে তাহলে আবার '
            .'অফিসে গিয়ে সব টাইপ করতে হবে, আর সেটাই ভুলের উৎস।');

        $this->assertSame(0, bccomp((string) $receipt->lines->first()->received_qty, '7', 4));
    }

    public function test_the_pushed_receipt_stays_a_draft(): void
    {
        /*
         * ⭐ এটাই সবচেয়ে গুরুত্বপূর্ণ দাবি।
         *
         * ⛔ নিশ্চিত হয়ে গেলে একটা হারানো বা দুইবার আসা ঠেলা খতিয়ানে
         * দাখিলা বসাত — ⚠️ আর খাতা হয় মেলে, নয় মেলে না; মাঝামাঝি
         * বলে কিছু নেই।
         */
        $order = $this->aConfirmedOrder();

        $this->push($order, '7')->assertSuccessful();

        $receipt = PurchaseReceipt::query()->where('purchase_order_id', $order->id)->firstOrFail();

        $this->assertSame(DocumentStatus::DRAFT, $receipt->status,
            'যন্ত্র থেকে আসা গ্রহণটা নিজেই নিশ্চিত হয়ে গেছে — তাহলে '
            .'ট্রাকের পাশে লেখা একটা সংখ্যা সরাসরি খতিয়ানে বসছে।');
    }

    // ── ৩ · দর আদেশ থেকে ─────────────────────────────────────────────

    public function test_the_rate_comes_from_the_order_not_from_the_push(): void
    {
        /*
         * ⛔ পেলোডে একটা দর পাঠানো হয়, আর সেটা **পড়াই হয় না** —
         * ⚠️ নাহলে একটা বদলে দেওয়া ঠেলা ক্রয়মূল্য বদলে দিতে পারত,
         * আর ঐ সংখ্যাটা সরাসরি মজুদের স্তরে বসে।
         */
        $order = $this->aConfirmedOrder();

        $this->push($order, '7', extra: ['rate' => '99999'])->assertSuccessful();

        $receipt = PurchaseReceipt::query()->where('purchase_order_id', $order->id)->firstOrFail();

        $this->assertSame(0, bccomp((string) $receipt->lines->first()->rate, '100', 4),
            'ঠেলা থেকে আসা দরটা কাগজে বসে গেছে — গুদামের যন্ত্র দর ঠিক '
            .'করতে পারে না, ওটা ক্রয় বিভাগের কথা।');
    }

    // ── ৪ ও ৫ · যা ফিরিয়ে দেওয়া হয় ──────────────────────────────────

    public function test_a_draft_order_takes_no_goods(): void
    {
        $order = $this->anOrder();

        $this->push($order, '7');

        $this->assertSame(0, PurchaseReceipt::query()->where('purchase_order_id', $order->id)->count(),
            'খসড়া আদেশের বিপরীতে মাল বুঝে নেওয়া হয়েছে — অথচ কেউ এখনো '
            .'সরবরাহকারীকে কিছুই বলেনি।');
    }

    public function test_a_product_that_is_not_on_the_order_is_refused(): void
    {
        $order = $this->aConfirmedOrder();

        $other = Product::query()->where('id', '<>', $this->product->id)->firstOrFail();

        $this->pushRaw($order, [[
            'productId' => (string) $other->public_id,
            'receivedQty' => '3',
        ]]);

        $this->assertSame(0, PurchaseReceipt::query()->where('purchase_order_id', $order->id)->count(),
            'আদেশের বাইরের একটা পণ্য যন্ত্র থেকেই বুঝে নেওয়া হয়েছে — '
            .'ওটার দর কোথাও লেখা নেই, তাই ক্রয়মূল্যও আন্দাজ হত।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function anOrder(): PurchaseOrder
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        return app(PurchaseOrderService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'ordered_qty' => '10', 'rate' => '100', 'tax' => '0']],
        );
    }

    private function aConfirmedOrder(): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->confirm($this->anOrder());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function push(PurchaseOrder $order, string $qty, array $extra = [])
    {
        return $this->pushRaw($order, [array_merge([
            'productId' => (string) $this->product->public_id,
            'receivedQty' => $qty,
        ], $extra)]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function pushRaw(PurchaseOrder $order, array $lines)
    {
        $this->login();

        /*
         * ⭐ তারে পেলোডটা একটা **JSON স্ট্রিং**, নেস্টেড অবজেক্ট নয়।
         *
         * ⓘ [[PushedChange::fromArray()]] `payloadJson` খোঁজে, আর
         * `changeId` ছাড়া সারিটাই ব্যাখ্যা করে না — ⚠️ ঐ চাবিটার উপরেই
         * "দুইবার বসা ঠেকানোর" গোটা ব্যবস্থা দাঁড়ানো।
         *
         * ⛔ প্রথম খসড়ায় `entityId` আর `payload` পাঠানো হয়েছিল, আর
         * গোটা ব্যাচটা নীরবে প্রত্যাখ্যাত হত — HTTP ২০০, কিন্তু কিছুই
         * বসত না। ⓘ ঠিক একই ভুল টানার দিকেও হয়েছিল (`payloadJson`
         * বনাম `payload`), আর দুইবারই লক্ষণ ছিল "কিছুই হলো না"।
         */
        return $this->withToken($this->token)->postJson(
            '/api/v1/sync/purchase/push?deviceId='.self::DEVICE,
            [[
                'changeId' => (string) Str::uuid7(),
                'entityType' => 'GoodsReceipt',
                'operation' => 'create',
                'payloadJson' => json_encode([
                    'orderId' => (string) $order->public_id,
                    'receivedOn' => now()->toDateString(),
                    'lines' => $lines,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updatedAt' => now()->toIso8601String(),
            ]],
        );
    }

    private function login(): void
    {
        if ($this->token !== '') {
            return;
        }

        $this->app['auth']->forgetGuards();

        $login = $this->postJson('/api/v1/auth/login', [
            // ডেমোর সবার পাসওয়ার্ড `password` — README দেখুন
            'identifier' => 'owner@abos.test',
            'password' => 'password',
            'deviceId' => self::DEVICE,
            'appVersion' => '0.1.0',
            'platform' => 'android',
        ]);

        $this->token = (string) $login->json('accessToken');

        $this->assertNotSame('', $this->token, implode("\n", [
            'লগইনই হয়নি — নিচের কোনো দাবিই তাহলে সিঙ্কের কথা বলছে না।',
            '',
            'সার্ভার দিয়েছে '.$login->status().': '.$login->getContent(),
        ]));

        $this->app['auth']->forgetGuards();
    }
}
