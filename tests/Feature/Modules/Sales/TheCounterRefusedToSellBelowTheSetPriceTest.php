<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PricingRule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নির্ধারিত দামের নিচে বিক্রয় আর নেওয়া হয় না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"nirdarito sales prices er niche entry nibe na"*।
 *
 * ── ⚠️ কেন এই ফাইলটা থাকতেই হবে ──────────────────────────────────────
 * ⓘ যন্ত্রটা নতুন নয় — [[PricingRule]] আগে থেকেই ছিল, কেবল নীতিটা
 * `allow`-এ বসানো ছিল, অর্থাৎ শুধু খাতায় লিখে রাখত।
 *
 * ⛔ আর [[DemoSeeder]] ডেমো কোম্পানিতে নীতিটা `allow`-ই রাখে (কারণ
 * ওখানে লেখা)। ⚠️ ফলে **গোটা সুইটে নিয়মটা কোনোদিন চলে না** — ঠিক সেই
 * "শাখা কখনো ছোঁয়া হয়নি" ফাঁদ: কোড আছে, সবুজও আছে, অথচ অর্ধেকটা
 * অপরীক্ষিত।
 *
 * ⭐ তাই এই ফাইলটা নীতিটা **নিজে চালু করে** নেয়, আর দুই দিকেই মাপে:
 * নিচে আটকায়, আর নির্ধারিত দামে ও তার উপরে আটকায় না।
 */
final class TheCounterRefusedToSellBelowTheSetPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();

        $settings = app(SettingsService::class);
        $settings->set(PricingRule::POLICY, PricingRule::BLOCK);
        $settings->set(PricingRule::BELOW, true);
        $settings->set(PricingRule::ABOVE, false);
        $settings->set(PricingRule::TOLERANCE, '0');
        $settings->flush();
    }

    /** ⛔ নির্ধারিত দামের নিচে — নেওয়া হয় না। */
    public function test_a_rate_below_the_set_price_is_refused(): void
    {
        $under = bcsub($this->standard(), '1', 4);

        $this->expectException(ValidationException::class);

        $this->sell($under);
    }

    /**
     * ⭐ নির্ধারিত দামেই বেচা যায়।
     *
     * ⚠️ এই দাবিটা ছাড়া উপরেরটা অর্ধেক: ⛔ একটা যন্ত্র যদি **সব** দরই
     * আটকাত, সেও উপরের দাবিটা পাস করত — আর তখন কাউন্টারে কিছুই বেচা
     * যেত না, অথচ সুইট সবুজ।
     */
    public function test_the_set_price_itself_still_sells(): void
    {
        $invoice = $this->sell($this->standard());

        $this->assertNotNull($invoice, 'ⓘ নির্ধারিত দামেই বিক্রয় আটকে গেছে — সহনশীলতা ০ মানে "সমান চলবে"।');
    }

    /**
     * ⭐ আর উপরে বেচাও চলে — মালিক কেবল নিচের কথা বলেছেন।
     *
     * ⓘ কোডের পুরনো মন্তব্যেই কারণটা লেখা: নিচে বেচলে টাকা যায়, উপরে
     * বেচলে ওটা বিক্রয়কর্মীর কৃতিত্ব।
     */
    public function test_a_rate_above_the_set_price_is_allowed(): void
    {
        $over = bcadd($this->standard(), '100', 4);

        $this->assertNotNull($this->sell($over),
            '⛔ বেশি দামে বিক্রয় আটকেছে — `price_policy_above` বন্ধ থাকার কথা।');
    }

    /**
     * ⭐ আর নীতিটা বন্ধ থাকলে নিচেও চলে।
     *
     * ⚠️ এটাই প্রমাণ করে সুইচটা সত্যিই সুইচ। ⛔ না মাপলে উপরের দাবিগুলো
     * সবুজ থাকত এমন একটা যন্ত্রেও যেটা **সবসময়** আটকায়, নীতি যা-ই হোক —
     * আর তখন ডেমো ডেটাও অচল হয়ে যেত।
     */
    public function test_with_the_policy_off_a_low_rate_goes_through(): void
    {
        $settings = app(SettingsService::class);
        $settings->set(PricingRule::POLICY, PricingRule::ALLOW);
        $settings->flush();

        $this->assertNotNull($this->sell(bcsub($this->standard(), '1', 4)),
            '⛔ নীতি বন্ধ, তবু আটকাচ্ছে — অর্থাৎ সুইচটা কিছুই নিয়ন্ত্রণ করে না।');
    }

    /** ⓘ এই পণ্যের নির্ধারিত বিক্রয়মূল্য। */
    private function standard(): string
    {
        return (string) $this->product->sale_price;
    }

    private function sell(string $rate): ?object
    {
        $this->actingAs($this->owner);

        return app(\App\Modules\Sales\Services\SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $rate]],
        );
    }
}
