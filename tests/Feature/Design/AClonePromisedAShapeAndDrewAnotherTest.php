<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\Ui;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * একটা নকল আকৃতির প্রতিশ্রুতি দিয়ে আঁকল অন্যটা।
 *
 * ── কী পাহারা দেওয়া হচ্ছে ────────────────────────────────────────────
 * ABOS-এর দশটা রূপের ছয়টার **নিজস্ব markup** আছে — ওডুর লঞ্চার শিট,
 * D365-এর ওয়াফল, ফিওরির শেল বার, রেডউডের ইটরঙা পটি। ওগুলো CSS নয়;
 * টোকেন দিয়ে ও-জিনিস আনা যায় না।
 *
 * আর ঠিক সেখানেই ফাঁদ। রূপের **বিন্যাসটা ঘোষিত হয় একটা শব্দে** —
 * `Ui::all()`-এ `'nav' => 'rail'` — আর টোকেন সবসময়ই উপস্থিত থাকে।
 * তাই একটা রূপ স্প্রিংবোর্ডের প্রতিশ্রুতি দিয়ে সাধারণ সাইডবার এঁকেও
 * **সব পরীক্ষায় সবুজ থাকতে পারত**: রং মিলছে, মাপ মিলছে, কেবল
 * জিনিসটাই নেই।
 *
 * ২৭ অগাস্ট ২০২৬-এ দশটা রূপ লাইভে পরে দেখা হয়েছিল, আর ছয়টাই তখন
 * ঠিক আঁকছিল। এই পাহারাটা ওই অবস্থাটা **ধরে রাখার** জন্য, ভাঙা কিছু
 * সারানোর জন্য নয়।
 *
 * ── কেন আগের পাহারাগুলো এটা ধরত না ───────────────────────────────────
 * তিনটাই ছিল, তিনটাই সৎ, তিনটাই অন্য দিকে তাকিয়ে:
 *
 *   [[TheLooksLiveInDataNotCssTest]]      — টোকেন সম্পূর্ণ কি না
 *   [[EveryScreenObeysTheThemeTest]]      — পর্দা রং হাতে বসায় কি না
 *   [[ALookThatTravelsAsAFileTest]]       — রূপ ফাইলে যায়-আসে কি না
 *
 * তিনটার একটাও জিজ্ঞেস করে না **"জিনিসটা পর্দায় আঁকা হলো তো?"**
 *
 * ── কেন তালিকাটা হাতে রাখা হয়নি ──────────────────────────────────────
 * রূপের তালিকা আসে `Ui::keys()` থেকে। এগারোতম রূপ যোগ করলে তার
 * স্বাক্ষর ঘোষণা না করা পর্যন্ত এই পাহারা লাল থাকে — অর্থাৎ নতুন রূপ
 * চুপচাপ অরক্ষিত অবস্থায় ঢুকতে পারে না।
 *
 * পাশের `EveryScreenObeysTheThemeTest`-এ ঠিক এই ভুলটাই আছে: সেখানে
 * তালিকাটা হাতে লেখা আটটা, আর `linear` ও `salesforce` — দুইটা নতুন
 * রূপ — ওই তালিকার বাইরে থেকে গেছে। সেটাও এই কাজেই ঠিক করা হয়েছে।
 */
class AClonePromisedAShapeAndDrewAnotherTest extends TestCase
{
    use RefreshDatabase;

