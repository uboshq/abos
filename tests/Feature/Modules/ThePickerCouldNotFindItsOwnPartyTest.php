<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পর্দা নিজের পক্ষকেই খুঁজে পেত না।
 *
 * ── ⛔ কী ভাঙা ছিল, ৭ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * লাইভে হাতে চালিয়ে ধরা পড়েছে। সরাসরি ক্রয়ের পর্দায় সরবরাহকারী খুঁজতে
 * `Bengal` লেখা হলো — `Bengal Foods Ltd`, নামটা আমি নিজেই বসিয়েছিলাম।
 * পর্দা বলল:
 *
 *     ⛔ "ওই নামে কোনো সরবরাহকারী নেই।"
 *
 * ⓘ অথচ `বেঙ্গল` লিখলে সে আসে। কারণটা পর্দায় নয়, **যা পাঠানো হয় তাতে**:
 *
 *     'name' => $s->name()      // চলতি ভাষার নামটা, একটাই
 *
 * ⚠️ বাংলা লোকেলে ইংরেজি নামটা **ব্রাউজারে পৌঁছাতই না**। ছাঁকনি যা পায়নি
 * তা খুঁজবে কী করে?
 *
 * ── কেন এটা শুধু অসুবিধা নয় ────────────────────────────────────────
 * ⛔ বার্তাটা **মিথ্যা**: সরবরাহকারী আছে, পর্দা বলছে নেই। ⓘ কাউন্টারে
 * দাঁড়ানো মানুষটার কাছে দুইটা পথ থাকে — একই পক্ষ দ্বিতীয়বার বসানো
 * (তখন বকেয়া দুই সারিতে ভাগ হয়ে যায়, আর কোনটা সত্যি তা কেউ বলতে পারে
 * না), নয়তো কাজটা থামিয়ে দেওয়া।
 *
 * ⚠️ আর এটা ধরা পড়ে **কেবল দুই ভাষায় নাম বসানো থাকলে** — অর্থাৎ ঠিক সেই
 * গ্রাহকদের বেলায় যাঁদের যত্ন করে বসানো হয়েছে।
 *
 * ── ⭐ এই ফাইলটা যা পাহারা দেয় ──────────────────────────────────────
 * সারাইটা তিন জায়গায় (সরবরাহকারী · গ্রাহক · পণ্য), আর তিনটাই একই আকার।
 * ⓘ চতুর্থ পিকারটা কেউ লিখবেন, আর একই ভুলটা আবার করবেন — কারণ
 * `$x->name()` ডাকাটাই স্বাভাবিক মনে হয়।
 *
 * ⛔ তাই দাবিটা পর্দার আচরণে নয়, **যা পাঠানো হয় তাতে**: দুইটা নামই
 * ব্রাউজারে পৌঁছাচ্ছে কি না।
 */
