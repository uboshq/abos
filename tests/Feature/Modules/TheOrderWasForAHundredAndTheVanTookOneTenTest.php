<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * আদেশ ছিল একশোর, গাড়িতে গেল একশো দশ।
 *
 * ── যে ফাঁকটা এই কাগজ ভরাট করে (৬ সেপ্টেম্বর ২০২৬) ───────────────────
 * আংশিক চালান চলে — ১০০ কার্টনের আদেশে ৬০ আজ, ৪০ পরে। কিন্তু
 * `DeliveryChallanService::resolveOrderLine()` দেখত আদেশ · সারি · পণ্য —
 * **পরিমাণ নয়**। ফলে ৬০ + ৫০ = ১১০ কেউ আটকাত না: গুদাম থেকে অতিরিক্ত
 * মাল বেরিয়ে যেত, চালান তৈরি হত, আর ক্রেতার খাতায় তার দাম বসত।
 *
 * ── ⚠️ কেন কেউ ধরেনি ────────────────────────────────────────────────
 * ঐ ফাইলেই `releasableQty()` আছে আর সে `$alreadyDelivered` যোগ করে —
 * পড়ে মনে হত পাহারা আছে। কিন্তু সে সীমা দেয় **রিজার্ভেশন ছাড়ার** উপর,
 * ডেলিভারির উপর নয়। ⓘ *যোগফলটা আছে* আর *পাহারা আছে* এক কথা নয়।
 *
 * ⭐ ক্রয়ের দিকে হুবহু এই বাগটাই ৫ সেপ্টেম্বর সারানো হয়েছে
 * (`PurchaseBillService`, কমিট `0980939`) — অথচ তারও কোনো টেস্ট ছিল না,
 * আর বিক্রয়ের আয়নাটা ভাঙাই থেকে গিয়েছিল।
 */
class TheOrderWasForAHundredAndTheVanTookOneTenTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    private function orderFor(string $qty): SalesOrder
    {
        $service = app(SalesOrderService::class);

        $order = $service->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => '200']],
        );

        return $service->confirm($order);
    }

    private function challanFor(SalesOrder $order, string $qty, bool $confirm = true): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        $challan = $service->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'sales_order_id' => $order->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'delivered_qty' => $qty,
                'rate' => '200',
                'sales_order_line_id' => $order->lines->first()->id,
            ]],
        );

        return $confirm ? $service->confirm($challan) : $challan;
    }

    public function test_a_partial_delivery_is_allowed(): void
    {
        $order = $this->orderFor('100');

        $challan = $this->challanFor($order, '60');

        $this->assertSame(0, bccomp((string) $challan->lines->first()->delivered_qty, '60', 4),
            'আংশিক চালান আটকানো হয়েছে — ৬০ পাঠিয়ে ৪০ পরে পাঠানোই স্বাভাবিক কাজ।');
    }

    public function test_the_second_challan_cannot_take_the_order_past_its_quantity(): void
    {
        $order = $this->orderFor('100');

        $this->challanFor($order, '60');

        try {
            $this->challanFor($order, '50');

            $this->fail('১০০-র আদেশে ৬০ + ৫০ = ১১০ ডেলিভারি হয়ে গেল, অথচ কেউ আটকাল না।');
        } catch (ValidationException $refused) {
            $this->assertArrayHasKey('lines', $refused->errors());

            /*
             * ⚠️ বার্তায় **দুইটা সংখ্যাই** থাকতে হবে।
             *
             * "বেশি হয়ে যাচ্ছে" বললে গুদামের লোক জানতেন না আর কতটা
             * পাঠানো যায়, আর ফোন করে জিজ্ঞেস করতেন — রোজ।
             */
            $message = implode(' ', $refused->errors()['lines']);

            $this->assertStringContainsString('100', $message,
                'বার্তায় আদেশের পরিমাণটা নেই।');
            $this->assertStringContainsString('60', $message,
                'বার্তায় ইতিমধ্যে কতটা গেছে সেটা নেই।');
        }
    }

    public function test_the_rest_of_the_order_still_goes_out(): void
    {
        $order = $this->orderFor('100');

        $this->challanFor($order, '60');
        $second = $this->challanFor($order, '40');

        $this->assertSame(0, bccomp((string) $second->lines->first()->delivered_qty, '40', 4),
            'বাকি ৪০ পাঠাতে গিয়ে আটকে গেছে — সীমাটা যোগফলের উপর, প্রতিটা চালানের উপর নয়।');
    }

    /**
     * ⭐ সবচেয়ে সহজে ভুল হওয়ার জায়গা।
     *
     * যোগফলটা **এই চালানটা বাদ দিয়ে** নিতে হয়। না নিলে একটা চালান
     * সম্পাদনা করতে গেলে সে নিজেকেই গুনত — ৬০-এর চালানটা খুলে একই ৬০
     * সেভ করতে গেলে ১২০ দেখাত, আর দ্বিতীয়বার সেভ করাই যেত না।
     */
    public function test_editing_a_challan_does_not_count_itself(): void
    {
        $order = $this->orderFor('100');

        $challan = $this->challanFor($order, '60', confirm: false);

        $again = app(DeliveryChallanService::class)->update(
            $challan,
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'sales_order_id' => $order->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'delivered_qty' => '60',
                'rate' => '200',
                'sales_order_line_id' => $order->lines->first()->id,
            ]],
        );

        $this->assertSame(0, bccomp((string) $again->lines->first()->delivered_qty, '60', 4),
            'একই চালান আবার সেভ করতে গিয়ে আটকে গেছে — সে নিজেকেই গুনছে।');
    }

    /**
     * বাতিল চালানের মাল আবার পাঠানো যায়, আর সেটাই ঠিক।
     */
    public function test_a_cancelled_challan_gives_its_quantity_back(): void
    {
        $order = $this->orderFor('100');

        $service = app(DeliveryChallanService::class);
        $first = $this->challanFor($order, '60');

        $service->cancel($first, 'গাড়ি বদলে গেছে');

        $again = $this->challanFor($order, '60');

        $this->assertSame(0, bccomp((string) $again->lines->first()->delivered_qty, '60', 4),
            'বাতিল চালানটা এখনো আদেশের পরিমাণ ধরে রেখেছে — তাহলে বাতিলের কোনো মানে থাকে না।');
    }
}
