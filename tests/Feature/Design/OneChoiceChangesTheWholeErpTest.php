<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\LookRegistry;
use App\Core\Support\Ui;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একটা বাছাই গোটা ERP বদলায়।
 *
 * ── প্রতিশ্রুতিটা কী ─────────────────────────────────────────────────
 * Apps বাছলে দুইশো বাহান্নটা পর্দাই Odoo-র মতো দেখাবে; Tiles বাছলে
 * সবগুলোই SAP Fiori-র মতো। "পর্দার রং বদলানো" নয় — গোটা সফটওয়্যার।
 *
 * ── এটা যেভাবে নীরবে ভাঙতে পারত ─────────────────────────────────────
 * শেকলটার চারটা কড়া: PHP-র তালিকা → ডাটাবেসের কলাম → `<html data-ui>`
 * → CSS-এর টোকেন-সেট। যেকোনো একটা ছুটে গেলে বাকিগুলো দিব্যি কাজ করে,
 * আর পর্দায় কিছুই ভাঙে না — কেবল বাছাইটা কোনো কাজ করে না।
 *
 * সবচেয়ে সম্ভাব্য ছুটে যাওয়া: কেউ নবম একটা চেহারা `Ui::all()`-এ
 * যোগ করলেন, আর `themes.css`-এ ব্লকটা লিখতে ভুলে গেলেন। তখন পর্দায়
 * ওটা বাছা যায়, সেভও হয়, `data-ui="নতুন"` বসেও যায় — আর দেখতে হয়
 * হুবহু ক্লাসিকের মতো। কেউ বলতে পারবেন না কেন।
 *
 * তাই নিচের প্রতিটা কড়া আলাদা করে পরখ করা হয়।
 */
class OneChoiceChangesTheWholeErpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** কড়া ১ — বাছাইটা সত্যিই জমা হয়। */
    public function test_the_choice_is_saved_on_the_person_not_the_session(): void
    {
        $this->actingAs($this->user)
            ->post(route('appearance.save'), [
                'ui' => 'apps',
                'accent' => 'blue',
                'theme' => 'light',
                'locale' => 'bn',
            ])
            ->assertRedirect();

        /*
         * ডাটাবেস থেকে নতুন করে পড়া, স্মৃতির বস্তু থেকে নয়।
         *
         * `$this->user->ui` পড়লে পরীক্ষাটা পাশ করত এমনকি যদি সেভটা
         * কখনো ডিস্কে না পৌঁছত — আর ঠিক ওই ব্যর্থতাটাই ব্যবহারকারী
         * টের পেতেন, পরের দিন লগইন করে।
         */
        $this->assertSame('apps', User::query()->find($this->user->id)?->ui);
    }

    /** কড়া ২ — পাতাটা সত্যিই ওই চেহারাটা পরে বেরোয়। */
    public function test_the_page_comes_out_wearing_the_chosen_look(): void
    {
        foreach (['tiles', 'apps', 'redwood'] as $look) {
            $this->user->forceFill(['ui' => $look])->save();

            $this->actingAs($this->user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('data-ui="'.$look.'"', false);
        }
    }

    /**
     * কড়া ৩ — অচেনা মান পাতাটা ভাঙে না।
     *
     * কলামটা একটা string। কেউ সরাসরি ডাটাবেসে `odoo` বসিয়ে দিলে
     * `data-ui="odoo"` বসত, যার কোনো টোকেন-সেট নেই — পাতাটা তখন
     * রংহীন, মাপহীন আঁকা হত, আর দেখে মনে হত CSS লোডই হয়নি।
     */
    public function test_a_look_that_does_not_exist_falls_back_instead_of_breaking(): void
    {
        $this->user->forceFill(['ui' => 'odoo'])->save();

        /*
         * ফেরার জায়গাটা ABOS-এর নিজেরটা, ক্লাসিক নয়।
         *
         * ভুল মান মানে "কোনো বাছাই নেই", আর বাছাই না থাকলে যা দেখানোর
         * কথা সেটাই দেখানো হয় — `Ui::DEFAULT`। নামটা হাতে লেখা হয়নি,
         * নাহলে ডিফল্ট বদলানোর দিন এই পরীক্ষাটা ভাঙত অথচ আচরণ ঠিকই
         * থাকত।
         */
        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-ui="'.Ui::DEFAULT.'"', false)
            ->assertDontSee('data-ui="odoo"', false);
    }

    /**
     * কড়া ৪ — প্রতিটা চেহারার সত্যিই একটা টোকেন-সেট আছে।
     *
     * **এটাই এই ফাইলের আসল পাহারা।** উপরের তিনটা শেকলের ভেতরের কড়া;
     * এটা ধরে শেষ কড়াটা — যেখানে বাছাইটা সত্যিই চেহারায় পরিণত হয়।
     *
     * এটা না থাকলে নবম চেহারা যোগ করা যেত CSS ছাড়াই, আর সবকিছু
     * "কাজ করছে" বলে মনে হত: বাছা যায়, সেভ হয়, attribute বসে। শুধু
     * পর্দাটা বদলাত না।
     */
    public function test_every_look_has_a_token_set_of_its_own(): void
    {
        /*
         * উৎসটা এখন রেজিস্ট্রি, স্টাইলশিট নয়।
         *
         * ── কেন বদলাতে হলো ──────────────────────────────────────────
         * থিম ইঞ্জিনের ধাপ ১-এ টোকেনগুলো `themes.css` থেকে
         * `Looks/*.php`-তে সরেছে, আর CSS ব্লকগুলো উঠে গেছে। ফাইলটা
         * পড়তে থাকা এই পাহারা তখন দশটা রূপের সবগুলোকেই "শূন্য টোকেন"
         * বলছিল।
         *
         * দাবিটা একটুও বদলায়নি — প্রতিটা রূপের নিজের টোকেন-সেট থাকতে
         * হবে। কেবল সেটটা এখন অন্য জায়গায় থাকে।
         */
        $without = [];

        foreach (Ui::keys() as $look) {
            /*
             * ক্লাসিকও বাদ যায় না — আর সেই ছাড়টাই ইঞ্জিনের সাথে উঠেছে।
             *
             * ── কেন আগে ছাড় ছিল ─────────────────────────────────────
             * ক্লাসিক মানে ছিল "কোনো টোকেন বদলায় না" — `:root`-এ যা
             * লেখা হুবহু তাই। তাই তার কোনো ব্লক ছিল না, আর ব্লক চাওয়া
             * মানেই ছিল ভুল চাওয়া। ওটার নাম ছিল `Ui::BARE`।
             *
             * ── কেন আর নেই ──────────────────────────────────────────
             * মালিকের নমুনা দেখার পর ক্লাসিক একটা **আলাদা, সম্পূর্ণ
             * নকশা** হয়ে গেছে — খতিয়ান রূপ, উপরে টানা মেনু, অ্যাম্বার
             * সক্রিয় ট্যাব, ২৬px সারি। আজ তার নিজের ৬৬টা টোকেন।
             *
             * ছাড়টা তবু বসে ছিল, আর তার একমাত্র ফল ছিল: দশটার মধ্যে
             * ক্লাসিককে **কেউ পরীক্ষাই করত না**। ধ্রুবকটা একটা এমন
             * জগতের কথা বলছিল যেটা আর নেই।
             */

            if (LookRegistry::of($look)['light'] === []) {
                $without[] = $look;
            }
        }

        $this->assertSame([], $without, implode("\n", [
            'এই চেহারাগুলো Ui::all()-এ আছে, কিন্তু Looks/-এ ওদের',
            'কোনো টোকেন-সেট নেই। পর্দায় বাছা যাবে, সেভও হবে, আর',
            'দেখতে হুবহু ক্লাসিকের মতোই থাকবে — কেউ বুঝতে পারবেন না কেন।',
            ...$without,
        ]));
    }

    /**
     * প্রতিটা টোকেন-সেট সত্যিই কিছু বদলায় — নাম বসানো নয়।
     *
     * উপরের পরীক্ষাটা ব্লকটা **আছে** কি না দেখে। একটা খালি ব্লকও
     * ওটা পাশ করাত, আর খালি ব্লক মানে ক্লাসিক — অর্থাৎ ঠিক সেই ভুলটাই
     * যেটা ধরার কথা ছিল, কেবল আরেকটু ভালো ছদ্মবেশে।
     */
    public function test_no_token_set_is_an_empty_shell(): void
    {
        $thin = [];

        foreach (Ui::keys() as $look) {
            $count = count(LookRegistry::of($look)['light']);

            /*
             * বারোটা কেন।
             *
             * একটা চেহারা বিশ্বাসযোগ্য হতে গেলে অন্তত তিনটা জিনিস
             * নিজের করে বলতে হয়: পৃষ্ঠতল, কালি, আর ধার/ঘনত্ব। তার
             * নিচে নামলে ওটা চেহারা নয়, একটা রঙের ছোপ।
             */
            if ($count < 12) {
                $thin[] = "{$look} — মাত্র {$count}টা টোকেন";
            }
        }

        $this->assertSame([], $thin, implode("\n", [
            'এই টোকেন-সেটগুলো এত পাতলা যে চেহারাটা আলাদা মনে হবে না।',
            ...$thin,
        ]));
    }

    /**
     * মেনুটা সত্যিই জায়গা বদলায় — কেবল রং নয়।
     *
     * ── কেন এটা আলাদা করে পরখ করতে হয় ───────────────────────────────
     * বাকি সবটা টোকেন, আর টোকেন ভুল হলে চোখে পড়ে। কিন্তু "মেনু বাঁয়ে
     * না উপরে" একটা `@if` — আর `@if` ভুল হলে কিছুই ভাঙে না, কেবল
     * দুইটার একটা কখনো আঁকা হয় না।
     *
     * সবচেয়ে সম্ভাব্য ভুল: কেউ `Ui::all()`-এ নবম চেহারা যোগ করলেন
     * `nav` ছাড়া। তখন `nav()` একটা undefined index-এ পড়ত — অথবা
     * খারাপ ক্ষেত্রে চুপচাপ রেল ধরে নিত, আর Odoo-র নকলটা বাঁ দিকের
     * রেল নিয়ে বসে থাকত।
     */
    public function test_the_menu_actually_moves_between_the_rail_and_the_top(): void
    {
        $seen = [];

        foreach (Ui::keys() as $look) {
            $this->user->forceFill(['ui' => $look])->save();

            $html = (string) $this->actingAs($this->user)
                ->get(route('dashboard'))->getContent();

            $where = Ui::nav($look);
            $seen[$where] = true;

            /*
             * `quiet` তৃতীয় বিন্যাস, আর সে যোগ হয়েছে ২৭ অগাস্ট ২০২৬-এ।
             *
             * ── মেনুর হিসাবে সে রেলের দলে ────────────────────────────
             * Linear-এর পরিচয় উপরের বারটা **না থাকা**, কিন্তু বাঁয়ের
             * তালিকাটা থাকে। তাই "মেনু কোথায়" প্রশ্নে তার উত্তর রেলের
             * মতোই; তফাতটা মেনুতে নয়, টপবারে — আর ওটা দেখে
             * [[AClonePromisedAShapeAndDrewAnotherTest]]।
             *
             * এই পাহারাটা ওই দিন লাল হয়েছিল ("nav অচেনা: quiet"), আর
             * সেটা ঠিকই হয়েছিল: নতুন বিন্যাস চুপচাপ ঢুকে পড়া উচিত নয়।
             */
            $this->assertContains($where, ['rail', 'top', 'quiet'], "চেহারা {$look}-এর nav অচেনা: {$where}");

            if ($where === 'top') {
                /*
                 * উপরে মেনু মানেই আমাদের পটিটা নয়, ২৮ আগস্ট ২০২৬ থেকে।
                 *
                 * Salesforce-ও `top`, কিন্তু Lightning-এ দ্বিতীয় কোনো
                 * পটি নেই — ওয়াফল আর চলতি অ্যাপের অবজেক্ট-ট্যাব, ব্যস।
                 * ওর নিজের শেলই ট্যাবগুলো আঁকে, তাই আমাদের পটিটাও বসলে
                 * একই পর্দায় দুইটা বসত।
                 *
                 * দাবিটা তাই রূপের নিজের ঘোষণা থেকে — কারণগুলো
                 * [[Ui::topnav()]]-এ, আর `none` মানে পটিটা **থাকা
                 * চলবে না**।
                 */
                if (Ui::topnav($look) === 'none') {
                    $this->assertStringNotContainsString('class="topnav', $html,
                        "{$look} বলেছে উপরের পটি নেই, অথচ পটিটা আঁকা হয়েছে।");
                } else {
                    $this->assertStringContainsString('class="topnav', $html,
                        "{$look} উপরে মেনু চায়, কিন্তু পর্দায় উপরের পটিটা নেই।");
                }

                $this->assertStringNotContainsString('rail-flyout', $html,
                    "{$look}-এ বাঁয়ের রেলও রয়ে গেছে — একই পর্দায় দুইটা মেনু।");
            } else {
                $this->assertStringContainsString('rail-flyout', $html,
                    "{$look} বাঁয়ে রেল চায়, কিন্তু রেলটা নেই।");
                $this->assertStringNotContainsString('class="topnav', $html,
                    "{$look}-এ উপরের পটিও রয়ে গেছে — একই পর্দায় দুইটা মেনু।");
            }
        }

        /*
         * দুইটা বিন্যাসই সত্যিই কেউ না কেউ ব্যবহার করে।
         *
         * এটা না থাকলে আটটাই `rail` হয়ে গেলেও উপরের সব দাবি পাশ করত —
         * প্রতিটা চেহারা নিজের সাথে মিলত, কেবল দ্বিতীয় বিন্যাসটা
         * কোনোদিন আঁকা হত না। ঠিক সেই চেনা ফাঁদ: যে পাহারা জিনিসটা
         * না থাকলেও পাশ করে।
         */
        $this->assertArrayHasKey('rail', $seen, 'কোনো চেহারাই বাঁয়ের রেল ব্যবহার করে না।');
        $this->assertArrayHasKey('top', $seen, 'কোনো চেহারাই উপরের মেনু ব্যবহার করে না — শেলটা মৃত কোড।');
        $this->assertArrayHasKey('quiet', $seen, 'কোনো চেহারাই quiet ব্যবহার করে না — তৃতীয় কঙ্কালটা মৃত কোড।');
    }

    /**
     * এই পর্দার কাজগুলোও জায়গা বদলায় — পাতার ভেতরে, না নিজের পটিতে।
     *
     * ── কেন এটা `nav`-এর মতোই একটা আলাদা পাহারা দাবি করে ─────────────
     * দুইটাই markup-এর সিদ্ধান্ত, আর দুইটাই একটা `@if`। `@if` ভুল হলে
     * পাতা ভাঙে না — কেবল দুইটার একটা কখনো আঁকা হয় না, আর সেটা কেউ
     * খেয়াল করে না যতক্ষণ না কেউ ওই চেহারাটা বাছেন।
     *
     * ── কেন পর্দাটা `suppliers/create` ───────────────────────────────
     * পাহারাটার জন্য এমন একটা পাতা দরকার যেটা সত্যিই `header` স্লট
     * ব্যবহার করে। অনেক তালিকা করে না — ওদের শিরোনাম টুলবারেই থাকে —
     * আর ওরকম একটা পাতা ধরলে দুইটা চেহারাতেই "পটির বাইরে কিছু নেই"
     * সত্য হত, আর পরীক্ষাটা কিছুই প্রমাণ না করেই পাশ করত।
     */
    public function test_the_page_actions_move_between_the_page_and_a_bar_of_their_own(): void
    {
        $seen = [];

        foreach (Ui::keys() as $look) {
            $this->user->forceFill(['ui' => $look])->save();

            $html = (string) $this->actingAs($this->user)
                ->get(route('supplier.create'))->getContent();

            $where = Ui::commands($look);
            $seen[$where] = true;

            $this->assertContains($where, ['bar', 'inline'],
                "চেহারা {$look}-এর commands অচেনা: {$where}");

            /*
             * `<main>`-এর আগে শিরোনামটা আছে কি না — সেটাই পুরো পার্থক্য।
             *
             * পটিটা `<main>`-এর বাইরে বসে বলেই সে লেখার কলামের সীমা
             * ছাড়িয়ে পুরো চওড়া হতে পারে। ভেতরে থাকলে সে কলামের
             * ভেতরেই আটকা, আর "পুরো-চওড়া" কথাটার মানে থাকে না।
             */
            $beforeMain = substr($html, 0, (int) strpos($html, '<main'));
            $titleIsOutside = str_contains($beforeMain, '<h1');

            if ($where === 'bar') {
                $this->assertTrue($titleIsOutside,
                    "{$look} কাজের পটি চায়, কিন্তু শিরোনামটা এখনো <main>-এর ভেতরে।");
            } else {
                $this->assertFalse($titleIsOutside,
                    "{$look} পাতার ভেতরে চায়, কিন্তু শিরোনামটা <main>-এর বাইরে চলে গেছে।");
            }

            // কোনো অবস্থাতেই দুইবার নয় — একই শিরোনাম দুই জায়গায় থাকলে
            // স্ক্রিন রিডার দুইবার পড়ত, আর চোখেও দুইবার দেখা যেত।
            $this->assertSame(1, substr_count($html, '<h1'),
                "{$look}-এ শিরোনামটা একবারের বেশি আঁকা হয়েছে।");
        }

        $this->assertArrayHasKey('bar', $seen, 'কোনো চেহারাই কাজের পটি ব্যবহার করে না — শাখাটা মৃত কোড।');
        $this->assertArrayHasKey('inline', $seen, 'কোনো চেহারাই পাতার ভেতরে রাখে না।');
    }

    /** বাছাইয়ের পর্দায় আটটাই দেখা যায় — একটাও লুকানো নয়। */
    public function test_the_chooser_offers_every_look(): void
    {
        $page = $this->actingAs($this->user)->get(route('appearance'))->getContent();

        foreach (Ui::keys() as $look) {
            $this->assertStringContainsString(
                'value="'.$look.'"',
                (string) $page,
                "চেহারা {$look} বাছাইয়ের পর্দায় নেই — কোড আছে, দরজা নেই।",
            );
        }
    }

    /**
     * তালিকার বাইরের চেহারা সেভ হয় না, আর নীরবেও হয় না।
     *
     * পড়ার সময় অচেনা মান চুপচাপ ক্লাসিক হয় (কড়া ৩)। কিন্তু লেখার
     * সময় চুপ থাকা ভুল হত: ব্যবহারকারী কিছু বাছলেন, সেভ চাপলেন, আর
     * ফিরে এসে অন্য কিছু বসে থাকতে দেখলেন — কেন, তা কোথাও লেখা নেই।
     */
    public function test_a_made_up_look_is_refused_out_loud(): void
    {
        $this->actingAs($this->user)
            ->post(route('appearance.save'), [
                'ui' => 'sap',
                'accent' => 'blue',
                'theme' => 'light',
                'locale' => 'bn',
            ])
            ->assertSessionHasErrors('ui');

        $this->assertSame(Ui::DEFAULT, User::query()->find($this->user->id)?->ui);
    }
}
