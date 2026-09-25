<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টার কেবল শেষ করতে পারত, থামতে পারত না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"নিশ্চিত করুন botam er jaygay duti butam dilam ekta khosora, r ekta
 * conf."* — আর পরে: *"হোল্ড বোতামটা lagbe na"*।
 *
 * ── ⛔ আগে যা হত ────────────────────────────────────────────────────
 * ⓘ কাউন্টারে একটাই বোতাম ছিল, আর সে সবসময় কাগজ **পাকা** করত। ⚠️ মাল
 * তোলা হয়ে গেছে অথচ গ্রাহক এখনো আসেননি, বা দাম নিয়ে কথা বাকি — তখন
 * বিক্রেতার হাতে দুইটাই পথ: হয় পাকা করে ফেলা, নয় সব মুছে ফেলা।
 *
 * ── ⓘ যন্ত্রটা নতুন নয় ──────────────────────────────────────────────
 * খসড়ার পথটা আগে থেকেই বসানো ([[DirectSaleService::hold()]]), কিন্তু সে
 * চালু হত কেবল **অনুমোদনের নিয়মে** — মানুষের হাতে কোনো সুইচ ছিল না।
 * ⭐ এখন বোতামটা ঐ একই পথেই যায়, তাই দ্বিতীয় আকারের কোনো অসমাপ্ত বিল
 * তৈরি হয় না।
 */