class ThePickerCouldNotFindItsOwnPartyTest extends TestCase
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

        /*
         * ⚠️ ভাষাটা বাংলা — আর এটাই শর্ত।
         *
         * ⓘ ইংরেজি লোকেলে `name()` ইংরেজি নামটাই ফেরত দিত, আর ভুলটা
         * লুকিয়ে থাকত। ⛔ বাগটা কেবল তখনই দেখা যায় যখন দেখানো নাম আর
         * খোঁজা নাম আলাদা ভাষার।
         */
        app()->setLocale('bn');
    }

    /**
     * ⓘ ভিত্তিটা সত্যি কি না — দুইটা নাম সত্যিই আলাদা তো?
     *
     * ⚠️ সিডারের সব সারিতে দুই ভাষার নাম না থাকলে নিচের দাবিগুলো সবুজ
     * থাকত অথচ কিছুই মাপত না।
     */
    public function test_the_rows_really_carry_two_different_names(): void
    {
        foreach ([Supplier::class, Customer::class, Product::class] as $model) {
            $row = $model::query()->whereNotNull('name_bn')->where('name_bn', '!=', '')->first();

            $this->assertNotNull($row, class_basename($model).'-এর একটা সারিতেও বাংলা নাম নেই।');
            $this->assertNotSame(
                strtolower((string) $row->name_en),
                strtolower((string) $row->name_bn),
                class_basename($model).'-এর দুইটা নাম এক — তাহলে এই পরীক্ষা কিছুই আলাদা করতে পারে না।',
            );
        }
    }

    /** ⛔ ক্রয়ের পর্দা সরবরাহকারীর **দুইটা নামই** পাঠায়। */
    public function test_the_purchase_screen_sends_both_supplier_names(): void
    {
        $s = Supplier::query()->whereNotNull('name_bn')->where('name_bn', '!=', '')->firstOrFail();

        $html = $this->get(route('purchase.direct.create'))->assertOk()->getContent();

        $this->assertStringContainsString(
            $s->name_en,
            $html,
            "সরবরাহকারীর ইংরেজি নামটা পর্দায় পৌঁছায়নি।\n"
            ."ফলে ইংরেজিতে টাইপ করে তাঁকে খুঁজে পাওয়া যাবে না, আর পর্দা বলবে\n"
            .'"ওই নামে কোনো সরবরাহকারী নেই" — যেটা মিথ্যা।',
        );

        $this->assertStringContainsString($s->name_bn, $html,
            'সরবরাহকারীর বাংলা নামটাই পর্দায় নেই।');
    }

    /** ⛔ বিক্রয়ের পর্দা গ্রাহকের দুইটা নামই পাঠায়। */
    public function test_the_sales_screen_sends_both_customer_names(): void
    {
        $c = Customer::query()->whereNotNull('name_bn')->where('name_bn', '!=', '')->firstOrFail();

        $html = $this->get(route('sales.direct.create'))->assertOk()->getContent();

        $this->assertStringContainsString($c->name_en, $html,
            "গ্রাহকের ইংরেজি নামটা পর্দায় পৌঁছায়নি — ইংরেজিতে খুঁজলে তিনি \"নেই\" হয়ে যাবেন।\n"
            .'আর তখন কেউ একই গ্রাহক দ্বিতীয়বার বসাবেন, আর বকেয়া দুই সারিতে ভাগ হবে।');

        $this->assertStringContainsString($c->name_bn, $html,
            'গ্রাহকের বাংলা নামটাই পর্দায় নেই।');
    }

    /**
     * ⛔ আর পণ্যেরও — **দুই পর্দাতেই**, একই ছাঁচ, একই ক্ষতি।
     *
     * ── ⚠️ কেন দুইটা পর্দাই আলাদা করে মাপা ─────────────────────────────
     * প্রথম খসড়ায় কেবল বিক্রয়ের পর্দাটা মাপা হয়েছিল, আর পাহারাটা সবুজ
     * ছিল। ⛔ তারপর হাতে চালাতে গিয়ে দেখা গেল **ক্রয়ের পণ্যের ছাঁকনিতে
     * ভুলটা রয়ে গেছে** — একই দিনে, একই ফাইলের কাজে, চতুর্থ জায়গা।
     *
     * ⓘ দুইটা কাউন্টার আয়না হলেও তাদের সারি দুই জায়গায় তৈরি হয়:
     * বিক্রয়েরটা [[DirectSaleController]]-এ, ক্রয়েরটা
     * [[DirectPurchaseService::stockPanel()]]-এ। ⚠️ একটা মেপে অন্যটা ধরে
     * নেওয়া যায় না — আর ঠিক সেটাই একবার করেছিলাম।
     */
    public function test_both_counters_send_both_product_names(): void
    {
        $p = Product::query()->whereNotNull('name_bn')->where('name_bn', '!=', '')->firstOrFail();

        foreach (['sales.direct.create' => 'বিক্রয়', 'purchase.direct.create' => 'ক্রয়'] as $route => $screen) {
            $html = $this->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString($p->name_en, $html,
                "{$screen}ের পর্দায় পণ্যের ইংরেজি নামটা পৌঁছায়নি — বারকোড না থাকলে "
                .'ওটাই খুঁজে পাওয়ার একমাত্র উপায় ছিল।');

            $this->assertStringContainsString($p->name_bn, $html,
                "{$screen}ের পর্দায় পণ্যের বাংলা নামটাই নেই।");
        }
    }

    /**
     * ⭐ আর ছাঁকনিটাও দুইটা নামই দেখে।
     *
     * ⓘ উপরের দাবিগুলো বলে **নামটা পৌঁছেছে**; এটা বলে **সেটা খোঁজা হয়**।
     * ⚠️ দুইটা আলাদা ভুল: একবার পাঠানো হয়নি, আরেকবার পাঠানো হয়েছে কিন্তু
     * ছাঁকনিতে যোগ করা হয়নি — আর দ্বিতীয়টা আরও নীরব, কারণ ডেটা তো
     * পর্দাতেই আছে।
     */
    public function test_the_filters_actually_look_at_both_names(): void
    {
        foreach ([
            'purchase.direct.create' => 'ক্রয়ের সরবরাহকারী ও পণ্য',
            'sales.direct.create' => 'বিক্রয়ের গ্রাহক ও পণ্য',
        ] as $route => $what) {
            $html = $this->get(route($route))->assertOk()->getContent();

            /*
             * ⓘ চলকের নাম পর্দাভেদে আলাদা (`x` · `c` · `p`), তাই তিনটাকেই
             * এক করে নিয়ে গোনা হয় — দাবিটা নামের নয়, **আচরণের**।
             *
             * ⚠️ আর গোনা হয় **দুইবার**: প্রতিটা পর্দায় দুইটা করে ছাঁকনি
             * আছে (পক্ষ ও পণ্য)। ⛔ একটা গুনলে অন্যটা বাদ পড়ত — আর ঠিক
             * সেটাই ৭ সেপ্টেম্বরে ঘটেছিল, ক্রয়ের পণ্যের ছাঁকনিতে।
             */
            $normalised = str_replace(['(c.', '(p.', '(s.'], '(x.', $html);

            foreach (['name_en', 'name_bn'] as $field) {
                $this->assertGreaterThanOrEqual(
                    2,
                    substr_count($normalised, "(x.{$field} || '').toLowerCase().includes(t)"),
                    "{$what} — দুইটা ছাঁকনির অন্তত একটায় `{$field}` দেখা হয় না।\n"
                    ."নামটা পর্দায় পৌঁছেছে, কিন্তু খোঁজা হচ্ছে না — ফল একই, আর বোঝা আরও কঠিন।\n"
                    .'⚠️ পক্ষের ঘরটা ঠিক করে পণ্যের ঘরটা ভুলে যাওয়া সবচেয়ে সহজ ভুল।',
                );
            }
        }
    }
}
