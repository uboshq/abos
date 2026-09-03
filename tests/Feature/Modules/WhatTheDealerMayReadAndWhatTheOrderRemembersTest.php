<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ডিলার কী পড়তে পারবেন, আর অর্ডারটা কী মনে রাখে।
 *
 * ── কেন এই তিনটা কলাম একসাথে ─────────────────────────────────────────
 * তিনটাই একটা নীতির ফল — মালিকের কথা, ৩ সেপ্টেম্বর ২০২৬:
 *
 *   > "ডিলার শুধু তার হিসাব দেখবে।"
 *
 * অর্থাৎ পোর্টালের প্রতিটা পর্দার একটাই প্রশ্ন: **এই জিনিসটা কি তাঁর
 * নিজের?** মজুদ আমাদের, ক্রয়মূল্য আমাদের, প্রত্যাখ্যানের ভিতরের কারণ
 * আমাদের — কিছুই তাঁর নয়।
 *
 * ⚠️ ── এই ফাইলটা কেন দরকার ────────────────────────────────────────────
 * একটা মাইগ্রেশন কলাম বসায়, কিন্তু **কেউ ওটা ব্যবহার করছে কি না তা
 * বলে না**। কলাম বসিয়ে ভুলে যাওয়া এই রিপোতে আগেও হয়েছে: `customers`-এ
 * `portal_password` তিনটা কলাম বসে ছিল, আর নথিতে লেখা ছিল "কোনো পর্দা
 * নেই" — অথচ পুরো পোর্টালটাই তৈরি।
 *
 * তাই এখানে কলাম **আছে কি না** দেখা হয় না; দেখা হয় **কী আচরণ করে**।
 */
