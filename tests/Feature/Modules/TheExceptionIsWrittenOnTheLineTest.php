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
use App\Modules\MasterData\Models\Tax;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ব্যতিক্রমটা সারিতেই লেখা থাকে।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * দুইটা আলাদা জায়গায় একই আকারের ফাঁক ছিল, আর দুইটাই **নীরব**:
 *
 * **১ · দরের সতর্কতা।** [[SalesInvoiceService]]-এর মন্তব্য বলত *"সারির
 * বিবরণে লেখা থাকলে ছয় মাস পরেও কাগজ দেখেই বোঝা যায়"* — কিন্তু কোডটা
 * পাঠাত `session()->flash()`-এ, অর্থাৎ **পরের এক পাতা পর্যন্ত**।
 * মন্তব্যটা যে জিনিসটাকে সমস্যা বলছিল, কোডটা ঠিক সেটাই করত।
 *
 * **২ · ভ্যাটের অঙ্ক।** ক্লায়েন্ট অঙ্ক পাঠালে সেটাই মানা হত, আর পণ্যের
 * নিজের হারের সাথে কোথাও মেলানো হত না। ছাড়ের সীমা আছে; ভ্যাটের নেই।
 *
 * দুইটার ফল এক: **নিয়মের বাইরে যাওয়া সংখ্যাটা খাতায় কোনো চিহ্ন রাখত
 * না।** ছয় মাস পরে কেউ জিজ্ঞেস করলে উত্তর দেওয়ার কিছু থাকত না।
 *
 * ── কেন এই পাহারাটা লাগে ─────────────────────────────────────────────
 * ঘর দুইটা `nullable`, আর প্রায় সব সারিতে `null`ই থাকবে। কেউ লেখার
 * লাইনটা তুলে দিলে **কোনো টেস্ট লাল হত না, কোনো পাতা ভাঙত না** —
 * কেবল ছয় মাস পরে খোঁজার সময় কিছু পাওয়া যেত না। ঠিক আগের অবস্থা,
 * আর কেউ টের পেত না যে ফিরে এসেছে।
 */
