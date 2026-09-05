<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ক্রয়ের কাউন্টার — মালিক যেভাবে এঁকেছেন।
 *
 * ── কেন এই পরীক্ষাটা আছে ────────────────────────────────────────────
 * মালিকের কথা (৫ সেপ্টেম্বর ২০২৬): *"vitorer likha gulo purches er
 * moto hobe but outlooking emon hote hobe"* — ভিতরের শব্দ ক্রয়ের, কিন্তু
 * **চেহারাটা হুবহু তাঁর স্ক্রিনশটের**।
 *
 * ⛔ চেহারা কোনো টেস্ট মাপতে পারে না, আর এই পরীক্ষাটা সেই দাবিও করে না।
 * ⭐ যা মাপা যায় তা হলো **উপাদানগুলো আছে কিনা** — কারণ এই রিপো একবার
 * শিখেছে যে *"রং মিলে গেছে"* আর *"জিনিসটা তৈরি"* এক কথা নয়
 * ([[AClonePromisedAShapeAndDrewAnotherTest]]-এর একই যুক্তি)।
 *
 * ⚠️ ছয় মাস পরে কেউ একটা ঘর সরিয়ে দিলে পর্দা ভাঙবে না, কেবল মালিকের
 * নকশার একটা টুকরা নীরবে হারাবে। ⓘ তখন এই লাল টেস্টটাই একমাত্র খবর।
 */
