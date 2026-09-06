<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * আদেশ ছিল একশোর, বিল এল একশো দশের।
 *
 * ── কেন এই কাগজটা আজ লেখা হচ্ছে (৬ সেপ্টেম্বর ২০২৬) ──────────────────
 * নিয়মটা **আগেই সারানো হয়েছে** — `PurchaseBillService::resolveOrderLine()`,
 * ৫ সেপ্টেম্বর, কমিট `0980939`। কিন্তু অডিটে দেখা গেল **তার কোনো টেস্ট
 * নেই**: গোটা সুইটে `over_billed_order` শব্দটা একবারও নেই।
 *
 * ⚠️ সারানো অথচ অপরীক্ষিত নিয়ম একটা বিশেষ ফাঁদ — সে **আজ কাজ করে**, তাই
 * কেউ খোঁজে না; আর যেদিন কেউ ঐ লাইনটা সরায়, কোনো কিছুই লাল হয় না।
 *
 * ⭐ বিক্রয়ের আয়নাটা আজ সারানো হয়েছে ([[TheOrderWasForAHundredAndTheVanTookOneTenTest]]),
 * আর এই দুইটা কাগজ একসাথে রাখলে **দুই পাশে এক নিয়ম** থাকার কথাটা
 * কোডে বাঁধা পড়ে — একটা পাশ বদলালে অন্যটা ধরিয়ে দেবে।
 */
class TheOrderWasForAHundredAndTheBillCameForOneTenTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    private function orderFor(string $qty): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);

        $order = $service->create(
            [
                'supplier_id' => $this->supplier->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => '60']],
        );

        return $service->confirm($order);
    }

    private function billFor(PurchaseOrder $order, string $qty): PurchaseBill
    {
        return app(PurchaseBillService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'qty' => $qty,
                'rate' => '60',
                'purchase_order_line_id' => $order->lines->first()->id,
            ]],
        );
    }

    public function test_a_partial_bill_is_allowed(): void
    {
        $order = $this->orderFor('100');

        $bill = $this->billFor($order, '60');

        $this->assertSame(0, bccomp((string) $bill->lines->first()->qty, '60', 4),
            'আংশিক বিল আটকানো হয়েছে — ৬০ আজ, ৪০ পরে, এটাই স্বাভাবিক কাজ।');
    }

    public function test_the_second_bill_cannot_take_the_order_past_its_quantity(): void
    {
        $order = $this->orderFor('100');

        $this->billFor($order, '60');

        try {
            $this->billFor($order, '50');

            $this->fail('১০০-র আদেশে ৬০ + ৫০ = ১১০ বিল হয়ে গেল, অথচ কেউ আটকাল না।');
        } catch (ValidationException $refused) {
            $this->assertArrayHasKey('lines', $refused->errors());

            $message = implode(' ', $refused->errors()['lines']);

            /* দুইটা সংখ্যাই — নইলে ক্রেতা জানতেন না আর কতটা বিল করা যায়। */
            $this->assertStringContainsString('100', $message,
                'বার্তায় আদেশের পরিমাণটা নেই।');
            $this->assertStringContainsString('60', $message,
                'বার্তায় ইতিমধ্যে কতটা বিল হয়েছে সেটা নেই।');
        }
    }

    public function test_the_rest_of_the_order_still_bills(): void
    {
        $order = $this->orderFor('100');

        $this->billFor($order, '60');
        $second = $this->billFor($order, '40');

        $this->assertSame(0, bccomp((string) $second->lines->first()->qty, '40', 4),
            'বাকি ৪০ বিল করতে গিয়ে আটকে গেছে — সীমাটা যোগফলের উপর, প্রতিটা বিলের উপর নয়।');
    }

    /**
     * ⭐ যোগফলটা এই বিলটা বাদ দিয়ে — নাহলে সম্পাদনা করাই যেত না।
     */
    public function test_editing_a_bill_does_not_count_itself(): void
    {
        $order = $this->orderFor('100');

        $bill = $this->billFor($order, '60');

        $again = app(PurchaseBillService::class)->update(
            $bill,
            [
                'supplier_id' => $this->supplier->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'qty' => '60',
                'rate' => '60',
                'purchase_order_line_id' => $order->lines->first()->id,
            ]],
        );

        $this->assertSame(0, bccomp((string) $again->lines->first()->qty, '60', 4),
            'একই বিল আবার সেভ করতে গিয়ে আটকে গেছে — সে নিজেকেই গুনছে।');
    }
}
