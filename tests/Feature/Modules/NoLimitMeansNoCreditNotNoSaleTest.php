<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * লিমিট নাই মানে **বাকি নাই** — বিক্রি নাই নয়।
 *
 * ── মালিকের নিয়ম, ৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * *"limit nai bill hobe na"* — যাঁর ধারের সীমা বসানো হয়নি, তাঁকে বাকিতে
 * মাল দেওয়া যাবে না।
 *
 * ⭐ সুইচটা আগে থেকেই ছিল (`customer.zero_limit_blocks`), আর ডিফল্টে বন্ধ
 * — ইচ্ছাকৃতভাবে, কারণ চালু করলে যাঁদের লিমিট বসানোই হয়নি তাঁরা সবাই
 * পরদিন সকালে আটকে যেতেন।
 *
 * ── ⛔ কিন্তু সুইচটা টেপা যেত না ─────────────────────────────────────
 * চালু করলে **নগদ বিক্রিও আটকে যেত**। যাচাইটা দেখত বিলের **পুরো** অঙ্ক,
 * আর কাউন্টারে গোনা টাকাটা বসত তার **পরে**। ⓘ ফলে ক্রেতা পুরো দাম হাতে
 * গুনে দিলেও ব্যবস্থা বলত "সীমা ছাড়িয়েছে" — যদিও তাতে কারও এক পয়সা ধারও
 * বাড়ত না।
 *
 * ⚠️ নিয়মটা কোডে ছিল না, **মন্তব্যে ছিল**: *"সীমাটা বাকির সীমা। টাকা হাতে
 * দিয়ে মাল নিলে কারও ধার বাড়ে না।"* — আর ঠিক নিচেই স্বীকারোক্তি, *"নগদ
 * বিক্রিতেও এক মুহূর্তের জন্য পাওনা জন্মায়; সেটা সামলাতে সেটিংটা বন্ধ
 * রাখার পথ খোলা আছে।"* ⛔ ওটা সামলানো নয়, **পুরো নিয়মটা বন্ধ করা**।
 *
 * ── কেন এই ফাইলটা দরকার ─────────────────────────────────────────────
 * এখানকার ভুল দুই দিকেই নীরব। ⓘ একদিকে গ্রাহক সীমাহীন বাকি নিয়ে যান আর
 * কোথাও কিছু ভাঙে না; অন্যদিকে সুইচ টিপলে **পুরো কাউন্টার বন্ধ** হয়ে যায়
 * আর কারণটা কেউ বুঝতে পারেন না।
 *
 * ⚠️ দুইটা পথ আলাদা করে মাপা — সরাসরি বিক্রয় আর POS। একটার প্রমাণ
 * অন্যটার প্রমাণ নয়: `confirm()` দুই জায়গা থেকে ডাকা হয়, আর ৭ সেপ্টেম্বরের
 * আগে **দুইটাই** টাকার কথা বলত না।
 *
 * ── ⚠️ কেন [[ZeroMeansZeroOnTheDayYouSayTest]] এটা ধরেনি ────────────
 * ওই ফাইলটা এই নিয়মেরই পাহারা, আর ভালো পাহারা — সুইচ, চাবি, ভুল চাবি,
 * সীমাওয়ালা ডিলার, সবই মাপা। ⛔ **কিন্তু তার একটা পরীক্ষাও এক পয়সা টাকা
 * গোনে না।** সবগুলো `SalesInvoiceService`-কে সরাসরি ডাকে, আর সেখানে
 * বিলটা পুরোপুরি বাকিই থাকে।
 *
 * ⓘ তাই "নিয়মটা কাজ করে" প্রমাণিত ছিল, আর "নিয়মটা চালু করলে কাউন্টার
 * বন্ধ হয়ে যায়" কেউ জানত না। ⭐ এটাই সেই ছাঁচ: **যে অবস্থাটা কোনো
 * পরীক্ষা তৈরিই করে না, সেটা ভাঙা আছে কি না তা কেউ বলতে পারে না।**
 */
class NoLimitMeansNoCreditNotNoSaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        /*
         * ⛔ বিক্রয়কর্মী, মালিক নয় — আর এটাই এই ফাইলের সবচেয়ে জরুরি লাইন।
         *
         * ⚠️ [[CustomerPolicy::overrideCreditLimit()]] `customer.credit_limit
         * .override` চাবিওয়ালা যে কাউকে ছেড়ে দেয়। মালিকের কাছে সব চাবি,
         * তাই তাঁর নামে চালালে **প্রতিটা দাবি পাশ করত — যাচাইটা ভাঙা
         * থাকলেও**।
         *
         * ⓘ নিচে `test_the_actor_cannot_simply_override_the_limit()` এটা
         * প্রতি রানে মেপে দেখে, যাতে কোনোদিন ভূমিকায় চাবিটা যোগ হলে
         * পাহারাটা নীরবে অন্ধ হয়ে না যায়।
         */
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** সীমা বসানো — `null` মানে ঘরটা শূন্য, অর্থাৎ "লিমিট নাই"। */
    private function limit(?string $amount): void
    {
        $this->customer->forceFill(['credit_limit' => $amount ?? '0'])->save();
        $this->customer->refresh();
    }

    /** মালিকের সুইচ — "শূন্য মানে শূন্য"। */
    private function zeroBlocks(bool $on): void
    {
        app(SettingsService::class)->set('customer.zero_limit_blocks', $on);
    }

    /**
     * সরাসরি বিক্রয় — ১০ × ১০০ = ১,০০০ টাকার বিল।
     *
     * @return array{challan: mixed, invoice: mixed, change: string}
     */
    private function sellDirect(string $paying): array
    {
        return app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'deposit' => $paying,
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        );
    }

    /**
     * POS — একই ১,০০০ টাকার কার্ট, যাতে দুই পথের সংখ্যা তুলনীয় থাকে।
     *
     * @return array{invoice: mixed, change: string}
     */
    private function sellPos(string $paying): array
    {
        return app(PosService::class)->checkout(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'paid' => $paying,
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        );
    }

    // ── ⛔ পাহারাটা অন্ধ কি না ───────────────────────────────────────────

    /**
     * যিনি বেচছেন তিনি সীমাটা নিজেই পার করাতে পারেন না।
     *
     * ⚠️ এই দাবিটা বাকি সবগুলোর ভিত্তি। ⓘ অভিনেতার কাছে
     * `customer.credit_limit.override` থাকলে [[CustomerPolicy]] প্রতিবার
     * ছেড়ে দিত, আর নিচের "আটকায়" দাবিগুলো **সবুজ থাকত অথচ কিছুই মাপত
     * না** — ঠিক সেই ছাঁচ যেটা এই রিপোতে আগে ধরা পড়েছে।
     */
    public function test_the_actor_cannot_simply_override_the_limit(): void
    {
        $this->assertFalse(
            auth()->user()->can('customer.credit_limit.override'),
            "এই পরীক্ষাগুলো এমন একজনের নামে চলছে যিনি সীমাটা নিজেই পার করাতে পারেন।\n"
            ."ফলে নিচের প্রতিটা \"আটকায়\" দাবি পাশ করত — যাচাইটা পুরো মুছে ফেললেও।\n"
            .'ভূমিকায় চাবিটা যোগ হয়ে থাকলে অন্য কোনো ভূমিকা বাছুন, দাবিটা তুলবেন না।',
        );
    }

    // ── ⭐ মালিকের নিয়ম: লিমিট নাই → বাকি নাই ───────────────────────────

    /** সুইচ চালু, লিমিট নাই, টাকা নাই — বিল বসবে না। */
    public function test_no_limit_and_no_money_is_refused_at_the_counter(): void
    {
        $this->zeroBlocks(true);
        $this->limit(null);

        $this->expectException(ValidationException::class);

        $this->sellDirect('0');
    }

    /** একই নিয়ম POS-এ — আলাদা পথ, আলাদা প্রমাণ। */
    public function test_no_limit_and_no_money_is_refused_at_the_pos(): void
    {
        $this->zeroBlocks(true);
        $this->limit(null);

        $this->expectException(ValidationException::class);

        $this->sellPos('0');
    }

    // ── ⛔ আসল সারাই: নগদ বিক্রি তবু চলে ────────────────────────────────

    /**
     * পুরো দাম হাতে দিলে সীমার প্রশ্নই ওঠে না।
     *
     * ⛔ ৭ সেপ্টেম্বরের আগে এটা **ব্যর্থ হত** — আর তাতে সুইচটা টেপাই যেত না।
     */
    public function test_cash_in_hand_still_buys_goods_without_any_limit(): void
    {
        $this->zeroBlocks(true);
        $this->limit(null);

        $result = $this->sellDirect('1000');

        $this->assertSame(
            DocumentStatus::CONFIRMED,
            $result['invoice']->status,
            "পুরো ১,০০০ টাকা হাতে দেওয়ার পরেও বিলটা বসেনি।\n"
            ."নগদে মাল নিলে কারও ধার বাড়ে না, তাই এখানে সীমার কোনো ভূমিকা নেই।\n"
            .'এটা ভাঙা থাকলে সুইচ চালু করা মানে পুরো কাউন্টার বন্ধ করে দেওয়া।',
        );
    }

    /** POS-এও তাই — নগদ বিক্রি থামে না। */
    public function test_cash_in_hand_still_buys_goods_at_the_pos(): void
    {
        $this->zeroBlocks(true);
        $this->limit(null);

        $result = $this->sellPos('1000');

        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->status);
    }

    /**
     * ⚠️ আধা টাকা মানে আধা বাকি — আর বাকিটাই আটকায়।
     *
     * ⓘ এই দাবিটা না থাকলে সারাইটা "যা এলো তাই ছেড়ে দাও"-তে নেমে যেতে
     * পারত, আর তখন ১ টাকা গুনে ১,০০০ টাকার বাকি নেওয়া যেত।
     */
    public function test_part_payment_still_leaves_credit_and_credit_is_refused(): void
    {
        $this->zeroBlocks(true);
        $this->limit(null);

        $this->expectException(ValidationException::class);

        $this->sellDirect('400');
    }

    // ── সীমা বসানো থাকলে ───────────────────────────────────────────────

    /**
     * সীমা ৫০০, বিল ১,০০০, হাতে ৬০০ — বাকি ৪০০, সীমার নিচে। ✅
     *
     * ⭐ এটাই সারাইয়ের আসল আকার: যাচাইটা **যতটুকু বাকি থাকছে** তা দেখে,
     * বিলের অঙ্ক নয়।
     */
    public function test_a_part_paid_bill_is_measured_by_what_is_left_unpaid(): void
    {
        $this->zeroBlocks(false);
        $this->limit('500');

        $result = $this->sellDirect('600');

        $this->assertSame(
            DocumentStatus::CONFIRMED,
            $result['invoice']->status,
            "১,০০০ টাকার বিলে ৬০০ দেওয়ার পর বাকি থাকে ৪০০ — ৫০০ সীমার নিচে।\n"
            .'পুরো অঙ্ক ধরে মাপলে এটা আটকাত, আর মানুষ সীমাটাই তুলে দিতেন।',
        );
    }

    /** সীমা ৫০০, বিল ১,০০০, হাতে ২০০ — বাকি ৮০০, সীমার উপরে। ⛔ */
    public function test_a_part_paid_bill_over_the_limit_is_still_refused(): void
    {
        $this->zeroBlocks(false);
        $this->limit('500');

        $this->expectException(ValidationException::class);

        $this->sellDirect('200');
    }

    // ── ⚠️ সুইচ বন্ধ থাকলে কিছুই বদলায়নি ───────────────────────────────

    /**
     * ডিফল্ট আচরণ অক্ষত — লিমিট নাই মানে সীমাহীন।
     *
     * ⛔ এটা না মাপলে সারাইটা নীরবে নিয়ম বদলে দিত, আর লাইভে TDEPOT-এর
     * ৩২ জনের ৩১ জনই পরদিন সকালে আটকে যেতেন। ⓘ সুইচটা কখন টেপা হবে
     * সেটা মালিকের সিদ্ধান্ত, কোডের নয়।
     */
    public function test_with_the_switch_off_a_missing_limit_still_means_unlimited(): void
    {
        $this->zeroBlocks(false);
        $this->limit(null);

        $result = $this->sellDirect('0');

        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->status);
    }

    /**
     * ⭐ যে ছাড়টা দেওয়া হয়, তার পেছনে **সত্যিকারের টাকা** থাকে।
     *
     * ── ⚠️ প্রথমে এই দাবিটা উল্টো লেখা ছিল, ৭ সেপ্টেম্বর ২০২৬ ────────
     * লিখেছিলাম *"বাড়তি টাকা পুরনো বকেয়ার বিপরীতে জায়গা কিনতে পারে
     * না"*, আর সেটা ব্যর্থ হয়েছিল। ⛔ **কোড ঠিক ছিল, দাবিটাই ভুল ছিল।**
     *
     * ⓘ ৫,০০০ টাকা সত্যিই টিলে গেছে, আদায়ের কাগজ হয়েছে, খতিয়ানে বসেছে।
     * উদ্বৃত্ত ৪,০০০ গ্রাহকের **অগ্রিম** — তাঁর নিজের টাকা আমাদের হাতে।
     * তার বিপরীতে পরের বিলটা ধার নয়, আর ধার নয় বলেই ধারের সীমা ওখানে
     * কিছু বলে না।
     *
     * ⚠️ দাবিটা রেখে দিলে এটা এমন একটা পাহারা হত যে **সঠিক কোডকে দোষী
     * বলে** — আর সেরকম পাহারা এক সপ্তাহে উপেক্ষিত হয়, তারপর তার সাথে
     * আসল অভিযোগগুলোও উপেক্ষিত হয়।
     *
     * ── ⭐ তাই আসল দাবিটা এখানে ─────────────────────────────────────
     * জায়গাটা যেন **কেবল বসে যাওয়া টাকা থেকেই** আসে। ⓘ `$payingNow`
     * যাচাইয়ে যায় নিশ্চিত করার **আগে**, আর আদায়ের কাগজ বসে **পরে** —
     * অর্থাৎ একটা সংখ্যা দুই ভূমিকায়। ⛔ ওই দুইটা কোনোদিন আলাদা হয়ে গেলে
     * গোনা-না-হওয়া টাকা দেখিয়ে সীমা পার করানো যেত, আর খাতায় তার কোনো
     * চিহ্ন থাকত না।
     */
    public function test_the_room_it_buys_is_backed_by_money_that_really_posted(): void
    {
        $this->zeroBlocks(false);
        $this->limit('500');

        // প্রথম বিলে ৪৫০ বাকি রেখে যাওয়া — সীমার ভেতরেই
        $this->sellDirect('550');

        $this->assertSame(
            0,
            bccomp($this->customer->fresh()->outstanding(), '450', 4),
            'প্রথম বিলের পর বকেয়া ৪৫০ হওয়ার কথা — ১,০০০ টাকার বিলে ৫৫০ জমা।',
        );

        // দ্বিতীয় বিল ১,০০০, হাতে ৫,০০০ — উদ্বৃত্ত ৪,০০০ অগ্রিম হয়ে বসে
        $result = $this->sellDirect('5000');
        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->status);

        /*
         * ৪৫০ + ১,০০০ − ৫,০০০ = −৩,৫৫০ — ঋণাত্মক, অর্থাৎ **আমরা তাঁর
         * কাছে ঋণী**।
         *
         * ⛔ এটাই সেই দাবি যা টাকাটাকে সংখ্যার সাথে বেঁধে রাখে। ⓘ যাচাইয়ে
         * যে ৫,০০০ দেখানো হয়েছিল, খতিয়ানেও ঠিক সেই ৫,০০০ বসেছে — নাহলে
         * এই সংখ্যাটা মিলত না।
         */
        $this->assertSame(
            0,
            bccomp($this->customer->fresh()->outstanding(), '-3550', 4),
            "যাচাইয়ে দেখানো টাকা আর খাতায় বসা টাকা এক নয়।\n"
            .'অর্থাৎ সীমার ছাড়টা এমন টাকার ভরসায় দেওয়া হয়েছে যেটা কোথাও বসেনি।',
        );

        /*
         * ⭐ আর অগ্রিমটা ফুরালে সীমা আবার কথা বলে।
         *
         * ⓘ −৩,৫৫০ থেকে পরের বিলগুলো বাকি রেখে গেলে একসময় বকেয়া আবার
         * ৫০০ ছাড়ায়, আর তখন দরজাটা বন্ধ হয়। ⚠️ এটা না মাপলে "অগ্রিম
         * থাকলে সীমা নেই" হয়ে যেত — একবার বেশি টাকা দিয়ে চিরকালের ছাড়।
         */
        for ($i = 0; $i < 4; $i++) {
            $this->sellDirect('0');
        }

        // ৪টা বিলের পর: −৩,৫৫০ + ৪,০০০ = ৪৫০ — এখনো সীমার ভেতরে
        $this->assertSame(0, bccomp($this->customer->fresh()->outstanding(), '450', 4));

        // পরেরটা বকেয়াকে ১,৪৫০-এ নিত — ৫০০ সীমা ছাড়িয়ে
        $this->expectException(ValidationException::class);

        $this->sellDirect('0');
    }
}
