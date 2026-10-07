<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারের নিজের দরজাটা খোলা থাকে।
 *
 * ── ⓘ মালিকের সিদ্ধান্ত, ২২ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * প্রশ্ন ছিল: *"কাউন্টারে নগদ বিক্রিতে আদেশ→নিশ্চিত, দুইটা বাড়তি ধাপ
 * বসবে কি না"*। ⭐ উত্তর: **না — কাউন্টার আলাদা থাক।**
 *
 * ── কেন ─────────────────────────────────────────────────────────────
 * ⓘ তাঁর আগের নির্দেশে চালানের শৃঙ্খলটা বাঁধা হয়েছে: *"kono direct
 * challan kata zabena, order ref.-e challan hobe"* — আর সেটা
 * [[AChallanIsWrittenAgainstAnOrderTest]]-এ বলবৎ।
 *
 * ⚠️ কিন্তু কাউন্টারে খদ্দের দাঁড়িয়ে থাকেন, আগে থেকে কোনো আদেশ থাকে না।
 * ⛔ দশ টাকার হাতে-হাতে বিক্রিতে আদেশ→নিশ্চিত বসালে বিক্রয়কর্মী প্রতিবার
 * একটা ভুয়া আদেশ বানিয়ে নিশ্চিত করতেন — কেবল ফর্ম পার করতে। তখন খাতা
 * নিখুঁত দেখাত, সত্যি হত না। ⓘ যে নিয়ম মানুষ ফাঁকি দিতে বাধ্য হয়,
 * সেটা নিয়ম নয়।
 *
 * ── ⛔ আর এই ফাইলটা কেন লাগল, যদিও কোনো কোড বদলায়নি ──────────────────
 * ⚠️ সিদ্ধান্তটা "যেমন আছে তেমনই থাক", তাই সারানোর কিছু ছিল না — আর
 * **ঠিক সেজন্যই** এটা অরক্ষিত। ⓘ ছাড়টা আজ কেবল
 * [[DeliveryChallanRequest]]-এর টীকায় লেখা, আর সেখানে এতদিন লেখা ছিল
 * *"মালিকের কাছে ঝুলে থাকা প্রশ্ন"*।
 *
 * ⛔ কেউ ছয় মাস পরে "সব চালানে আদেশ লাগবে" নিয়মটা **সম্পূর্ণ** করতে
 * গিয়ে ছাড়টা বন্ধ করে দিতেন — সেটা দেখতে সারাই-এর মতোই লাগত, প্রতিটা
 * পরীক্ষা সবুজ থাকত, আর কাউন্টার ভেঙে পড়ত। ⭐ নিয়ম মন্তব্যে থাকা আর
 * কোনো নিয়ম না থাকা কার্যত এক।
 */
final class TheCounterKeepsItsOwnDoorTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ আদেশ ছাড়াই কাউন্টারের বিক্রি সম্পূর্ণ হয় — চালান ও বিল দুইটাই।
     */
    public function test_a_counter_sale_needs_no_order_at_all(): void
    {
        $ordersBefore = SalesOrder::query()->count();

        $sale = $this->sellAtTheCounter();

        $this->assertNotNull($sale['challan'] ?? null, 'কাউন্টারের বিক্রিতে চালানই হয়নি।');
        $this->assertNotNull($sale['invoice'] ?? null, 'কাউন্টারের বিক্রিতে বিলই হয়নি।');

        /*
         * ⚠️ দাবিটা কেবল "চালান হয়েছে" নয় — "আদেশ ছাড়াই হয়েছে"।
         *
         * ⓘ নাহলে কেউ ভবিষ্যতে ভিতরে একটা আদেশ নিজে বানিয়ে নিশ্চিত
         * করে দিলে উপরের দুইটা দাবি সবুজই থাকত, অথচ মালিক ঠিক ঐ দুইটা
         * ধাপই চাননি।
         */
        $this->assertSame(
            $ordersBefore,
            SalesOrder::query()->count(),
            'কাউন্টারের বিক্রিতে ভিতরে একটা বিক্রয় আদেশ তৈরি হয়েছে — মালিক ঠিক এটাই চাননি।',
        );
    }

    /**
     * ⛔ আর চালানের সারিতে কোনো আদেশের সূত্র বসে না।
     *
     * ⚠️ উপরের দাবিটা গোনে কয়টা আদেশ **তৈরি** হলো। ⓘ এটা দেখে চালানটা
     * কোনো পুরনো আদেশের সাথে **জোড়া** লাগল কি না — দুইটা আলাদা কথা,
     * আর দ্বিতীয়টা না থাকলে কেউ ডেমোর কোনো আদেশ ধরে জুড়ে দিলে টের
     * পাওয়া যেত না।
     */
    public function test_the_counter_challan_carries_no_order_reference(): void
    {
        $sale = $this->sellAtTheCounter();

        $challan = DeliveryChallan::query()->findOrFail($sale['challan']->id);

        $this->assertNull(
            $challan->sales_order_id,
            'কাউন্টারের চালানটা একটা আদেশের সাথে জুড়ে বসেছে।',
        );
    }

    /**
     * ⓘ আর বাইরের দরজাটা বন্ধই থাকে — এই ছাড়টা সেটা আলগা করে না।
     *
     * ⚠️ এই দাবিটা না থাকলে "কাউন্টার আলাদা" সারাতে গিয়ে কেউ
     * [[DeliveryChallanRequest]]-এর পাহারাটাই তুলে দিতে পারতেন, আর
     * তখন মালিকের **আগের** নির্দেশটা (*"kono direct challan kata
     * zabena"*) নীরবে উল্টে যেত।
     *
     * ⓘ [[AChallanIsWrittenAgainstAnOrderTest]] ওটা বিস্তারিত মাপে;
     * এখানে দাবিটা আছে কেবল দুইটাকে **একসাথে** ধরে রাখতে — ছাড়টা
     * নিয়মটার জায়গায় বসে যায়নি।
     */
    public function test_the_public_door_still_asks_for_an_order(): void
    {
        $this->post(route('sales.challan.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100']],
        ])->assertForbidden();
    }

    /**
     * কাউন্টারে দশটা মাল, নগদহীন — টাকার দিকটা এখানে মাপার বিষয় নয়।
     *
     * @return array{challan: mixed, invoice: mixed, change: string}
     */
    private function sellAtTheCounter(): array
    {
        return app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
        );
    }
}
