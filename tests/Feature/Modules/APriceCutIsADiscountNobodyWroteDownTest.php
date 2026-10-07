<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দর কমিয়ে লেখা — এমন ছাড় যা কেউ খাতায় লেখেনি।
 *
 * ── কী ভোঁতা ছিল ────────────────────────────────────────────────────
 * ABOS-এ **যেকোনো** ছাড়েই অনুমোদন লাগত — দশ টাকার ছাড়েও, দশ হাজারেরও।
 *
 * ফল দুইদিকেই খারাপ। কাউন্টারে পাঁচ টাকার ছাড় দিতে গিয়ে বিল আটকে থাকে,
 * তাই লোকে ছাড় দেওয়াই বন্ধ করে — বা আরও খারাপ, **দর কমিয়ে লেখে** যাতে
 * ছাড়ের ঘরটা ছুঁতে না হয়।
 *
 * তখন টাকাটা একইভাবে যায়, কিন্তু খাতায় ছাড়টা আর দেখাই যায় না — আর
 * "আমরা এই মাসে কত ছাড় দিলাম" প্রশ্নের উত্তর মিথ্যা হয়ে যায়।
 *
 * ── তাই নিয়মটা দর মাপে, ছাড়ের ঘর নয় ─────────────────────────────────
 * মান দাম থেকে কতটা সরা যাবে, আর সরলে কী — কিছুই না, সতর্কতা, নাকি
 * আটকানো। এতে দর কমিয়ে লেখার পথটাই বন্ধ হয়।
 */
