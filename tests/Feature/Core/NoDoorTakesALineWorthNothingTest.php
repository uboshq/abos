<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দশটা দরজা, একটা নিয়ম — আর নিয়মটা হাতে বসানো হয়েছে।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"price chara kono entry newar kotha na"* — ক্রয়ে আর বিক্রয়ে, দুই
 * দিকেই।
 *
 * ── ⚠️ কেন এই পাহারাটা লাগল ─────────────────────────────────────────
 * ⓘ নিয়মটা বসেছে দশ জায়গায়, একটা একটা করে, হাতে। ⛔ ঠিক এভাবেই একটা
 * বাদ পড়ে — আর বাদ পড়া দরজাটা নীরব: বাকি ন'টা ঠিক কাজ করে, তাই কেউ
 * সন্দেহ করে না, অথচ শূন্য দরের সারি ঐ একটা পথেই ঢুকতে থাকে।
 *
 * ── ⛔ শূন্য দরের ক্ষতিটা কেন চোখে পড়ে না ───────────────────────────
 * **ক্রয়ে** — মাল ঢোকে, মজুদের মূল্য শূন্য বসে। ⚠️ ঐ মাল বিক্রি হলে
 * খরচ শূন্য, অর্থাৎ পুরো বিক্রয়মূল্যটাই মুনাফা বলে গোনা হয়, আর তার
 * উপর কর বসে।
 *
 * **বিক্রয়ে** — মাল গুদাম থেকে নামে, খরচ খাতায় বসে, আয় শূন্য। ⛔ প্রতিটা
 * সারি খাতায় সরাসরি লোকসান লেখে।
 *
 * ⓘ দুইটাতেই কোনো পর্দা লাল হয় না।
 *
 * ── ⚠️ কেন সত্যিকারের অনুরোধ পাঠানো হয়, নিয়ম পড়া হয় না ─────────────
 * ⓘ চারটা দরজার নিয়ম কন্ট্রোলারের ভিতরে, ছয়টার `FormRequest`-এ। ⛔ আর
 * নিয়মটা লেখা থাকা আর **চলা** এক জিনিস নয়: একটা কন্ট্রোলার তার
 * `FormRequest` ব্যবহারই না করলে নিয়মগুলো নিখুঁত থেকেও কিছুই আটকাত না।
 *
 * ⭐ তাই এখানে সত্যিই একটা শূন্য দরের সারি পাঠানো হয়, আর দেখা হয়
 * প্রত্যাখ্যানটা **ঐ ঘরের নামেই** আসে কি না।
 */
final class NoDoorTakesALineWorthNothingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        /*
         * ⭐ কাউন্টারের পর্দাটা এখানে চালু করা হয় — আর এটা মেপে শেখা।
         *
         * ⓘ মালিক POS ডিফল্টে বন্ধ রেখেছেন (`sales.screen_pos`), কারণ
         * ABOS-এর প্রথম ব্যবহারকারী একটা ডিপো। ⛔ তাই প্রথম রানে
         * কাউন্টারের দুইটা দাবি লাল হয়েছিল: অনুরোধটা যাচাই পর্যন্ত
         * পৌঁছায়ইনি — পর্দাটাই বন্ধ।
         *
         * ⚠️ এটা পাহারার ভুল নয়, দাবির ভুল ছিল: ⓘ *"শূন্য দর আটকায়"*
         * দাবিটা অর্থবহ কেবল সেই দরজায় যেটা খোলা থাকে।
         */
        $settings = app(SettingsService::class);
        $settings->set('sales.screen_pos', true);
        $settings->flush();
    }

    /**
     * প্রতিটা দরজা, আর তার পরিমাণের ঘরের নাম।
     *
     * ⓘ নামটা দরজায় দরজায় আলাদা — অর্ডারে `ordered_qty`, চালানে
     * `delivered_qty`, বিলে `qty`। ⚠️ ভুল নাম দিলে পরিমাণের ঘরটাও
     * ত্রুটি দিত, কিন্তু দাবিটা **দরের** ঘর ধরে, তাই ওটা এখানে
     * বিভ্রান্ত করত না — কেবল নিয়ন্ত্রণ সারিটা মিথ্যা হত।
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function doors(): array
    {
        return [
            'ক্রয় আদেশ' => ['purchase.order.store', 'ordered_qty'],
            'ক্রয় চালান' => ['purchase.receipt.store', 'received_qty'],
            'ক্রয় বিল' => ['purchase.bill.store', 'qty'],
            'সরাসরি ক্রয়' => ['purchase.direct.store', 'qty'],
            'বিক্রয় আদেশ' => ['sales.order.store', 'ordered_qty'],
            'ডেলিভারি চালান' => ['sales.challan.store', 'delivered_qty'],
            'বিক্রয় বিল' => ['sales.invoice.store', 'qty'],
            'সরাসরি বিক্রয়' => ['sales.direct.store', 'qty'],
            'কাউন্টার — বিল' => ['sales.pos.checkout', 'qty'],
            'কাউন্টার — জমা রাখা' => ['sales.pos.park', 'qty'],
        ];
    }

    /**
     * ⛔ শূন্য দরের সারি কোনো দরজাই নেয় না।
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('doors')]
    public function test_a_zero_rate_is_refused(string $route, string $qtyField): void
    {
        $this->post(route($route), $this->payload($qtyField, rate: '0'))
            ->assertSessionHasErrors('lines.0.rate');
    }

    /**
     * ⭐ আর একই সারি একটা দর পেলে ঐ ঘরে আর অভিযোগ থাকে না।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা কিছুই মাপত না ──────────────────
     * ⛔ একটা দরজা যদি **সবকিছুই** ফিরিয়ে দিত — ভুল রুট, বন্ধ অনুমতি,
     * একটা অসম্পূর্ণ পেলোড — তবু উপরের দাবিটা সবুজ থাকত। ⓘ আর তখন
     * পাহারাটা মাপত "কিছু একটা ভুল", দরের নিয়মটা নয়।
     *
     * ⚠️ দাবিটা "অনুরোধটা সফল হলো" নয়, **"ঐ ঘরে অভিযোগ নেই"** — ⓘ
     * পেলোডের অন্য ঘর অসম্পূর্ণ থাকতে পারে, আর তাতে দরের প্রশ্নের
     * উত্তর বদলায় না।
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('doors')]
    public function test_the_same_line_with_a_rate_clears_that_field(string $route, string $qtyField): void
    {
        $this->post(route($route), $this->payload($qtyField, rate: '100'))
            ->assertSessionDoesntHaveErrors('lines.0.rate');
    }

    /**
     * একটা সারির অনুরোধ — দরজায় দরজায় যেটুকু আলাদা, সেটুকুসহ।
     *
     * ⓘ ক্রয়ের দরজা সরবরাহকারী চায়, বিক্রয়ের গ্রাহক — দুইটাই পাঠানো
     * হয়, আর যার যেটা দরকার সে সেটা নেয়। ⚠️ বাড়তি ঘর কোনো দরজাতেই
     * ত্রুটি নয়, তাই এতে দাবিটা আলগা হয় না।
     *
     * @return array<string, mixed>
     */
    private function payload(string $qtyField, string $rate): array
    {
        return [
            /*
             * ⚠️ দুইটা ঘর যাচাইয়ের **আগে** দেখা হয় — আর এটা মেপে শেখা।
             *
             * ⓘ চালান আর বিলের `authorize()` বলে: অর্ডার বা চালানের
             * নম্বর ছাড়া কাগজটাই নেওয়া হবে না। ⛔ ছাড়া অনুরোধটা
             * ৪০৩ হয়ে ফেরে, আর দরের নিয়মটা কখনো চলেই না।
             *
             * ⓘ সংখ্যাগুলো সত্যি হতে হয় না: দাবিটা **দরের** ঘর ধরে,
             * তাই ওদের নিজের ত্রুটি এই পরীক্ষাকে বিভ্রান্ত করে না।
             */
            'sales_order_id' => 1,
            'delivery_challan_id' => 1,

            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'unit_id' => $this->product->unit_id,
                $qtyField => '1',
                'rate' => $rate,
            ]],
        ];
    }
}