class TheBuyingCounterLooksLikeTheOwnerDrewItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    private function screen(): TestResponse
    {
        return $this->get(route('purchase.direct.create'))->assertOk();
    }

    /**
     * পর্দায় লেখাটা আছে কি না — **এস্কেপসহ**।
     *
     * ── কেন `assertSee($text, false)` চলে না ────────────────────────
     * ⛔ ডেমোর ব্যবহারকারীর ভাষা বাংলা, তাই পাতাটা বাংলায় আঁকা। আর
     * বাংলা বার্তাগুলোর কয়েকটায় **উদ্ধৃতি চিহ্ন** আছে — যেমন
     * *মাল ঢোকে "বসানো হয়নি" অবস্থায়*। ⚠️ Blade ওটাকে `&quot;` করে
     * ছাপে, অথচ `__()` কাঁচা `"` ফেরত দেয় — তাই কাঁচা তুলনা কখনো
     * মিলত না, যদিও লেখাটা পর্দাতেই আছে।
     *
     * ⓘ এস্কেপ চালু রাখলে Laravel নিজেই `e()` করে তুলনা করে, আর
     * দুইটা এক ভাষায় দাঁড়ায়।
     */
    private function assertScreenSays(TestResponse $screen, string $key): void
    {
        $screen->assertSee(__($key));
    }

    /**
     * গুদামের `<select>`-টুকু — পুরো পাতাটা নয়।
     *
     * ── কেন এই কাটাকাটি ─────────────────────────────────────────────
     * ⛔ প্রথম খসড়ায় পুরো পাতায় `/<option value="\d+"\s+selected/`
     * খোঁজা হত, আর সেটা **বাকির মেয়াদের** ঘরটাকে ধরে ফেলত — ওখানে
     * ডিফল্ট ৩ দিন সত্যিই বাছা থাকে, আর থাকাটাই ঠিক।
     *
     * ⚠️ অর্থাৎ পাহারাটা লাল দিত এমন একটা জিনিসের জন্য যা মোটেও ভুল
     * নয়। ⓘ প্রশ্নটা সরু — **গুদামের ঘরে** কিছু বাছা আছে কি না — তাই
     * খোঁজাটাও সরু হওয়া দরকার।
     */
    private function warehouseBox(TestResponse $screen): string
    {
        $html = $screen->getContent();

        $from = mb_strpos($html, 'name="warehouse_id"');
        $this->assertNotFalse($from, 'গুদামের ঘরটাই পর্দায় নেই।');

        $to = mb_strpos($html, '</select>', $from);

        return mb_substr($html, $from, $to - $from);
    }

    /**
     * ছবির চারটা অংশ, আর প্রতিটার শিরোনাম।
     */
    public function test_the_four_regions_of_the_picture_are_all_drawn(): void
    {
        $screen = $this->screen();

        foreach ([
            'purchase::field.search_item',      // বাঁ কার্ড ২
            'purchase::field.billing_date',     // বাঁ কার্ড ১, সারি ১
            'purchase::field.their_invoice',
            'purchase::field.payment_terms',
            'purchase::field.received_on',      // বাঁ কার্ড ১, সারি ২
            'purchase::field.pur_inv_no',
            'purchase::field.this_line',        // মাঝের ছক
            'purchase::field.bill_total',       // ডান কার্ডের মাথা
            'purchase::field.total_due',
        ] as $key) {
            $this->assertScreenSays($screen, $key);
        }
    }

    /**
     * ⭐ ছয়টা ঘর, আর **ঠিক যে ক্রমে** মালিক চেয়েছেন।
     *
     * ```
     * যেদিন মাল এল · ক্রয় চালান নম্বর       · গুদাম
     * বিলের তারিখ  · সরবরাহকারীর বিল নম্বর · বাকির মেয়াদ
     * ```
     *
     * ⓘ ৫ সেপ্টেম্বর তিনি ক্রমটা একবার বদলেছেন — `Received on` উপরে
     * এসেছে। ⚠️ পরীক্ষাটাও তখনই বদলেছে, নাহলে ওটা **পুরনো নকশা**
     * পাহারা দিত আর নতুনটাকে ভুল বলত।
     *
     * ⚠️ শুধু "ঘরগুলো আছে" মাপলে যথেষ্ট হত না — ক্রমটাই তাঁর নির্দেশ
     * ছিল, আর কেউ একটা ঘর সরিয়ে দিলে পর্দা ভাঙত না, কেবল নকশাটা
     * নীরবে বদলে যেত। ⓘ তাই HTML-এ ওদের **অবস্থান** মেলানো হয়।
     */
    public function test_the_six_boxes_stand_in_the_order_he_gave(): void
    {
        $html = $this->screen()->getContent();

        $at = [];

        foreach ([
            // সারি ১
            'purchase::field.received_on',
            'purchase::field.pur_inv_no',
            'inventory::field.warehouse',
            // সারি ২
            'purchase::field.billing_date',
            'purchase::field.their_invoice',
            'purchase::field.payment_terms',
        ] as $key) {
            /*
             * ⚠️ লেবেলের `title=` ধরে খোঁজা, কেবল লেখাটা ধরে নয়।
             *
             * ⛔ *"Warehouse"* শব্দটা পাতার আরও জায়গায় আছে (বাঁ মেনু,
             * গুদামের নামের ভিতরে — "Main-Warehouse")। ⓘ `mb_strpos`
             * প্রথম দেখাটাই ধরত, তাই গুদামের ঘরটা **উপরে উঠে গেছে**
             * বলে ভুল ধরা পড়ত, যদিও ক্রম ঠিকই ছিল।
             *
             * ⭐ ছয়টা লেবেলের প্রতিটাতে `title=` বসানো আছে, আর ওটা
             * কেবল ওখানেই — তাই নোঙরটা অনন্য।
             */
            $where = mb_strpos($html, 'title="'.e(__($key)).'"');

            $this->assertNotFalse($where, "ঘরটা পর্দায় নেই — {$key}");

            $at[$key] = $where;
        }

        $sorted = $at;
        asort($sorted);

        $this->assertSame(array_keys($at), array_keys($sorted),
            'ঘরগুলোর ক্রম মালিকের দেওয়া ক্রমের সাথে মেলে না।');
    }

    /**
     * ⭐ সাতটা বিকল্পই একটাই ঘরে — দ্বিতীয় বাক্স নয়।
     *
     * ── মালিকের নির্দেশ, আর কেন এটা পাহারা দেওয়া দরকার ──────────────
     * *"date ditei di box holo, eta hote parbe na, ek box ei somadhan
     * korbe"* — তারিখ বাছলে দ্বিতীয় একটা বাক্স গজাত, আর তখন পরের ঘরটা
     * **নিচের সারিতে ছিটকে যেত**।
     *
     * ⚠️ চেহারা কোনো টেস্ট মাপতে পারে না, কিন্তু **ঘরের সংখ্যা** মাপা
     * যায়: কাগজের মাথায় ঠিক ছয়টা `<label>`, একটাও বেশি নয়। ⓘ কেউ
     * সপ্তম একটা বসালে সারিটা ভাঙবে, আর এই পরীক্ষাটাই একমাত্র খবর।
     */
    public function test_every_term_lives_in_one_box(): void
    {
        $screen = $this->screen();

        foreach (['cash', 'cod', 'month_end', 'fixed'] as $kind) {
            $this->assertScreenSays($screen, "purchase::field.term_{$kind}");
        }

        /*
         * ⓘ দিনসংখ্যার বিকল্পগুলো সারি থেকে আসে, তাই নাম ধরে মাপা যায়
         * না — কিন্তু ডিফল্ট ৩ দিনেরটা থাকতেই হবে, নাহলে পর্দা খুললে
         * ঘরটা খালি খুলত।
         */
        $screen->assertSee(__('purchase::field.term_credit', ['count' => 3]));

        // ⛔ মালিক বাদ দিয়েছেন — একটা পাওনার একটাই দেয় তারিখ
        $screen->assertDontSee('Date Range', false);
    }

    /**
     * ⭐ "এই লাইনে" ছকের সারিগুলো — মালিকের দ্বিতীয় ছবির গড়ন।
     *
     * ⚠️ ছাড় ও ভ্যাট ছকের **ভিতরে**, বাইরে নয় — দুইটা ছবির সবচেয়ে বড়
     * পার্থক্য ছিল এটাই, আর সিদ্ধান্তটা ডকে কারণসহ লেখা।
     */
    public function test_the_line_box_carries_every_row_he_drew(): void
    {
        $screen = $this->screen();

        foreach ([
            'purchase::field.total_amount',
            'purchase::field.discount_on_line',
            'purchase::field.vat_mode',
            'purchase::field.net_value',
            'purchase::field.in_cart',
            'purchase::field.running_total',
        ] as $key) {
            $this->assertScreenSays($screen, $key);
        }

        // ভ্যাটের তিনটা ধরনই বাছা যায়
        foreach (['product', 'amount', 'none'] as $mode) {
            $this->assertScreenSays($screen, "purchase::field.vat_mode_{$mode}");
        }
    }

    /**
     * চারটা বোতাম লাইনের ছকে, ছয়টা মোটের কার্ডে।
     *
     * ⛔ এর একটাও অনুমান নয় — প্রতিটার নাম মালিকের স্ক্রিনশটে আছে।
     */
    public function test_every_button_he_drew_is_on_the_screen(): void
    {
        $screen = $this->screen();

        foreach ([
            /*
             * ⓘ লাইনের ছকে তিনটা — `Costing` মালিক ৫ সেপ্টেম্বর তুলে
             * দিতে বলেছেন (*"ekhane lagbe na"*), তাই এখানেও নেই।
             */
            'purchase::action.gift_short',
            'purchase::action.add_to_cart',
            'purchase::action.clear_data',
            // মোটের কার্ডে
            'purchase::action.add_deposit_panel',
            'purchase::action.add_note',
            'purchase::action.rate_chart',
            'purchase::action.transportation',
            'purchase::action.shipment',
            'purchase::action.clear_all',
            // আর সবার নিচে
            'purchase::action.receive_goods',
        ] as $key) {
            $this->assertScreenSays($screen, $key);
        }
    }

    /**
     * ⚠️ কথাটা আছে, কিন্তু **স্থায়ী নোটিশ হিসেবে নয়**।
     *
     * ── কেন পরীক্ষাটা বদলাল (৫ সেপ্টেম্বর ২০২৬) ─────────────────────
     * আগে এটা মাপত যে বাক্যটা পর্দায় **লেখা** আছে, আর ওটা পাশ করত
     * কারণ গুদামের ঘরের নিচে একটা নীল নোটিশ সবসময় বসে থাকত।
     *
     * ⛔ মালিক ঠিকই প্রশ্ন তুলেছেন: *"এটার জন্য Notice board আছে কেন?"*
     * ⚠️ যে লেখা রোজ ওঠে তা কেউ পড়ে না, আর তখন একই জায়গার আসল
     * সতর্কতাগুলোও পড়া বন্ধ হয়ে যায়।
     *
     * ⭐ এখন কথাটা দুই জায়গায়, দুইটাই শর্তসাপেক্ষ: গুদামের ঘরের
     * `title`-এ (যিনি জানতে চান তিনি পান), আর সংরক্ষণের বার্তায়
     * (যেখানে ওটা সত্যিই খবর)।
     *
     * ⓘ তাই পরীক্ষাটা এখন উল্টো দিক থেকে মাপে: লেখাটা **পাতার দৃশ্যমান
     * অংশে নেই**, অথচ `title`-এ **আছে**।
     */
    public function test_the_placement_note_is_not_a_standing_notice(): void
    {
        $html = $this->screen()->getContent();
        $note = e(__('purchase::message.goods_wait_for_placement'));

        $this->assertStringContainsString('title="'.$note.'"', $html,
            'কথাটা গুদামের ঘরের গায়েও নেই — তাহলে হারিয়ে গেছে।');

        /*
         * ⚠️ `title="…"` বাদ দিয়ে বাকি পাতায় লেখাটা থাকা চলবে না।
         * ⓘ নাহলে কেউ নোটিশটা ফিরিয়ে আনলে পরীক্ষাটা চুপ করে থাকত।
         */
        $visible = str_replace('title="'.$note.'"', '', $html);

        /*
         * ⓘ `assertStringNotContainsString()` নয়, `assertFalse()` — কারণ
         * প্রথমটা ব্যর্থ হলে **পুরো পাতাটা** ব্যর্থতার বার্তায় ঢেলে দেয়।
         *
         * ⛔ ইচ্ছে করে ভেঙে দেখতে গিয়ে ২৯০KB HTML বেরিয়েছিল, আর তার
         * ভিতরে আসল বাক্যটা খুঁজে পাওয়া যায় না। ⚠️ যে লাল পড়া যায় না,
         * সেটা পরের মানুষটার কাছে কেবল দেরি।
         */
        $this->assertFalse(str_contains($visible, $note),
            'কথাটা আবার স্থায়ী নোটিশ হয়ে ফিরেছে — `title` ছাড়াও পাতায় লেখা আছে।');
    }

    /**
     * ⭐ গুদামের ঘরটা **ভরা অবস্থায়** খোলে — মালিকের নির্দেশ।
     *
     * *"warehouse by defolt purches e boslo"*
     */
    public function test_the_default_warehouse_is_already_chosen(): void
    {
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();

        /*
         * ⚠️ `value="৩" selected` খুঁজে লাভ নেই — Blade-এর `@selected()`
         * পরের লাইনে বসে, তাই দুইটার মাঝখানে newline আর স্পেস থাকে।
         * ⓘ তাই regex, আর ফাঁকটা `\s+` ধরে।
         */
        $this->assertMatchesRegularExpression(
            '/<option value="'.$default->id.'"\s+selected/',
            $this->warehouseBox($this->screen()),
            'ডিফল্ট গুদামটা আগে থেকে বাছা নেই।',
        );
    }

    /**
     * ⛔ ডিফল্ট না থাকলে **নীরবে প্রথমটা নেওয়া হয় না**।
     *
     * ── কেন এই পরীক্ষাটা লেখা হলো ───────────────────────────────────
     * আগে এখানে `orderBy('code')->first()` ছিল, অর্থাৎ ডিফল্ট বসানো না
     * থাকলে পর্দা চুপচাপ প্রথম গুদামটা বেছে নিত। ⚠️ তার দাম: মাল ভুল
     * গুদামে বসত, কোনো ত্রুটি ছাড়াই, আর ধরা পড়ত মাস শেষে মজুদ মেলানোর
     * সময় — যখন আর বলা যায় না কোন চালানটা ভুল ছিল।
     */
    public function test_with_no_default_warehouse_the_box_stays_empty_and_says_why(): void
    {
        Warehouse::query()->update(['is_default' => false]);

        $screen = $this->screen();

        $this->assertScreenSays($screen, 'purchase::message.no_default_warehouse');

        /*
         * ⛔ আগে এখানে `assertDontSee('selected')` ছিল, আর ওটা **সবসময়
         * লাল দিত** — কারণ `selected` শব্দটা পাতার CSS ক্লাসেও আছে
         * (`bg-(--color-surface-selected)`)। ⚠️ পাহারাটা তখন কিছুই মাপত
         * না, কেবল অভিযোগ করত।
         *
         * ⭐ আসল প্রশ্নটা সরু: গুদামের `<option>`গুলোর একটাও কি বাছা
         * অবস্থায় আছে? ⓘ তাই খোঁজাটা `<option …selected` ধরে।
         */
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="\d+"\s+selected/',
            $this->warehouseBox($screen),
            'ডিফল্ট নেই, তবু একটা গুদাম বাছা অবস্থায় আছে।',
        );
    }

    /**
     * ⚠️ ইচ্ছে করে ভেঙে দেখা — গার্ডটা অন্ধ নয়।
     *
     * ⓘ এই রিপোর নিয়ম: *"গার্ড লিখলে ইচ্ছে করে ভেঙে দেখুন"*। উপরের
     * পরীক্ষাগুলো লেখার পর প্রতিটা লেবেল একবার করে ব্লেড থেকে সরিয়ে
     * লাল হতে দেখা হয়েছে। ⭐ এখানে সেটার একটা স্থায়ী রূপ: পর্দাটা এমন
     * একটা শব্দ **দেখায় না** যেটা কখনো লেখা হয়নি।
     *
     * ⛔ এটা না থাকলে `assertSee` দিয়ে বানানো পরীক্ষাগুলোর কোনো নিচের
     * সীমা থাকত না — একটা খালি পাতাও সব `assertSee` পাশ করাতে পারত
     * যদি অনুবাদের চাবিগুলো নিজেরাই খালি হত।
     */
    public function test_the_words_are_real_and_not_empty_keys(): void
    {
        foreach ([
            'purchase::field.this_line',
            'purchase::action.shipment',
            'purchase::message.goods_wait_for_placement',
        ] as $key) {
            $this->assertNotSame($key, __($key), "অনুবাদের চাবিটা খালি — {$key}");
            $this->assertNotSame('', trim(__($key)));
        }

        $this->screen()->assertDontSee('purchase::field.', false);
        $this->screen()->assertDontSee('purchase::action.', false);
    }
}