class APriceCutIsADiscountNobodyWroteDownTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    /** মান দাম ১,০০০ টাকার একটা পণ্য। */
    private function product(): Product
    {
        $product = Product::query()->orderBy('id')->firstOrFail();

        $product->forceFill(['sale_price' => '1000'])->save();

        return $product->fresh();
    }

    private function policy(string $policy, int $tolerance, bool $below = true, bool $above = true): void
    {
        $this->settings()->set(PricingRule::POLICY, $policy);
        $this->settings()->set(PricingRule::TOLERANCE, $tolerance);
        $this->settings()->set(PricingRule::BELOW, $below);
        $this->settings()->set(PricingRule::ABOVE, $above);
        $this->settings()->flush();
    }

    private function bill(string $rate)
    {
        return app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product()->id, 'qty' => '1', 'rate' => $rate]]);
    }

    /**
     * ডিফল্টে কিছুই আটকায় না।
     *
     * ── কেন এটাই ঠিক ডিফল্ট ─────────────────────────────────────────
     * যে কোম্পানি কোনোদিন সীমা বসায়নি, সে কাউকে থামাতে বলেনি। কড়া
     * ডিফল্ট দিলে আপগ্রেডের দিন সকালে প্রতিটা কাউন্টার থেমে যেত, আর
     * কেউ জানত না কেন।
     */
    public function test_with_no_policy_set_nothing_is_stopped(): void
    {
        $this->assertNotNull($this->bill('1')->id, 'সীমা না বসিয়েও দর আটকে দেওয়া হচ্ছে।');
    }

    /**
     * সীমার ভেতরে থাকলে কিছুই হয় না।
     *
     * সীমাটার পুরো কাজই "এতটুকু সরা স্বাভাবিক" বলা। ভেতরে থেকেও
     * সতর্কতা এলে সেটা রোজ আসত, আর রোজ আসা সতর্কতা কেউ পড়ে না।
     */
    public function test_inside_the_tolerance_nothing_happens(): void
    {
        $this->policy(PricingRule::BLOCK, 5);

        $this->assertNotNull($this->bill('960')->id, '৪% সরে গিয়েও আটকে গেছে — সীমা ৫%।');
    }

    /**
     * সীমা ছাড়ালে, আর নীতি "আটকাও" হলে, সারিটা নেওয়া হয় না।
     */
    public function test_beyond_the_tolerance_a_block_policy_refuses_the_line(): void
    {
        $this->policy(PricingRule::BLOCK, 5);

        $this->expectException(ValidationException::class);

        $this->bill('800');
    }

    /**
     * "সতর্ক করো" বিল আটকায় না, কিন্তু চুপও থাকে না।
     *
     * ── কেন দুইটা আলাদা নীতি ────────────────────────────────────────
     * আটকানো মানে বিক্রয়টা হবে না। বেশিরভাগ ডিপো সেটা চায় না — তারা
     * চায় কেউ যেন জানে। আটকানোই একমাত্র বিকল্প হলে তারা নিয়মটাই বন্ধ
     * করে রাখত, আর তখন কেউ কিছুই জানত না।
     */
    public function test_a_warn_policy_records_the_bill_and_says_so(): void
    {
        $this->policy(PricingRule::WARN, 5);

        $invoice = $this->bill('800');

        $this->assertNotNull($invoice->id, 'সতর্কতার নীতিতেও বিলটা আটকে গেছে।');

        $this->assertNotEmpty(session('price_warnings'),
            'দর সীমার বাইরে, অথচ পর্দায় কিছুই বলা হয়নি।');
    }

    /**
     * উপরের দিকের পাহারা বন্ধ থাকলে বেশি দামে বেচা আটকায় না।
     *
     * মান দামের নিচে বেচলে টাকা যায়; উপরে বেচলে গ্রাহক যায়। কিছু ডিপো
     * কেবল প্রথমটা পাহারা দেয় — দ্বিতীয়টা তাদের কাছে বিক্রয়কর্মীর
     * কৃতিত্ব।
     */
    public function test_the_two_directions_are_separate_switches(): void
    {
        $this->policy(PricingRule::BLOCK, 5, below: true, above: false);

        $this->assertNotNull($this->bill('1500')->id,
            'উপরের পাহারা বন্ধ, তবু বেশি দামে বেচা আটকে গেছে।');

        $this->expectException(ValidationException::class);

        $this->bill('800');
    }

    /**
     * মান দাম বসানো না থাকলে কিছুই আটকায় না।
     *
     * ── কেন ─────────────────────────────────────────────────────────
     * শূন্যকে মান ধরে নিলে প্রতিটা দরই "অসীম শতাংশ উপরে" হত, আর
     * আটকানোর নীতিতে যে পণ্যের দাম বসানো হয়নি সেটা আর কোনোদিন বেচা
     * যেত না — একটা মাস্টার-ডাটার ফাঁক গোটা বিক্রয় থামিয়ে দিত।
     */
    public function test_a_product_with_no_standard_price_is_never_blocked(): void
    {
        $this->policy(PricingRule::BLOCK, 5);

        $product = Product::query()->orderBy('id')->firstOrFail();
        $product->forceFill(['sale_price' => '0'])->save();

        $invoice = app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'qty' => '1', 'rate' => '5000']]);

        $this->assertNotNull($invoice->id,
            'মান দাম বসানো নেই বলে পণ্যটা আর বেচাই যাচ্ছে না।');
    }

    /**
     * নীতিটা কন্ট্রোল প্যানেলে সত্যিই আছে।
     *
     * ── কেন এটার আলাদা পরীক্ষা ──────────────────────────────────────
     * উপরের সবগুলো সেটিং সরাসরি বসিয়ে দেখে। কিন্তু ব্যবহারকারী সেটা
     * পারেন না — তাঁর জন্য পর্দায় ঘরটা থাকতে হবে। ঘোষণা ছাড়া নিয়মটা
     * কোডে থাকত আর কেউ কোনোদিন চালু করতে পারত না।
     */
    public function test_the_policy_can_be_set_from_the_control_panel(): void
    {
        $declared = app(SettingsService::class)->definitions();

        foreach ([PricingRule::TOLERANCE, PricingRule::POLICY,
            PricingRule::BELOW, PricingRule::ABOVE] as $key) {
            $this->assertArrayHasKey($key, $declared, "{$key} কোথাও ঘোষণা করা হয়নি।");
        }

        $html = (string) $this->get(route('system_admin.control-panel', ['tab' => 'sales']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('settings['.PricingRule::POLICY.']', $html,
            'নীতির ঘরটা কন্ট্রোল প্যানেলে নেই।');
    }
}