    /**
     * প্রতিটা রূপের স্বাক্ষর — যে markup ছাড়া নকলটা আর চেনা যায় না।
     *
     * ── কী এখানে রাখা হয়, আর কী নয় ──────────────────────────────────
     * কেবল সেটুকু যা **ওই রূপের নিজের**, আর অন্য রূপে যার কোনো
     * অস্তিত্ব নেই। রং, ঘনত্ব, ধার — কিছুই নয়; ওগুলো টোকেনের কাজ আর
     * ওদের নিজের পাহারা আছে।
     *
     * ── খালি তালিকার মানে "পাহারা নেই" নয় ───────────────────────────
     * `navy` আর `rose` ABOS-এর **নিজের** পরিচয় — নকল করার মতো কোনো
     * আসল পণ্য নেই, তাই স্বাক্ষরের markup-ও নেই। ওদের জন্য নিচের
     * কঙ্কালের পরীক্ষাটাই যথেষ্ট, আর সেটা সব রূপের উপরেই চলে।
     *
     * @var array<string, list<string>>
     */
    private const SIGNATURE = [
        // Microsoft Dynamics 365 — ওয়াফল, আর সাইট-ম্যাপের নিচে এলাকা বদল
        'dynamic' => ['data-waffle', 'data-area-switch'],

        // Odoo — বেগুনি অ্যাপ-বার, আর ড্রপডাউন নয় বরং পুরো-পর্দা শিট
        'apps' => ['data-app-launcher', 'data-launcher-sheet'],

        // Salesforce Lightning — অ্যাপ-স্ট্রিপ, লঞ্চার গ্রিড, নিচে utility bar
        'salesforce' => ['data-sf-launcher', 'data-sf-apptabs', 'data-sf-utilitybar'],

        // SAP Fiori 3 — শেল বার, শিরোনামের নিচে উপশিরোনাম
        'tiles' => ['data-shell-bar'],

        // Oracle Redwood — ৪px ইটরঙা পটি, আর টালির স্প্রিংবোর্ড
        'redwood' => ['data-brand-strip', 'data-springboard'],

        // Oracle NetSuite — নেভি হেডারের লোগো, আর কার্ডের বাইরে পাতার শিরোনাম
        'suite' => ['data-suite-header', 'data-suite-title'],

        /*
         * Linear — পরিচয়টা **অনুপস্থিতি**, তাই আসল পাহারাটা নিচের
         * কঙ্কালের পরীক্ষায়: `quiet` মানে টপবার নেই।
         *
         * তবু এখানে একটা চিহ্ন আছে, আর সেটাই এই রূপের সবচেয়ে জরুরি
         * শর্ত: বারটা সরানোর পর ভাষা-চেহারা-প্রোফাইল যেন **কোথাও গিয়ে
         * বসে**। ওগুলো না বসলে রূপটা দেখতে ঠিকই Linear, কিন্তু ওই রূপ
         * বেছে নেওয়া মানুষ আর সাইন আউট করতে পারেন না।
         */
        'linear' => ['data-quiet-foot'],

        // উপরে মেনু বার, বাঁয়ে কিছু নেই — কঙ্কালেই ধরা পড়ে
        'classic' => [],

        // ABOS-এর নিজের দুইটা — নকল করার মতো আসল কিছু নেই
        'navy' => [],
        'rose' => [],
    ];

    /**
     * প্রতিটা কঙ্কাল পর্দায় কী রেখে যায়।
     *
     * ── কেন এটা স্বাক্ষরের চেয়েও জরুরি ───────────────────────────────
     * স্বাক্ষর বলে "টুকরোটা আছে"; কঙ্কাল বলে **"হাড়টা ঠিক আছে"**।
     * `dynamic`-এর ওয়াফল টপবারে বসে আর তার এলাকা-বদল সাইডবারের
     * পায়ে — কেউ যদি `'nav'` বদলে `'top'` করে দেয়, সাইডবারটাই আর
     * আঁকা হয় না, তাই `rail-foot` অঞ্চলটাও কেউ ডাকে না, আর
     * `data-area-switch` **নিঃশব্দে** হারিয়ে যায়।
     *
     * ওই ভুলটা ধরতে হলে ঘোষিত কঙ্কালটাও মিলিয়ে দেখতে হয়।
     *
     * @var array<string, array{sidebar: bool, topbar: bool, narrowbar: bool, topnav: bool}>
     */
    private const SKELETON = [
        'rail' => ['sidebar' => true,  'topbar' => true,  'narrowbar' => false, 'topnav' => false],
        'top' => ['sidebar' => false, 'topbar' => true,  'narrowbar' => false, 'topnav' => true],

        /*
         * `quiet` — Linear-এর কঙ্কাল, আর একমাত্র যেটার পরিচয় কিছু
         * **না থাকা**।
         *
         * বাঁয়ে তালিকা থাকে, চওড়া পর্দায় উপরে কোনো বার থাকে না; পাতা
         * একদম উপর থেকে শুরু হয়। টপবারের নিয়ন্ত্রণগুলো (ভাষা, চেহারা,
         * প্রোফাইল) হারায় না — সাইডবারের পায়ে নেমে আসে, ঠিক যেমন
         * Linear-এ হয়।
         *
         * ── `narrowbar` কেন **সত্যি** থাকতেই হবে ─────────────────────
         * সাইডবার `lg:flex`, bottom-nav `md:hidden` — মাঝের ৭৬৮ থেকে
         * ১০২৩px-এ দুইটার একটাও নেই। বারটা একেবারে তুলে নিলে ওই
         * চওড়ায় **কোনো নেভিগেশনই** থাকত না, আর সেটা প্রথম চেষ্টায়
         * সত্যিই ঘটেছিল: ১৪৪০px-এ সব ঠিক দেখাচ্ছিল, ৭৬৮px-এ পর্দাটা
         * বন্ধ ঘর হয়ে গিয়েছিল।
         *
         * তাই এখানে `false` লেখা যাবে না। নকল সম্পূর্ণ করতে গিয়ে
         * পর্দা থেকে বেরোনোর পথ কেড়ে নেওয়া নকল করা নয়, ভাঙা।
         */
        'quiet' => ['sidebar' => true,  'topbar' => false, 'narrowbar' => true,  'topnav' => false],
    ];