class TheExceptionIsWrittenOnTheLineTest extends TestCase
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

    /** মান দাম ১,০০০ টাকা, আর চাইলে একটা করের হার। */
    private function product(?Tax $tax = null, int $nth = 0): Product
    {
        $product = Product::query()->orderBy('id')->skip($nth)->firstOrFail();

        $product->forceFill([
            'sale_price' => '1000',
            'tax_id' => $tax?->id,
        ])->save();

        return $product->fresh();
    }

    private function tenPercent(): Tax
    {
        return Tax::query()->create([
            'code' => 'VAT10',
            'name_en' => 'VAT 10%',
            'rate' => '10',
            'is_inclusive' => false,
        ]);
    }

    private function policy(string $policy, int $tolerance): void
    {
        $this->settings()->set(PricingRule::POLICY, $policy);
        $this->settings()->set(PricingRule::TOLERANCE, $tolerance);
        $this->settings()->set(PricingRule::BELOW, true);
        $this->settings()->set(PricingRule::ABOVE, true);
        $this->settings()->flush();
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function bill(array $lines)
    {
        return app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], $lines);
    }

    // ── দরের সতর্কতা ─────────────────────────────────────────────────

    /**
     * সীমার বাইরের দর সারিতেই লেখা থাকে — সেশনে নয়।
     *
     * ১,০০০ টাকার পণ্য ৮০০-তে, সীমা ৫% ⇒ সরে গেছে **−২০%**।
     */
    public function test_a_price_outside_the_rule_is_written_on_the_line(): void
    {
        $this->policy(PricingRule::WARN, 5);

        $invoice = $this->bill([
            ['product_id' => $this->product()->id, 'qty' => '1', 'rate' => '800'],
        ]);

        $line = $invoice->lines()->firstOrFail();

        $this->assertNotNull($line->price_variance, 'সতর্কতাটা আবার হারিয়ে গেছে।');
        $this->assertSame(0, bccomp('-20', (string) $line->price_variance, 2));
    }

    /** সীমার ভেতরে থাকলে কিছুই লেখা হয় না। */
    public function test_a_price_inside_the_rule_leaves_no_mark(): void
    {
        $this->policy(PricingRule::WARN, 5);

        $invoice = $this->bill([
            ['product_id' => $this->product()->id, 'qty' => '1', 'rate' => '980'],
        ]);

        $this->assertNull($invoice->lines()->firstOrFail()->price_variance);
    }

    /**
     * এক সারির ব্যতিক্রম পরের সারিতে গড়ায় না।
     *
     * ── কেন এই পরীক্ষাটা আলাদা করে আছে ──────────────────────────────
     * প্রথম খসড়ায় `$priceVariance` লুপের ভেতরে শূন্য করা হয়নি, তাই
     * আগের সারির শতাংশটা পরের সারিতেও বসে যেত। **চোখে ধরা পড়ত না,
     * কারণ সংখ্যাটা বিশ্বাসযোগ্যই দেখাত** — একটা সত্যিকারের শতাংশ,
     * শুধু ভুল সারিতে।
     */
    public function test_one_line_exception_does_not_bleed_into_the_next(): void
    {
        $this->policy(PricingRule::WARN, 5);

        $invoice = $this->bill([
            ['product_id' => $this->product(nth: 0)->id, 'qty' => '1', 'rate' => '800'],
            ['product_id' => $this->product(nth: 1)->id, 'qty' => '1', 'rate' => '1000'],
        ]);

        $lines = $invoice->lines()->orderBy('line_no')->get();

        $this->assertNotNull($lines[0]->price_variance, 'প্রথম সারির ব্যতিক্রমটাই লেখা হয়নি।');
        $this->assertNull($lines[1]->price_variance, 'আগের সারির ব্যতিক্রম পরের সারিতে গড়িয়েছে।');
    }

    // ── ভ্যাটের অঙ্ক ─────────────────────────────────────────────────

    /**
     * হাতে দেওয়া ভ্যাট হারের অঙ্ক থেকে সরে গেলে পার্থক্যটা বসে।
     *
     * ১,০০০ টাকায় ১০% ⇒ হারের অঙ্ক ১০০। হাতে দেওয়া হলো ৬০ ⇒ **−৪০**।
     */
    public function test_a_hand_typed_tax_records_how_far_it_sits_from_the_rate(): void
    {
        $product = $this->product($this->tenPercent());

        $invoice = $this->bill([
            ['product_id' => $product->id, 'qty' => '1', 'rate' => '1000', 'tax' => '60'],
        ]);

        $line = $invoice->lines()->firstOrFail();

        $this->assertSame(0, bccomp('60', (string) $line->tax, 2), 'টাইপ করা অঙ্কটাই বদলে দেওয়া হয়েছে।');
        $this->assertSame(0, bccomp('-40', (string) $line->tax_variance, 2));
    }

    /** হারের সাথে মিলে গেলে কোনো চিহ্ন নয়। */
    public function test_a_tax_that_matches_the_rate_leaves_no_mark(): void
    {
        $product = $this->product($this->tenPercent());

        $invoice = $this->bill([
            ['product_id' => $product->id, 'qty' => '1', 'rate' => '1000', 'tax' => '100'],
        ]);

        $this->assertNull($invoice->lines()->firstOrFail()->tax_variance);
    }

    /**
     * অঙ্ক না পাঠালে হার থেকেই গোনা হয়, তাই সরে যাওয়ার প্রশ্নই নেই।
     */
    public function test_a_tax_left_to_the_rate_has_nothing_to_differ_from(): void
    {
        $product = $this->product($this->tenPercent());

        $invoice = $this->bill([
            ['product_id' => $product->id, 'qty' => '1', 'rate' => '1000'],
        ]);

        $line = $invoice->lines()->firstOrFail();

        $this->assertSame(0, bccomp('100', (string) $line->tax, 2));
        $this->assertNull($line->tax_variance);
    }

    /**
     * পণ্যের কোনো হার বসানো না থাকলেও চিহ্ন নয়।
     *
     * "কোথা থেকে সরল" প্রশ্নের উত্তর তখন নেই, আর উত্তরহীন প্রশ্নের
     * একটা সংখ্যা বানিয়ে রাখা মানে পরের জনকে বিভ্রান্ত করা।
     */
    public function test_a_product_with_no_rate_records_nothing(): void
    {
        $invoice = $this->bill([
            ['product_id' => $this->product()->id, 'qty' => '1', 'rate' => '1000', 'tax' => '75'],
        ]);

        $line = $invoice->lines()->firstOrFail();

        $this->assertSame(0, bccomp('75', (string) $line->tax, 2));
        $this->assertNull($line->tax_variance);
    }
}