class WhatTheDealerMayReadAndWhatTheOrderRemembersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * একটা অর্ডার — নিজেই বানানো, ডেমোর ভরসায় নয়।
     *
     * ⚠️ প্রথমে ধরে নিয়েছিলাম `DemoSeeder` অর্ডার বসায়। **বসায় না** —
     * `sal_orders` খালি। শুরুর `assertGreaterThan(0, …)` লাইনটাই সেটা
     * ধরিয়ে দিয়েছে; ওটা না থাকলে নিচের তুলনাগুলো **"০ বনাম ০" মিলিয়ে
     * চিরকাল সবুজ** থাকত, আর কলামটা সত্যিই বসেছে কি না কেউ জানত না।
     */
    private function order(): SalesOrder
    {
        $service = app(SalesOrderService::class);

        return $service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => Product::query()->value('id'),
            'ordered_qty' => '1',
            'rate' => '100',
        ]]);
    }

    /* ── কারণের কোড ──────────────────────────────────────────────── */

    /**
     * ⭐ নতুন কারণ চুপচাপ লুকানো — আর এটাই সবচেয়ে জরুরি assertion।
     *
     * ডিফল্ট `true` হলে **মাইগ্রেশন চালানোর মুহূর্তে** আজকের প্রতিটা
     * কারণ ডিলারের পর্দায় চলে আসত, কারো কিছু না করেই। তার মধ্যে
     * একটাও যদি "মজুদ নেই" ধরনের হয়, সেটাই ফাঁস — আর মালিকের সাফ
     * সিদ্ধান্ত হলো ডিলার মজুদ দেখবেন না।
     */
    public function test_a_new_reason_is_hidden_from_the_dealer_until_somebody_says_otherwise(): void
    {
        $reason = ReasonCode::query()->create([
            'company_id' => $this->company->id,
            'code' => 'NOSTK',
            'name_en' => 'Out of stock',
            'context' => 'cancellation',
        ]);

        $this->assertFalse($reason->fresh()->visible_to_dealer);
    }

    /**
     * ⚠️ আর যেগুলো আগে থেকেই আছে, সেগুলোও।
     *
     * মাইগ্রেশনটা পুরনো সারিগুলোতেও ডিফল্ট বসায়। এটা আলাদা করে দেখা
     * দরকার, কারণ নতুন সারির ডিফল্ট আর পুরনো সারির ভরাট **দুইটা আলাদা
     * পথ** — একটা ঠিক থাকলেও অন্যটা ভুল হতে পারে।
     */
    public function test_reasons_that_already_existed_are_hidden_too(): void
    {
        $existing = ReasonCode::query()->count();

        // ⚠️ শূন্যটা আগে দেখে নেওয়া — খালি সংগ্রহে assertion সবসময় সবুজ
        $this->assertGreaterThan(0, $existing, 'ডেমোতে একটাও কারণ কোড নেই — তুলনাটা তখন অর্থহীন।');

        $this->assertSame(
            0,
            ReasonCode::query()->where('visible_to_dealer', true)->count(),
            'কোনো পুরনো কারণ ডিলারের জন্য খোলা হয়ে গেছে — মাইগ্রেশনেই ফাঁস।',
        );
    }

    /** কোম্পানি চাইলে একটা খুলে দিতে পারে — সুইচটা সত্যিই কাজ করে। */
    public function test_a_company_can_open_one_reason_without_opening_the_rest(): void
    {
        $shown = ReasonCode::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CUSTNO',
            'name_en' => 'Customer changed their mind',
            'context' => 'cancellation',
            'visible_to_dealer' => true,
        ]);

        $hidden = ReasonCode::query()->create([
            'company_id' => $this->company->id,
            'code' => 'NOSTK2',
            'name_en' => 'Out of stock',
            'context' => 'cancellation',
        ]);

        $this->assertTrue($shown->fresh()->visible_to_dealer);
        $this->assertFalse($hidden->fresh()->visible_to_dealer);
    }

    /* ── অর্ডার কোথা থেকে এল ─────────────────────────────────────── */

    /**
     * পুরনো ও নতুন সব অর্ডারের একটা উৎস আছে — `null` কোনোদিন নয়।
     *
     * ⓘ `null` মানে "জানি না", আর তখন পুরনো সারিগুলো নিয়ে প্রশ্নটা
     * চিরকাল খোলা থাকত। আজ পর্যন্ত পোর্টাল বা SR থেকে একটাও অর্ডার
     * আসেনি, তাই `counter` অনুমান নয় — সত্য।
     */
    public function test_every_order_says_where_it_came_from(): void
    {
        $this->order();

        $orders = SalesOrder::query()->count();
        $this->assertGreaterThan(0, $orders, 'একটাও অর্ডার বসেনি — তুলনাটা তখন অর্থহীন।');

        $this->assertSame(
            $orders,
            SalesOrder::query()->where('source', 'counter')->count(),
            'অর্ডারের উৎস বসেনি — ডিফল্টটা কাজ করছে না।',
        );
    }

    /**
     * ⭐ পোর্টালের অর্ডার নাম বলতে পারে, যদিও ডিলার `users`-এ নেই।
     *
     * ⚠️ এটাই এই তিনটা কলামের আসল কারণ। `created_by` foreign key
     * `users`-এ যায়, আর ডিলার `customers`-এ — তাই পোর্টাল থেকে আসা
     * অর্ডারে ওই ঘরটা **খালি থাকা ছাড়া উপায় নেই**।
     *
     * খালি ঘরের একটা ব্যাখ্যা থাকতে হবে, নইলে ছয় মাস পরে কেউ ধরে
     * নেবেন সারিটা সিডার থেকে এসেছে। **`source` সেই ব্যাখ্যা, আর
     * `created_by_customer_id` আসল উত্তর।**
     */
    public function test_a_portal_order_names_the_dealer_even_though_he_is_not_a_user(): void
    {
        $order = $this->order();
        $dealer = $order->customer_id;

        $order->forceFill([
            'source' => 'portal',
            'created_by' => null,
            'created_by_customer_id' => $dealer,
        ])->save();

        $fresh = $order->fresh();

        $this->assertSame('portal', $fresh->source);
        $this->assertNull($fresh->created_by, 'ডিলার users-এ নেই, তাই এই ঘরটা খালি থাকার কথা।');
        $this->assertSame($dealer, $fresh->created_by_customer_id);
    }

    /**
     * ⚠️ ডিলার মুছলে অর্ডারটা মুছে যায় না।
     *
     * `cascade` হলে একজন ডিলার সরানোর সাথে তাঁর প্রতিটা অর্ডার,
     * বিক্রির ইতিহাস আর খতিয়ানের সূত্র মুছে যেত। এই রিপোতে কেউ মোছে
     * না (নিষ্ক্রিয় হয়), কিন্তু পাহারাটা ধারণার উপর ছাড়া যায় না।
     */
    public function test_the_link_to_the_dealer_lets_go_instead_of_dragging_the_order_away(): void
    {
        $foreign = collect(Schema::getForeignKeys('sal_orders'))
            ->first(fn (array $k) => in_array('created_by_customer_id', $k['columns'], true));

        $this->assertNotNull($foreign, 'created_by_customer_id-এ কোনো foreign key নেই।');
        $this->assertSame('set null', strtolower((string) $foreign['on_delete']));
    }
}