    /**
     * কোন চিহ্ন দেখে বোঝা যায় শেলের কোন অংশটা আঁকা হয়েছে।
     *
     * `topbar` আর `narrowbar` একই কম্পোনেন্ট, আলাদা মান — একটা সব
     * প্রস্থে, অন্যটা `lg:hidden`। মান ছাড়া কেবল `data-topbar` খুঁজলে
     * পাহারাটা দুইটাকে এক ভাবত।
     */
    private const PART = [
        'sidebar' => 'data-site-map',
        'topbar' => 'data-topbar="full"',
        'narrowbar' => 'data-topbar="narrow"',
        'topnav' => 'data-nav-item',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * প্রতিটা রূপের স্বাক্ষর ঘোষণা করা আছে।
     *
     * এটা আগে চলে, কারণ ঘোষণা না থাকলে নিচের পরীক্ষাগুলো ওই রূপটাকে
     * চুপচাপ এড়িয়ে যেত — আর অরক্ষিত রূপই ঠিক যেটা ধরা দরকার।
     */
    public function test_every_look_declares_what_makes_it_recognisable(): void
    {
        $undeclared = array_diff(Ui::keys(), array_keys(self::SIGNATURE));
        $stale = array_diff(array_keys(self::SIGNATURE), Ui::keys());

        $this->assertSame([], array_values($undeclared), implode("\n", [
            'এই রূপগুলোর স্বাক্ষর ঘোষণা করা নেই:',
            ...$undeclared,
            '',
            'SIGNATURE-এ সারি যোগ করুন। নকল করার মতো আসল পণ্য না থাকলে',
            'খালি তালিকা দিন — তখন কঙ্কালের পরীক্ষাটাই ওর পাহারা।',
        ]));

        $this->assertSame([], array_values($stale), implode("\n", [
            'এই রূপগুলো আর নেই, অথচ SIGNATURE-এ রয়ে গেছে:',
            ...$stale,
        ]));
    }

    /**
     * প্রতিটা রূপ তার স্বাক্ষরটা সত্যিই আঁকে।
     */
    public function test_every_clone_actually_draws_its_signature(): void
    {
        $missing = [];

        foreach (self::SIGNATURE as $look => $marks) {
            if ($marks === []) {
                continue;
            }

            $body = $this->shellFor($look);

            foreach ($marks as $mark) {
                if (! str_contains($body, $mark)) {
                    $missing[] = "{$look} — {$mark} পর্দায় নেই";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই রূপগুলো তাদের নিজের markup আঁকছে না:',
            ...$missing,
            '',
            'রং ঠিক থাকায় পর্দাটা দেখতে ভুল লাগবে না — কিন্তু নকলটা',
            'আর চেনা যাবে না। দেখুন chrome/<রূপ>.blade.php-এর অঞ্চলটা',
            'শেলের কোথাও সত্যিই ডাকা হয় কি না।',
        ]));
    }

    /**
     * প্রতিটা রূপের ঘোষিত কঙ্কালটাই আঁকা হয়।
     */
    public function test_the_declared_skeleton_is_the_one_drawn(): void
    {
        $wrong = [];

        foreach (Ui::keys() as $look) {
            $nav = Ui::nav($look);

            $this->assertArrayHasKey($nav, self::SKELETON, implode("\n", [
                "রূপ {$look} ঘোষণা করেছে nav = '{$nav}', কিন্তু ওই কঙ্কালটা",
                'SKELETON-এ বর্ণনা করা নেই — তাই কেউ জানে না ওতে পর্দায়',
                'কী থাকার কথা।',
            ]));

            $body = $this->shellFor($look);

            /*
             * উপরের পটিটা কঙ্কালের ধ্রুবক অংশ নয়, ২৮ আগস্ট ২০২৬ থেকে।
             *
             * আগে `top` মানেই ছিল "পটি আছে"। Salesforce-ও `top`,
             * অথচ Lightning-এ ওই পটি নেই — ওর নিজের শেল অবজেক্ট-ট্যাব
             * আঁকে (`data-sf-apptabs`), আর দুইটা একসাথে বসত।
             *
             * তাই দাবিটা এখন রূপের নিজের ঘোষণা থেকে আসে। `none` মানে
             * পটিটা **থাকা চলবে না** — অর্থাৎ পরীক্ষাটা দুই দিকেই
             * কড়া, আগের মতোই।
             */
            $skeleton = self::SKELETON[$nav];
            $skeleton['topnav'] = Ui::topnav($look) !== 'none';

            foreach ($skeleton as $part => $expected) {
                $seen = str_contains($body, self::PART[$part]);

                if ($seen !== $expected) {
                    $wrong[] = sprintf(
                        '%s (nav=%s) — %s %s, অথচ %s',
                        $look,
                        $nav,
                        $part,
                        $seen ? 'আঁকা হয়েছে' : 'আঁকা হয়নি',
                        $expected ? 'থাকার কথা' : 'না থাকার কথা',
                    );
                }
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'ঘোষিত কঙ্কাল আর আঁকা কঙ্কাল মিলছে না:',
            ...$wrong,
            '',
            'একটা অংশ না আঁকা হলে তার ভেতরের অঞ্চলগুলোও কেউ ডাকে না —',
            'ফলে ওই রূপের স্বাক্ষরও নিঃশব্দে হারায়।',
        ]));
    }

