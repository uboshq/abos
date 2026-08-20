<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

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

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-ui="classic"', false)
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
        $css = (string) file_get_contents(base_path('resources/css/themes.css'));

        $without = [];

        foreach (Ui::keys() as $look) {
            /*
             * ক্লাসিক বাদ, আর এই বাদটাই তার সংজ্ঞা।
             *
             * ক্লাসিক মানে "কোনো টোকেন বদলায় না" — `:root`-এ যা লেখা
             * আছে হুবহু তাই। ওর একটা ব্লক থাকলে সেটা আর ক্লাসিক থাকত না।
             */
            if ($look === Ui::DEFAULT) {
                continue;
            }

            if (! str_contains($css, "[data-ui='{$look}']")) {
                $without[] = $look;
            }
        }

        $this->assertSame([], $without, implode("\n", [
            'এই চেহারাগুলো Ui::all()-এ আছে, কিন্তু themes.css-এ ওদের',
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
        $css = (string) file_get_contents(base_path('resources/css/themes.css'));
        $thin = [];

        foreach (Ui::keys() as $look) {
            if ($look === Ui::DEFAULT) {
                continue;
            }

            preg_match("/\[data-ui='{$look}'\]\s*\{(.*?)\}/s", $css, $m);

            $count = substr_count($m[1] ?? '', '--');

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

            $this->assertContains($where, ['rail', 'top'], "চেহারা {$look}-এর nav অচেনা: {$where}");

            if ($where === 'top') {
                $this->assertStringContainsString('class="topnav', $html,
                    "{$look} উপরে মেনু চায়, কিন্তু পর্দায় উপরের পটিটা নেই।");
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
