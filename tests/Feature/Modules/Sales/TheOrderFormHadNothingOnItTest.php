<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অর্ডারের ফর্মে কিছুই হত না।
 *
 * ── ⓘ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"New order form ali update koro zate direct sales er activiti gulo hoy
 * ekhane, ekhon to ekhane kichui hoyna"*।
 *
 * ⚠️ ফর্মটা **ভাঙা ছিল না** — abos-8b আসল পাতা headless Chrome-এ চালিয়ে
 * দেখেছেন Alpine বুট হয়, সারি যোগ হয়, একটাও ত্রুটি নেই। ⓘ অর্থাৎ
 * *"কিছুই হয় না"* মানে ভাঙা নয়, **সুবিধাগুলোই নেই**: সরাসরি বিক্রয়ের
 * পর্দা ~২,৪০০ লাইন, অর্ডারের ফর্ম ছিল ৮৭।
 *
 * ── ⛔ কিন্তু সবটা তোলা হয়নি, আর সেটাই এই ফাইলের সবচেয়ে জরুরি কথা ────
 * মালিককে সরাসরি জিজ্ঞেস করা হয়েছিল *"অর্ডার নেওয়ার সময় কি টাকাও
 * নেন?"*, আর উত্তর: **না — অর্ডার আলাদা, টাকা পরে**।
 *
 * ⚠️ তাই টাকা নেওয়ার প্যানেল, ক্যাশ ড্রয়ার আর নোট গোনা এখানে নেই।
 * বসালে ব্যবহারকারী ভাবতেন টাকা নেওয়া হয়ে গেছে, অথচ অর্ডার মাল সরায়
 * না আর বিলও হয় না। ⓘ নিচের শেষ পরীক্ষাটা ঐ সিদ্ধান্তটা ধরে রাখে —
 * কেউ "সরাসরি বিক্রয়ের মতো করে দেই" ভেবে প্যানেলটা তুলে আনলে লাল হবে।
 */
final class TheOrderFormHadNothingOnItTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        CompanyContext::set(Company::query()->where('code', 'TDEPOT')->firstOrFail()->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function form(): string
    {
        return (string) $this->actingAs($this->user)
            ->get(route('sales.order.create'))
            ->assertOk()
            ->getContent();
    }

    /** ⭐ ক্রেতার বকেয়া ও সীমা পাতার সাথেই যায়। */
    public function test_the_customers_dues_reach_the_page(): void
    {
        $customer = Customer::query()->firstOrFail();
        $customer->update(['credit_limit' => '5000']);

        $html = $this->form();

        $this->assertStringContainsString('salesOrderDesk(', $html,
            'অর্ডারের ডেস্কটাই পাতায় নেই — ক্রেতার পটি, বারকোড, কিছুই চলবে না।');

        $this->assertStringContainsString('&quot;limit&quot;:5000', $html, implode(PHP_EOL, [
            'ক্রেতার ক্রেডিট সীমা ব্রাউজারে পৌঁছায়নি।',
            '',
            'তাহলে সীমা ছাড়ানোর সতর্কবার্তাটা কোনোদিন উঠবে না, আর',
            'অর্ডারটা নেওয়ার পরে ডেলিভারির দিন ধরা পড়বে।',
        ]));

        $this->assertStringContainsString('&quot;due&quot;:', $html,
            'ক্রেতার বকেয়া ব্রাউজারে পৌঁছায়নি।');
    }

    /** ⭐ বারকোড → পণ্য, আর বারকোডহীন পণ্য তালিকায় নেই। */
    public function test_the_barcodes_reach_the_page_without_an_empty_key(): void
    {
        $product = Product::query()->firstOrFail();
        $product->update(['barcode' => 'ORD-BAR-77']);

        $html = $this->form();

        $this->assertStringContainsString('ORD-BAR-77', $html,
            'বারকোডটা ব্রাউজারে পৌঁছায়নি — স্ক্যান করলে কিছুই হবে না।');

        $this->assertStringNotContainsString('&quot;&quot;:&quot;', $html, implode(PHP_EOL, [
            'বারকোডের তালিকায় একটা ফাঁকা চাবি আছে।',
            '',
            'অর্থাৎ বারকোডহীন পণ্যগুলোও ঢুকেছে, আর তখন ফাঁকা ঘরে Enter',
            'চাপলে এলোমেলো একটা পণ্য সারিতে বসে যেত।',
        ]));
    }

    /** ⭐ মজুদের ইঙ্গিত — কিন্তু অর্ডার আটকায় না। */
    public function test_the_stock_hint_is_there_and_does_not_block(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('stockFor(row)', $html,
            'মজুদের ইঙ্গিতটা আঁকা হচ্ছে না।');

        $this->assertStringContainsString('stock: {', $html,
            'মজুদের তালিকাটা ব্রাউজারে পৌঁছায়নি, তাই ঘরটা চিরকাল খালি থাকবে।');

        /*
         * ⛔ পরিমাণের ঘরে `max` বসানো থাকলে ব্রাউজার নিজেই অর্ডার আটকে
         * দিত। ⓘ abos-8b-র কথা, আর কথাটা ঠিক: অর্ডার ভবিষ্যতের কাগজ,
         * মাল কাল আসতে পারে।
         */
        $this->assertStringNotContainsString(':max="stockFor(row)"', $html, implode(PHP_EOL, [
            'মজুদ দিয়ে পরিমাণ আটকে দেওয়া হচ্ছে।',
            '',
            'অর্ডার ভবিষ্যতের কাগজ — আজ মজুদ নেই মানে কাল মাল আসবে না,',
            'এমন নয়। ইঙ্গিতটা দেখাবে, আটকাবে না।',
        ]));
    }

    /** ⭐ মোটটা ভেঙে দেখানো হয় — এক সংখ্যায় নয়। */
    public function test_the_running_total_is_broken_down(): void
    {
        $html = $this->form();

        foreach (['subtotal.toFixed', 'discountTotal.toFixed', 'taxTotal.toFixed'] as $piece) {
            $this->assertStringContainsString($piece, $html,
                'চলমান যোগফলের একটা অংশ পাতায় নেই: '.$piece);
        }
    }

    /**
     * ⛔ টাকা নেওয়ার কিছুই এখানে নেই — মালিকের সিদ্ধান্ত।
     *
     * ⚠️ এই পরীক্ষাটা একটা **ব্যবসায়িক সিদ্ধান্ত** ধরে রাখে, কোনো কারিগরি
     * নিয়ম নয়। ⓘ মালিককে জিজ্ঞেস করে উত্তরটা পাওয়া গেছে; কেউ পরে
     * "সরাসরি বিক্রয়ের মতো করে দেই" ভেবে প্যানেলটা তুলে আনলে এটা লাল
     * হবে, আর তখন প্রশ্নটা আবার মালিকের কাছেই যাবে।
     */
    public function test_no_money_is_taken_on_an_order(): void
    {
        $html = $this->form();

        $found = [];

        foreach ([
            'directSale(' => 'কাউন্টারের পুরো শ্রেণিটা',
            'cashDrawer' => 'ক্যাশ ড্রয়ার',
            'noteCount' => 'নোট গোনা',
            'name="paid"' => 'টাকা নেওয়ার ঘর',
            'name="payment_mode"' => 'টাকার মাধ্যম',
        ] as $needle => $what) {
            if (str_contains($html, $needle)) {
                $found[] = $what.' ('.$needle.')';
            }
        }

        $this->assertSame([], $found, implode(PHP_EOL, [
            'অর্ডারের ফর্মে টাকা নেওয়ার জিনিস ঢুকেছে:',
            '',
            implode(PHP_EOL, $found),
            '',
            '⛔ মালিককে ২১ সেপ্টেম্বর ২০২৬-এ সরাসরি জিজ্ঞেস করা হয়েছিল,',
            'আর উত্তর ছিল: "না — অর্ডার আলাদা, টাকা পরে"।',
            '',
            'ঘরটা থাকলে ব্যবহারকারী ভাববেন টাকা নেওয়া হয়ে গেছে, অথচ',
            'অর্ডার মাল সরায় না আর বিলও হয় না। দরকার হলে মালিককে আবার',
            'জিজ্ঞেস করুন — অনুমান করবেন না।',
        ]));
    }

    /**
     * ⭐ বাকি ফর্মগুলোতে কিছুই বদলায়নি।
     *
     * ⓘ সারির সম্পাদকটা ছয়টা ফর্মে এক। ⚠️ মজুদ আর ভাঙা যোগফল দুইটাই
     * ঐচ্ছিক প্রপ, আর এই পরীক্ষাটা সেটাই মাপে — নাহলে একদিন কেউ ওগুলো
     * সবার জন্য চালু করে দিত আর ক্রয়ের ফর্মে বিক্রয়ের মজুদ বসত।
     */
    public function test_the_other_forms_did_not_change(): void
    {
        $html = (string) $this->actingAs($this->user)
            ->get(route('sales.challan.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('salesLineEditor(', $html,
            'ডেলিভারি চালানের ফর্মে সারির সম্পাদকটাই নেই — পরীক্ষাটা কিছু মাপছে না।');

        $this->assertStringNotContainsString('stockFor(row)', $html,
            'চালানের ফর্মে মজুদের ইঙ্গিতটা ঢুকে পড়েছে — প্রপটা আর ঐচ্ছিক নেই।');

        $this->assertStringNotContainsString('subtotal.toFixed', $html,
            'চালানের ফর্মে ভাঙা যোগফলটা ঢুকে পড়েছে।');
    }
}