    /**
     * প্রতিটা রূপের উপরের পটিটা **সেই পণ্যের মতো**, আমাদের সুবিধামতো নয়।
     *
     * ── কেন উপরের পরীক্ষাটা একা যথেষ্ট ছিল না ────────────────────────
     * পরের পরীক্ষাটা কেবল দেখে "যা ঘোষণা করা, তাই আঁকা"। সেটা যন্ত্রটা
     * পাহারা দেয়, **বাছাইটা নয়**। প্রমাণ: `apps`-এর ঘোষণা `sections`
     * থেকে `modules`-এ ফিরিয়ে দিয়ে চালানো হয়েছিল — পরীক্ষাটা সবুজই
     * থাকল, অথচ ওটাই ছিল ঠিক সেই ভুল যেটা নিয়ে মালিক স্ক্রিনশট
     * পাঠিয়েছেন।
     *
     * অর্থাৎ ফিরে যাওয়ার পথটা খোলা ছিল। এই তালিকাটা সেটাই বন্ধ করে:
     * এখানে দাবিটা আসল পণ্যগুলোর, আর কারণ প্রতিটার পাশে লেখা।
     *
     * তালিকাটা হাতে লেখা, কিন্তু **রূপগুলো `Ui::keys()` থেকে** — তাই
     * এগারোতম রূপ যোগ করলে সে ঘোষণা না করা পর্যন্ত লাল থাকবে।
     */
    public function test_each_clone_puts_in_the_strip_what_that_product_puts_there(): void
    {
        $expected = [
            /* Odoo — উপরে অ্যাপের নাম, নিচের পটিতে সেই অ্যাপের মেনু।
               অ্যাপ বদলায় ওয়াফল দিয়ে (`data-app-launcher`)। */
            'apps' => 'sections',

            /* NetSuite — উপরের পটিটাই কেন্দ্রগুলোর তালিকা (Activities ·
               Transactions · Lists · Reports)। ওখানে মডিউলই সঠিক। */
            'suite' => 'modules',

            /* Fiori — লঞ্চপ্যাড ছাড়া মডিউল বদলের আর কোনো পথ নেই, আর
               আমাদের `tiles`-এ লঞ্চপ্যাড নেই। পটি সরালে মানুষ আটকে
               যেতেন। */
            'tiles' => 'modules',

            /* Lightning — ওয়াফল + চলতি অ্যাপের অবজেক্ট-ট্যাব, আর ট্যাবগুলো
               ওর নিজের শেলই আঁকে (`data-sf-apptabs`)। দ্বিতীয় একটা পটি
               Lightning-এ নেই। */
            'salesforce' => 'none',

            /* ABOS-এর নিজেরটা — মেনু বাঁয়ের রেলে, উপরে পটি নেই। */
            'navy' => 'none',
            'rose' => 'none',
            'dynamic' => 'none',
            'redwood' => 'none',
            'linear' => 'none',

            /* ক্লাসিক কারও নকল নয় — টোকেনের মূল সেট, মেনু উপরে। */
            'classic' => 'modules',
        ];

        $wrong = [];

        foreach (Ui::keys() as $look) {
            $this->assertArrayHasKey($look, $expected, implode("\n", [
                "রূপ {$look}-এর উপরের পটি নিয়ে কোনো সিদ্ধান্ত লেখা নেই।",
                '',
                'ওটা কোন পণ্যের নকল, আর সেই পণ্যের উপরের পটিতে কী থাকে —',
                'সেটা ঠিক করে এই তালিকায় একটা সারি বসান।',
            ]));

            if (Ui::topnav($look) !== $expected[$look]) {
                $wrong[] = sprintf('%s — ঘোষণা %s, থাকার কথা %s',
                    $look, Ui::topnav($look), $expected[$look]);
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'একটা রূপের উপরের পটি আসল পণ্যের সাথে আর মিলছে না:',
            ...$wrong,
        ]));
    }

    /**
     * পটিতে যা বসার কথা তাই বসে — মডিউল, না চলতি মডিউলের মেনু।
     *
     * ── কেন এই পরীক্ষাটা লেখা হলো ────────────────────────────────────
     * ২৮ আগস্ট ২০২৬-এ মালিক স্ক্রিনশট পাঠিয়েছেন: উপরে বাঁয়ে লেখা
     * "হিসাব ও অর্থ", আর ঠিক নিচের পটিতে এগারোটা **মডিউল**। তাঁর কথা —
     * *"ekhane menu asar kotha modiule asteche"*।
     *
     * কোনো পরীক্ষা লাল হয়নি, কারণ কোনো পরীক্ষা কখনো জিজ্ঞেসই করেনি
     * ওই পটিতে কী থাকার কথা। রং মিলত, উচ্চতা মিলত, `data-nav-item`
     * থাকত — সব সবুজ, আর জিনিসটা ভুল।
     *
     * দাবিটা তাই বিষয়বস্তুর উপর, চিহ্নের উপর নয়: `sections` ঘোষণা করা
     * রূপে **অন্য মডিউলের নাম পটিতে থাকতে পারবে না**।
     */
    public function test_the_top_strip_carries_what_the_look_declared(): void
    {
        $wrong = [];

        foreach (Ui::keys() as $look) {
            $shape = Ui::topnav($look);

            $this->assertContains($shape, ['modules', 'sections', 'none'],
                "রূপ {$look} অচেনা topnav ঘোষণা করেছে: '{$shape}'");

            if ($shape === 'none') {
                continue;
            }

            $body = $this->shellFor($look);

            $labels = collect(app(ModuleRegistry::class)->all())
                ->map(fn ($m) => $m->label())
                ->values();

            $strip = $this->stripOf($body);

            if ($shape === 'sections') {
                $found = $labels->filter(fn (string $label) => str_contains($strip, $label));

                if ($found->isNotEmpty()) {
                    $wrong[] = "{$look} (sections) — পটিতে অন্য মডিউলের নাম: ".$found->join(', ');
                }
            }

            if ($shape === 'modules') {
                $missing = $labels->reject(fn (string $label) => str_contains($strip, $label));

                if ($missing->isNotEmpty()) {
                    $wrong[] = "{$look} (modules) — পটিতে নেই: ".$missing->join(', ');
                }
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'উপরের পটিতে ভুল জিনিস বসেছে:',
            ...$wrong,
            '',
            'Odoo-তে ওই পটি চলতি অ্যাপের মেনু, NetSuite-এ মডিউলের তালিকা।',
            'কোনটা কী, সেটা Ui::topnav()-এ ঘোষণা করা।',
        ]));
    }

    /**
     * খোলা তালিকাটা যেন স্ক্রল-পটির ভেতরে কাটা না পড়ে।
     *
     * ── কেন একটা CSS ক্লাসের উপর পরীক্ষা ─────────────────────────────
     * সাধারণত ক্লাসের নাম পরীক্ষা করা বাজে অভ্যাস — ওটা বাস্তবায়নের
     * খুঁটিনাটি। এখানে নয়: `fixed` বনাম `absolute` এখানে সাজসজ্জা নয়,
     * **কাজ করা বনাম না করা**।
     *
     * পটিতে `overflow-x-auto` আছে (এগারোটা মডিউল সরু পর্দায় ধরে না),
     * আর CSS-এ `overflow-x: auto` দিলে `overflow-y`-ও `auto` হয়ে যায়।
     * তাই `absolute` তালিকাটা ৪১px পটির ভেতরেই কাটা পড়ত — DOM-এ ৬২৮px,
     * পর্দায় শূন্য। মালিক একাধিক পিসিতে দেখেছেন: "kaj korena, skin hang
     * kore"।
     *
     * কেউ `fixed` ফিরিয়ে `absolute` করলে ঠিক ওই দিনটাই ফিরে আসবে, আর
     * রঙের কোনো পরীক্ষা সেটা ধরবে না।
     */
    public function test_the_open_list_is_not_trapped_inside_the_scrolling_strip(): void
    {
        $markup = File::get(resource_path('views/components/shell/topnav.blade.php'));

        /* মন্তব্যে `absolute` শব্দটা আছে — ব্যাখ্যায়। markup-এ নেই। */
        $markup = (string) preg_replace('/\{\{--.*?--\}\}/su', '', $markup);
        $markup = (string) preg_replace('/\/\*.*?\*\//su', '', $markup);

        $this->assertStringContainsString('overflow-x-auto', $markup,
            'পটিটা আর স্ক্রল করে না — তাহলে এই পরীক্ষার কারণটাও বদলে গেছে, আবার পড়ুন।');

        $this->assertStringNotContainsString('absolute', $markup, implode("\n", [
            'উপরের পটির তালিকা আবার `absolute` হয়েছে।',
            '',
            'পটিতে overflow-x-auto আছে, তাই overflow-y-ও auto — তালিকাটা',
            'পটির ৪১px উচ্চতায় কাটা পড়বে আর ক্লিক করলে কিছুই দেখা যাবে না।',
        ]));

        $this->assertStringContainsString('fixed z-50', $markup,
            'তালিকাটা আর fixed নয় — স্ক্রল-পটির বাইরে আঁকা না হলে ওটা কাটা পড়ে।');
    }

    /**
     * পটির অংশটুকু — ভেতরের পাতা বাদ দিয়ে।
     *
     * পুরো HTML-এ প্রতিটা মডিউলের নাম থাকেই (ওয়াফল, bottom nav,
     * সাইডবারের রেল), তাই গোটা পাতায় খুঁজলে `sections`-এর দাবিটা
     * কখনো সবুজ হত না।
     */
    private function stripOf(string $body): string
    {
        if (! preg_match('/<nav class="topnav.*?<\/nav>/su', $body, $m)) {
            return '';
        }

        return $m[0];
    }

    /**
     * ছাঁকনির পটিটা ঠিক সেই রূপেই খোলা থাকে যেটা `bar` ঘোষণা করেছে।
     *
     * ── কেন এটা আলাদা একটা পরীক্ষা ───────────────────────────────────
     * এটা কঙ্কালের কথা নয়, **আচরণের** কথা — Alpine-এর প্রাথমিক
     * অবস্থা। ঘোষণাটা `Ui::filters()`-এ আর ব্যবহারটা টুলবারে; দুইটার
     * মাঝে কোনো সংযোগ নেই যা নিজে থেকে ভাঙা ধরা পড়ে।
     *
     * কেউ টুলবারের শর্তটা সরিয়ে দিলে Fiori-র পটি চুপচাপ বন্ধ হয়ে যেত,
     * আর রং-মাপ সব ঠিক থাকায় কোনো পরীক্ষা লাল হত না। ঠিক এই ধরনের
     * নীরব ক্ষতিই এই ফাইলটার বিষয়।
     */
    public function test_only_the_look_that_asks_for_a_filter_bar_gets_one(): void
    {
        $known = ['toggle', 'bar'];
        $open = [];

        foreach (Ui::keys() as $look) {
            $kind = Ui::filters($look);

            $this->assertContains($kind, $known, implode("\n", [
                "রূপ {$look} ঘোষণা করেছে filters = '{$kind}', যেটা চেনা নয়।",
                'চেনা মান: '.implode(', ', $known),
            ]));

            $this->user->forceFill(['ui' => $look])->save();

            /*
             * পণ্যের তালিকা — কারণ এই পর্দাটার নিজের ছাঁকনি আছে।
             * ছাঁকনিহীন পর্দায় প্যানেলটাই আঁকা হয় না, তাই পরীক্ষাটা
             * সব রূপেই "বন্ধ" দেখত আর কিছুই প্রমাণ করত না।
             */
            $body = (string) $this->get(route('inventory.product.index'))
                ->assertOk()
                ->getContent();

            if (str_contains($body, 'filtersOpen: true')) {
                $open[] = $look;
            }
        }

        $expected = array_values(array_filter(
            Ui::keys(),
            static fn (string $look): bool => Ui::filters($look) === 'bar',
        ));

        $this->assertSame($expected, $open, implode("\n", [
            'ছাঁকনির পটি খোলা পাওয়া গেছে এই রূপগুলোয়: '.(implode(', ', $open) ?: 'কোনোটাতেই নয়'),
            "অথচ 'bar' ঘোষণা করেছে: ".(implode(', ', $expected) ?: 'কেউ নয়'),
            '',
            'দেখুন x-ui.toolbar-এর $filtersAlwaysOpen শর্তটা এখনো আছে কি না।',
        ]));
    }

    /**
     * চেহারার পাতা সত্যি কথাই বলে — কোনটা সাজ বদলায়, কোনটা কেবল রং।
     *
     * ── কেন এই দাবিটা আলাদা করে দরকার ────────────────────────────────
     * পর্দায় লেখা থাকে "এগুলো বাছলে মেনু ও বারের গড়নই বদলাবে"। ওই
     * কথাটা যদি ভুল দলে বসা কোনো রূপ সম্পর্কে বলা হয়, তবে পর্দাটা
     * **আত্মবিশ্বাসের সাথে মিথ্যা বলে** — আর সেটা কিছু না লেখার চেয়ে
     * খারাপ।
     *
     * ── ঠিক এই ভুলটা লেখার সময় একবার হয়েছিল ─────────────────────────
     * প্রথম নিয়মে কেবল ঘোষিত চতুষ্টয় মেলানো হত, আর তাতে **Redwood**
     * "কেবল রং" দলে পড়ত — অথচ সে ইটরঙা পটি আর স্প্রিংবোর্ড আঁকে।
     * ধরা পড়েছিল লেখার আগে হিসাব কষে, পর্দায় দেখে নয়।
     *
     * এই পরীক্ষাটা তাই দুই দিক থেকেই দেখে: যে রূপ নিজস্ব markup আঁকে
     * সে অবশ্যই "সাজ বদলায়" দলে, আর যে দলে "কেবল রং" লেখা আছে তার
     * পর্দা ডিফল্টের সাথে হুবহু এক কঙ্কাল দেখাবে।
     */
    public function test_the_appearance_page_tells_the_truth_about_each_look(): void
    {
        $wrong = [];

        foreach (Ui::keys() as $look) {
            $claimsShape = Ui::changesArrangement($look);
            $hasOwnMarkup = self::SIGNATURE[$look] !== [];
            $sameSkeleton = Ui::nav($look) === Ui::nav(Ui::DEFAULT);

            if ($hasOwnMarkup && ! $claimsShape) {
                $wrong[] = "{$look} — নিজস্ব markup আঁকে, অথচ 'কেবল রং' দলে";
            }

            if (! $claimsShape && ! $sameSkeleton) {
                $wrong[] = "{$look} — কঙ্কাল ডিফল্টের চেয়ে আলাদা, অথচ 'কেবল রং' দলে";
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'চেহারার পাতা ভুল কথা বলবে:',
            ...$wrong,
        ]));

        /*
         * দুইটা দলেই কেউ না কেউ আছে।
         *
         * এটা না থাকলে দশটাই এক দলে পড়লেও উপরের দাবিগুলো পাশ করত, আর
         * পর্দায় একটা শিরোনামের নিচে কিছুই থাকত না। সেই চেনা ফাঁদ:
         * যে পাহারা জিনিসটা না থাকলেও সবুজ।
         */
        $shape = array_filter(Ui::keys(), static fn (string $l): bool => Ui::changesArrangement($l));
        $colour = array_filter(Ui::keys(), static fn (string $l): bool => ! Ui::changesArrangement($l));

        $this->assertNotSame([], $shape, 'কোনো রূপই সাজ বদলায় না — ভাগটাই অর্থহীন।');
        $this->assertNotSame([], $colour, 'সব রূপই সাজ বদলায় — "কেবল রং" শিরোনামটা খালি থাকবে।');
    }

    /**
     * বাছাইয়ের পাতায় প্রতিটা রূপ নিজের নামেই বসে।
     *
     * ── কেন এটা আলাদা করে দেখতে হয় ──────────────────────────────────
     * কার্ডগুলো দুই দলে ভাগ করার পর `groupBy()` মূল চাবিগুলো ফেলে দিয়ে
     * ০,১,২… বসিয়েছিল। চাবিটাই রূপের নাম, আর সেটাই রেডিওর `value` —
     * তাই দশটা রেডিওর মান হয়ে গিয়েছিল সংখ্যা, আর **কোনো রূপই আর বাছাই
     * করা যেত না**। সেভ করলে `clean()` সবটাকে চুপচাপ ডিফল্টে নামাত।
     *
     * পাতাটা দেখতে নিখুঁত ছিল — দুইটা শিরোনাম, ৮ ও ২টা কার্ড, সব রং
     * ঠিক। শিরোনাম বা কার্ড গুনে ওটা ধরা যেত না; ধরা পড়েছে ব্রাউজারে
     * একটা নির্দিষ্ট রেডিও খুঁজতে গিয়ে।
     *
     * তাই পাহারাটা চেহারা দেখে না, **মান** দেখে।
     */
    public function test_every_look_can_actually_be_chosen(): void
    {
        $html = (string) $this->get(route('appearance'))->assertOk()->getContent();

        $offered = [];

        if (preg_match_all('~<input[^>]*name="ui"[^>]*value="([^"]*)"~i', $html, $m) > 0) {
            $offered = $m[1];
        }

        $missing = array_values(array_diff(Ui::keys(), $offered));
        $strange = array_values(array_diff($offered, Ui::keys()));

        $this->assertSame([], $missing, implode("\n", [
            'এই রূপগুলোর কোনো রেডিও পাতায় নেই, তাই বাছাই করা যায় না:',
            ...$missing,
        ]));

        $this->assertSame([], $strange, implode("\n", [
            'পাতায় এমন মানের রেডিও আছে যা কোনো রূপ নয়:',
            ...$strange,
            '',
            'সম্ভবত groupBy() মূল চাবিগুলো ফেলে দিয়েছে — preserveKeys: true দিন।',
        ]));
    }

    /** এক রূপে ড্যাশবোর্ডের রেন্ডার করা HTML। */
    private function shellFor(string $look): string
    {
        $this->user->forceFill(['ui' => $look])->save();

        return (string) $this->get(route('dashboard'))
            ->assertOk()
            ->getContent();
    }
}
