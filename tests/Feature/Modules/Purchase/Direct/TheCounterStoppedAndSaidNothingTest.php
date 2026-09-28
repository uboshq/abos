<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase\Direct;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারে বোতাম চাপলে কিছুই হত না, আর পর্দা কিছুই বলত না।
 *
 * ── ⛔ কী ভাঙা ছিল, ২৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * সরাসরি ক্রয়ের `guard()` **তিন** জায়গায় `preventDefault()` ডেকে চুপ
 * করে ফিরে আসত: কার্ট খালি; লট ধরা পণ্যে লট নম্বর নেই; ভাড়া লেখা আছে
 * অথচ কে আনল বলা নেই। ⚠️ কাউন্টারে দাঁড়ানো মানুষের কাছে তিনটারই মানে
 * এক — **বোতাম কাজ করছে না**।
 *
 * ── ⓘ পর্দার বার্তাটা ভদ্রতা, নিরাপত্তা নয় ───────────────────────────
 * ⭐ তিনটা নিয়মই সার্ভারেও আছে, আর সেটাই আসল পাহারা — পর্দা কেবল
 * সার্ভার ফিরিয়ে দেওয়ার **আগেই** বলে দেয়, যাতে বিশ লাইন টাইপ করার পর
 * জানতে না হয়।
 *
 * ── ⚠️ তিনটার দুইটার দাবি আগে থেকেই ছিল, একটার ছিল না ────────────────
 * ⓘ কার্ট খালি — [[Tests\Feature\Modules\DirectPurchaseTest]]-এ
 *   `test_nothing_is_bought_without_a_line`।
 * ⓘ লট নম্বর — [[Tests\Feature\Modules\Purchase\ALotTrackedProductCouldNotBeBoughtDirectlyTest]]।
 * ⛔ **ভাড়া বনাম বাহক — কোথাও ছিল না।** নিয়মটা কন্ট্রোলারে লেখা ছিল
 *   (`carrier_id`-এর `Rule::requiredIf`), কিন্তু কোনো দাবি ওটা ছুঁত না —
 *   অর্থাৎ কেউ ওটা মুছে ফেললে সব সবুজ থাকত, আর তখন পর্দার নতুন
 *   বার্তাটাই একমাত্র পাহারা হয়ে যেত। ⚠️ আর একটা JavaScript-নির্ভর
 *   পাহারা কোনো পাহারা নয়: `curl` ওটা চেনেই না।
 */
final class TheCounterStoppedAndSaidNothingTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->buyer = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->firstOrFail();

        /*
         * ⛔ লট ধরা নয় এমন পণ্য — ইচ্ছাকৃত। ⚠️ লট ধরা পণ্য নিলে
         * `demandLots()` আগে থামাত, আর তখন এই দাবিটা **ভাড়ার নিয়মের
         * বদলে লটের নিয়মটা** মাপত, অথচ সবুজই থাকত।
         */
        $plain = Product::query()->where('track_batch', false)->first();

        $this->assertNotNull($plain, 'ডেমোতে লট-ছাড়া কোনো পণ্যই নেই।');

        $this->plain = $plain;
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_freight_with_nobody_to_carry_it_is_refused_by_the_server(): void
    {
        /*
         * ⭐ একই অনুরোধ দুইবার, কেবল বাহকের ঘরটা আলাদা — তাই পার্থক্যটা
         * আর কিছু থেকে আসতে পারে না।
         */
        $this->actingAs($this->buyer)
            ->post(route('purchase.direct.store'), $this->payload([
                'transport_cost' => '500',
            ]))
            ->assertSessionHasErrors('carrier_id');

        $this->assertSame(0, PurchaseBill::query()->count(),
            'বাহক ছাড়াই ভাড়াসহ বিলটা বসে গেছে।');
    }

    public function test_the_same_request_goes_through_once_somebody_is_named(): void
    {
        /*
         * ⛔ এটা না থাকলে উপরের দাবিটা ফাঁপা হতে পারত: অনুরোধটা যদি
         * **অন্য** কোনো কারণে ব্যর্থ হত, তবু `carrier_id`-তে ভুল দেখে
         * দাবিটা সবুজ থাকত না — কিন্তু বিলের সংখ্যা ০ থাকায় দ্বিতীয়
         * দাবিটা সবুজ থাকত। ⓘ তাই একই অনুরোধ বাহকসহ পাঠিয়ে দেখা হয় যে
         * সে সত্যিই বসে।
         */
        $this->actingAs($this->buyer)
            ->post(route('purchase.direct.store'), $this->payload([
                'transport_cost' => '500',
                'carrier_name' => 'করিম ট্রান্সপোর্ট',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PurchaseBill::query()->count(),
            'বাহকের নাম দেওয়ার পরেও বিলটা বসেনি।');
    }

    public function test_a_named_carrier_is_enough_even_without_a_typed_name(): void
    {
        /*
         * ⓘ নিয়মটা "নাম **বা** তালিকার বাহক" — দুইটার যেকোনো একটা।
         * ⚠️ কেবল নামের কেসটা মাপলে তালিকা-ধরা পথটা কোনোদিন পরীক্ষা হত না।
         */
        $carrier = Supplier::query()->where('id', '!=', $this->supplier->id)->first()
            ?? $this->supplier;

        $this->actingAs($this->buyer)
            ->post(route('purchase.direct.store'), $this->payload([
                'transport_cost' => '500',
                'carrier_id' => $carrier->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PurchaseBill::query()->count());
    }

    public function test_the_page_has_somewhere_to_print_the_reason(): void
    {
        /*
         * ⛔ এই প্রকল্পের সবচেয়ে চেনা ফাঁদ: কাজটা হয়ে যায়, জোড়াটা লাগে
         * না, আর কিছুই লাল হয় না।
         *
         * ⓘ কম্পোনেন্ট বার্তাটা **বসায়** — সেটার দাবি
         * `direct-purchase.test.js`-এ। ⚠️ কিন্তু পাতায় ওটা আঁকার ঘরটা না
         * থাকলে বার্তাটা কোথাও দেখা যেত না, আর দুই দিকের দাবিই সবুজ থাকত।
         *
         * ⚠️ এটা যা প্রমাণ করে না, সেটাও বলে রাখা দরকার: Alpine
         * বাঁধনটা সত্যিই চালিয়েছে কি না, তা HTML দেখে বলা যায় না —
         * CSP-Alpine নীরবে ফেলে দিতে পারে। ⓘ সেটা মাপা হয় ব্রাউজারে
         * (`E:\ABOS\e2e`) আর `csp-expressions`-এ।
         */
        $html = $this->actingAs($this->buyer)
            ->get(route('purchase.direct.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-stopped', $html,
            'পাঠানো থামার কারণ লেখার ঘরটাই পাতায় নেই।');

        $this->assertStringContainsString('x-text="stopped"', $html,
            'ঘরটা আছে, কিন্তু সে বার্তাটার সাথে বাঁধা নয়।');

        /* ⓘ আর শব্দ তিনটা সত্যিই পর্দায় পৌঁছায় */
        foreach (['need_a_line', 'need_a_lot', 'need_a_carrier'] as $key) {
            $this->assertStringContainsString(
                __('purchase::message.'.$key),
                $html,
                $key.' শব্দটা পর্দায় পাঠানোই হয়নি।',
            );
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->plain->id,
                'qty' => '10',
                'rate' => '100',
            ]],
            ...$overrides,
        ];
    }
}