final class TheCounterCouldOnlyFinishAndNeverPauseTest extends TestCase
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

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        /*
         * ⓘ বাছাইগুলো [[TheCounterDepositWaitedForItsSignatureTest]]-এর
         * হুবহু — ⚠️ প্রথমে নিজে একটা শর্ত লিখেছিলাম (`is_sellable`),
         * আর ঐ কলামটা নেই। ⛔ নাম অনুমান করে লেখা শর্ত পরীক্ষাকে এমন
         * জায়গায় ভাঙে যার সাথে দাবিটার কোনো সম্পর্ক নেই।
         */
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $extra */
    private function sell(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
            ...$extra,
        ]);
    }

    private function lastInvoice(): SalesInvoice
    {
        return SalesInvoice::query()->latest('id')->firstOrFail();
    }

    /**
     * ⭐ "খসড়া রাখুন" চাপলে কাগজটা খসড়াই থাকে।
     */
    public function test_the_draft_button_leaves_the_bill_unfinished(): void
    {
        $this->sell(['save_as_draft' => '1']);

        $this->assertSame('draft', $this->lastInvoice()->status,
            '⛔ "খসড়া রাখুন" চাপার পরেও বিলটা পাকা হয়ে গেছে — অর্থাৎ বোতামটা '
            .'আসলে কিছুই আলাদা করে না, অথচ পর্দায় দুইটা বোতাম দেখা যায়।');
    }

    /**
     * ⛔ পাল্টা-দাবি, আর এটাই আসল পাহারা।
     *
     * ⚠️ কেবল উপরেরটা লিখলে "সব বিল খসড়া রাখো" লিখেও সবুজ পাওয়া যেত,
     * আর তখন কাউন্টারে **একটাও বিল পাকা হত না** — দেখতে সফল, অথচ
     * দিনের শেষে খাতায় কিছুই বসত না।
     */
    public function test_the_confirm_button_still_finishes_the_bill(): void
    {
        $this->sell(['save_as_draft' => '0']);

        $this->assertNotSame('draft', $this->lastInvoice()->status,
            '⛔ "নিশ্চিত করুন" চেপেও বিলটা খসড়া থেকে গেছে।');
    }

    /**
     * ⓘ ঘরটা না পাঠালে আগের আচরণই — পুরনো পথ, পুরনো ফল।
     *
     * ⚠️ এটা জরুরি, কারণ অন্য পথ থেকে আসা অনুরোধে (API, পুরনো খসড়া)
     * ঘরটা থাকবেই না। ⛔ অনুপস্থিতিকে "হ্যাঁ" ধরলে ওই প্রতিটা বিল
     * নীরবে খসড়া হয়ে পড়ে থাকত।
     */
    public function test_a_request_without_the_field_behaves_as_before(): void
    {
        $this->sell();

        $this->assertNotSame('draft', $this->lastInvoice()->status,
            '⛔ ঘরটা না থাকায় বিলটা খসড়া হয়ে গেছে — পুরনো পথের আচরণ বদলে গেছে।');
    }

    /**
     * ⛔ অচেনা মান দরজাতেই ফিরিয়ে দেওয়া হয়।
     *
     * ── ⚠️ এই দাবিটা একবার ভুলভাবে লেখা হয়েছিল ──────────────────────
     * প্রথমে এখানে `'0'` পাঠানো হত, আর নাম ছিল *"অচেনা মান হ্যাঁ নয়"*।
     * ⛔ মিউটেশন ধরিয়ে দিল ওটা **কিছুই মাপত না**: সেবার শর্তটা
     * `! empty()` করে দিলেও চারটা দাবিই সবুজ থাকত, কারণ PHP-তে
     * `! empty('0')` আর `'0' === '1'` একই উত্তর দেয়।
     *
     * ⭐ তাই এখন সত্যিকারের বিপজ্জনক মানটাই খাওয়ানো হয় — এমন একটা লেখা
     * যেটা PHP-তে **সত্য** বলে গোনা হত। ⓘ আর আসল পাহারাটা যে সেবায়
     * নয়, যাচাইয়ের নিয়মে (`in:0,1`), সেটাও এতে ধরা পড়ে।
     */
    public function test_a_truthy_but_unknown_value_is_refused_at_the_door(): void
    {
        $this->sell(['save_as_draft' => 'yes'])
            ->assertSessionHasErrors('save_as_draft');

        $this->assertSame(0, SalesInvoice::query()->count(),
            '⛔ অচেনা মান নিয়ে একটা বিল তৈরি হয়ে গেছে।');
    }

    /**
     * ⭐ খসড়া ছাপা হয় না — মালিকের নির্দেশ: *"খসড়া print hobe na"*।
     *
     * ── ⛔ এই দাবিটা একটা সত্যিকারের ফাঁক ধরেছিল ────────────────────
     * পাহারাটা লেখা ছিল [[SalesInvoice::isHeldAtCounter()]] দিয়ে, আর সে
     * **দুইটা** শর্ত মেলাত: খসড়া *আর* সইয়ের অপেক্ষায় একটা জমা।
     *
     * ⚠️ "খসড়া রাখুন" বোতামে বানানো কাগজে **কোনো জমাই নেই**, তাই
     * দ্বিতীয় শর্তটা মিথ্যা হত আর বিলটা দিব্যি ছাপা হয়ে যেত। ⓘ পাহারাটা
     * একটা **ঘটনা** ধরে লেখা ছিল, **অবস্থা** ধরে নয় — আর নতুন পথটা
     * ঐ ঘটনাটা ছাড়াই একই অবস্থায় পৌঁছায়।
     *
     * ⭐ তাই এখানে ঠিক সেই বিপজ্জনক কাগজটাই খাওয়ানো হয়: জমা ছাড়া
     * একটা খসড়া, আর ছাপার ঠিকানা সরাসরি।
     */
    public function test_a_hand_made_draft_cannot_be_printed(): void
    {
        $this->sell(['save_as_draft' => '1']);

        $invoice = $this->lastInvoice();

        $this->assertFalse($invoice->isHeldAtCounter(),
            '⚠️ এই কাগজে জমা থাকার কথা নয় — থাকলে দাবিটা পুরনো পাহারাটাই '
            .'মাপত, আর আসল ফাঁকটা ছুঁতই না।');

        $this->get(route('sales.print.invoice', $invoice))
            ->assertSessionHasErrors('status');

        $this->get(route('sales.print.draft', $invoice))
            ->assertSessionHasErrors('status');
    }

    /**
     * ⛔ পাল্টা-দাবি: পাকা বিল ঠিকই ছাপা হয়।
     *
     * ⚠️ নাহলে "সব ছাপা বন্ধ করো" লিখেও উপরেরটা সবুজ থাকত, আর তখন
     * কাউন্টারে **একটাও বিল ছাপা হত না** — আর সেটা পরদিন সকালে ধরা
     * পড়ত, গ্রাহকের সামনে।
     */
    public function test_a_finished_bill_still_prints(): void
    {
        $this->sell(['save_as_draft' => '0']);

        $this->get(route('sales.print.invoice', $this->lastInvoice()))
            ->assertOk();
    }

    /**
     * ⭐ পর্দায় বোতাম দুইটা সত্যিই মানটা পাঠায়।
     *
     * ── ⛔ এই দাবিটা একটা কমিট-করা বাগ ধরেছিল ───────────────────────
     * প্রথম লেখায় বোতাম দুইটা করত `@click="$refs.asDraft.value = '1'"`।
     * ⚠️ প্রকল্পটা `@alpinejs/csp` ব্যবহার করে, আর সেখানে এক্সপ্রেশনের
     * ভিতর থেকে **DOM-এর ঘরে লেখা নিষিদ্ধ** — Alpine চুপচাপ বাঁধাইটা
     * ছেড়ে দেয়, কোনো ত্রুটি ছাড়াই।
     *
     * ⛔ ফল: "খসড়া রাখুন" চাপলে বিলটা **পাকাই হয়ে যেত**। ⓘ আর এই
     * ফাইলের বাকি দাবিগুলো সবুজ থাকত, কারণ তারা সরাসরি POST করে —
     * ব্লেডটা কখনো চালায় না।
     *
     * ⭐ তাই দাবিটা **পর্দার লেখা** মাপে: দুইটা submit বোতাম, দুইটাই
     * `name="save_as_draft"`, আর মান `1` ও `0`। ⓘ HTML-এর নিয়মে
     * চাপা বোতামটার মানই ফর্মে যায়, তাই Alpine-এর দরকারই নেই।
     */
    public function test_the_two_buttons_really_carry_their_value(): void
    {
        $html = (string) $this->get(route('sales.direct.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="save_as_draft" value="1"', $html,
            '⛔ "খসড়া রাখুন" বোতামটা মানটা পাঠায় না — চাপলে বিলটা পাকা হয়ে যাবে।');

        $this->assertStringContainsString('name="save_as_draft" value="0"', $html,
            '⛔ "নিশ্চিত করুন" বোতামটা মানটা পাঠায় না।');

        /*
         * ⚠️ পাল্টা-যাচাই: পুরনো ভাঙা রূপটা যেন ফিরে না আসে। ⓘ `$refs`
         * দিয়ে DOM-এ লেখা CSP-Alpine-এ নীরবে ব্যর্থ হয়, তাই ওটা
         * থাকলে পর্দা দেখতে ঠিকই লাগত।
         */
        $this->assertStringNotContainsString('$refs.asDraft', $html,
            '⛔ DOM-এ লেখা ঐ পুরনো রূপটা ফিরে এসেছে — CSP-Alpine ওটা চুপচাপ ছেড়ে দেয়।');
    }

    /**
     * ⭐ খসড়াটা পরে নিশ্চিত করা যায়, আর তখন মালও গুদাম থেকে বেরোয়।
     *
     * ── ⛔ এই দাবিটা দুইটা আসল ফাঁক ধরেছিল ──────────────────────────
     * ⓘ ১ · শেষ করার দরজাটা ([[DirectSaleService::finishHeld()]]) দাবি
     * করত অন্তত একটা জমা-ভাউচার থাকতেই হবে। ⚠️ হাতে রাখা খসড়ায় কোনো
     * জমা নেই, তাই দরজাটা **চিরকাল বন্ধ** থাকত — বিলটা খসড়ায় আটকে
     * যেত, আর শেষ করার কোনো পথই থাকত না।
     *
     * ⓘ ২ · আর সাধারণ "নিশ্চিত" দরজাটা ঐ কাগজকে ঢুকতে **দিত** — কারণ
     * সেখানকার পাহারাও জমা খুঁজত। ⛔ ফলে বিলটা খাতায় বসত অথচ চালান
     * খসড়াই থাকত: কাগজে বিক্রি হয়ে গেছে, গুদামে মাল রয়ে গেছে, আর
     * কোথাও কিছু ভাঙত না।
     *
     * ⭐ তাই এখানে **মজুদের সংখ্যাটাই** মাপা হয় — কাগজের অবস্থা নয়।
     * ⓘ অবস্থা মাপলে দ্বিতীয় ফাঁকটা সবুজই থাকত, কারণ বিলটা তখনও
     * `confirmed` হত।
     */
    public function test_a_parked_draft_can_be_finished_and_the_goods_then_leave(): void
    {
        $stock = app(StockService::class);
        $before = $stock->floorQty($this->product, $this->warehouse);

        $this->sell(['save_as_draft' => '1']);
        $invoice = $this->lastInvoice();

        $this->assertSame($before, $stock->floorQty($this->product, $this->warehouse),
            '⛔ খসড়া অবস্থাতেই মাল গুদাম থেকে বেরিয়ে গেছে।');

        $this->post(route('sales.invoice.confirm', $invoice))
            ->assertSessionHasNoErrors();

        $this->assertNotSame('draft', $invoice->fresh()->status,
            '⛔ খসড়াটা নিশ্চিত করা গেল না — শেষ করার দরজা বন্ধ।');

        $this->assertSame(0, bccomp(
            bcsub($before, $stock->floorQty($this->product, $this->warehouse), 4), '10', 4),
            '⛔ বিল নিশ্চিত হয়েছে অথচ মাল গুদামেই রয়ে গেছে — কাগজে বিক্রি, '
            .'বাস্তবে নয়।');
    }
}
