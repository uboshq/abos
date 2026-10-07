<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\Accent;
use App\Core\Support\Ui;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চেহারা — ব্যবহারকারীর নিজের রং, থিম ও ভাষা।
 *
 * DMS-এর একই নকশা: নির্দিষ্ট তালিকা থেকে বাছাই, ব্যক্তিপ্রতি, আর প্রতিটা
 * রঙের কনট্রাস্ট আগেই যাচাই করা।
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** WCAG-এর সূত্র — DesignTokenTest-এর মতোই। */
    private function contrast(string $rgbTriplet, array $against): float
    {
        $luminance = function (array $channels): float {
            $linear = array_map(
                fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
                $channels,
            );

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };

        $l1 = $luminance(array_map('floatval', explode(' ', $rgbTriplet)));
        $l2 = $luminance($against);

        [$light, $dark] = $l1 > $l2 ? [$l1, $l2] : [$l2, $l1];

        return ($light + 0.05) / ($dark + 0.05);
    }

    public function test_every_offered_colour_keeps_its_button_text_readable(): void
    {
        foreach (Accent::all() as $key => $accent) {
            /*
             * কালিটা রংটাই বলে — "সাদা" ধরে নেওয়া হয় না।
             *
             * ── দাবিটা কেন বদলাল, আর কেন এটা শিথিল করা নয় ────────────
             * আগে এখানে সরাসরি সাদা (255 255 255) বসানো ছিল। দাবিটা
             * ঠিকই ছিল — **বোতামের লেখা পড়া যেতে হবে** — কিন্তু
             * "সাদা" শব্দটা ছিল একটা বাস্তবায়নের নাম, দাবির অংশ নয়।
             *
             * ফল: তালিকায় কেবল সেইসব রংই রাখা যেত যাদের উপরে সাদা
             * পড়া যায়। অ্যাম্বার (#E08C1A, সাদার সাথে ২.৬৫:১) কোনোদিন
             * ঢুকতে পারত না — অথচ কালো লেখায় ওটা অনেক উপরে, আর নমুনা
             * নিজেই ওখানে কালো লেখা (#1A1A1A) ব্যবহার করেছে।
             *
             * এখন প্রতিটা রং নিজের কালি ঘোষণা করে আর পাহারাটা সেটার
             * বিরুদ্ধেই মাপে। সীমা একই — ৪.৫:১, AA। শিথিল হয়নি,
             * কেবল সত্যিকারের প্রশ্নটা জিজ্ঞেস করছে।
             */
            $ink = array_map('intval', explode(' ', $accent['ink']));

            foreach (['600', '700'] as $step) {
                $ratio = $this->contrast($accent['scale'][$step], $ink);

                // এটাই নির্দিষ্ট তালিকা রাখার কারণ। মুক্ত পিকারে কেউ এমন
                // হলুদ বাছত যাতে লেখা মিলিয়ে যায় — আর যে বোতামটা
                // তখন পড়া যেত না, সেটাই ইনভয়েস সেভ করার বোতাম।
                $this->assertGreaterThanOrEqual(
                    4.5,
                    $ratio,
                    sprintf('%s-%s is %.2f:1 against its own ink.', $key, $step, $ratio),
                );
            }
        }
    }

    /**
     * গাঢ় জমিনেও ব্যবহারকারীর নিজের রং, আর সেটা পড়া যায়।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * `tokens.css`-এর গাঢ় ব্লকে `--color-brand-500: #3b82f6` হার্ডকোড
     * ছিল। ফলে গাঢ় সুইচ টিপলে **পনেরোটা রংই এক নীল হয়ে যেত** —
     * যিনি সবুজ বেছেছিলেন তিনি রাতে নীল পেতেন।
     *
     * নকলের জন্য সেটা আরও খারাপ: Odoo রূপে অবার্জিন মাথার পাশে নীল
     * বোতাম বসত, Salesforce-এ নেভি মাথার পাশে অন্য নীল। এক পর্দায়
     * দুই ব্র্যান্ড।
     *
     * ── দুইটা সীমা, আর দুইটাই আলাদা কারণে ───────────────────────────
     * · **লেখা বনাম বোতাম ≥ ৪.৫:১** — নাহলে বোতামের লেখা পড়া যায় না
     * · **বোতাম বনাম পাতা ≥ ৩:১** (WCAG ১.৪.১১) — নাহলে গাঢ় জমিনে
     *   বোতামের কিনারাটাই মিলিয়ে যায়, আর মানুষ বুঝতে পারেন না
     *   ওখানে টেপার মতো কিছু আছে
     *
     * দ্বিতীয়টা ভুলে যাওয়া সহজ, কারণ লেখা পড়া যায় বলে সব ঠিক মনে হয়।
     *
     * সবচেয়ে গাঢ় কার্ডটা ধরে মাপা (Linear-এর #17181B) — ওটা পেরোলে
     * বাকি নয়টা রূপেও পেরোয়।
     */
    public function test_the_dark_screen_keeps_the_chosen_colour_and_stays_readable(): void
    {
        // Linear-এর কার্ড — দশটার মধ্যে সবচেয়ে গাঢ়
        $darkestCard = [23, 24, 27];

        foreach (Accent::all() as $key => $accent) {
            $this->assertArrayHasKey('dark', $accent, "{$key}-এর গাঢ় ধাপ ঘোষণা করা নেই।");
            $this->assertArrayHasKey('dark_ink', $accent, "{$key}-এর গাঢ় কালি ঘোষণা করা নেই।");

            $onPage = $this->contrast($accent['dark'], $darkestCard);

            $this->assertGreaterThanOrEqual(3.0, $onPage, sprintf(
                '%s-এর গাঢ় ধাপ পাতার সাথে %.2f:১ — বোতামের কিনারা মিলিয়ে যাবে।',
                $key, $onPage,
            ));

            $ink = array_map('intval', explode(' ', (string) $accent['dark_ink']));
            $onButton = $this->contrast($accent['dark'], $ink);

            $this->assertGreaterThanOrEqual(4.5, $onButton, sprintf(
                '%s-এর গাঢ় বোতামে লেখা %.2f:১ — পড়া যাবে না।',
                $key, $onButton,
            ));
        }
    }

    /**
     * গাঢ় ধাপটা যেন অন্য একটা রং হয়ে না যায়।
     *
     * ── কেন এই দ্বিতীয় পাহারাটা লাগে ─────────────────────────────────
     * উপরের পরীক্ষাটা কেবল কনট্রাস্ট মাপে। কেউ অবার্জিনের গাঢ় ধাপে
     * সবুজ বসিয়ে দিলে ওটা দুইটা সীমাই পেরোত, আর পরীক্ষা সবুজ থাকত —
     * অথচ Odoo রূপে রাতে সবুজ বোতাম।
     *
     * তাই দাবিটা রঙের **চরিত্র** ধরে: গাঢ় ধাপটা হয় হুবহু ৫০০, নয়তো
     * ৫০০-এর একই hue-তে কেবল উজ্জ্বলতর। পনেরোটার এগারোটা হুবহু;
     * বাকি চারটায় (ইন্ডিগো · ভায়োলেট · অবার্জিন · নেটস্যুট) ৩:১
     * পেতে সামান্য তুলতে হয়েছে।
     */
    public function test_the_dark_step_is_the_same_colour_only_brighter(): void
    {
        foreach (Accent::all() as $key => $accent) {
            [$r, $g, $b] = array_map('intval', explode(' ', $accent['scale']['500']));
            [$dr, $dg, $db] = array_map('intval', explode(' ', (string) $accent['dark']));

            if ([$r, $g, $b] === [$dr, $dg, $db]) {
                continue;   // হুবহু ৫০০ — এগারোটা এখানেই থামে
            }

            /*
             * Hue মেলানো হয় ডিগ্রিতে, চ্যানেল ধরে নয়।
             *
             * চ্যানেল ধরে মিলালে "লাল বেড়েছে" ধরনের দাবি করতে হত, আর
             * উজ্জ্বল করলে তিনটা চ্যানেলই বাড়ে — দাবিটা তখন কিছুই
             * প্রমাণ করত না।
             */
            $hue = function (int $r, int $g, int $b): float {
                $max = max($r, $g, $b) / 255;
                $min = min($r, $g, $b) / 255;
                $d = $max - $min;

                if ($d < 0.0001) {
                    return 0.0;
                }

                $h = match ($max) {
                    $r / 255 => fmod((($g - $b) / 255 / $d) + 6, 6),
                    $g / 255 => (($b - $r) / 255 / $d) + 2,
                    default => (($r - $g) / 255 / $d) + 4,
                };

                return $h * 60;
            };

            $drift = abs($hue($r, $g, $b) - $hue($dr, $dg, $db));
            $drift = min($drift, 360 - $drift);

            $this->assertLessThanOrEqual(6.0, $drift, sprintf(
                '%s-এর গাঢ় ধাপ %.1f° সরে গেছে — উজ্জ্বল নয়, অন্য রং।', $key, $drift,
            ));

            $this->assertGreaterThan(
                $r + $g + $b,
                $dr + $dg + $db,
                "{$key}-এর গাঢ় ধাপ ৫০০-এর চেয়ে উজ্জ্বল নয়।",
            );
        }
    }

    public function test_the_colour_is_applied_on_the_server_not_in_javascript(): void
    {
        $this->owner()->forceFill(['accent' => 'emerald'])->save();

        $response = $this->actingAs($this->owner()->fresh())->get('/');

        // <html>-এ বসে, তাই পাতাটা প্রথম আঁকাতেই ঠিক রঙে। JS-এ করলে
        // ডিফল্ট রঙে এঁকে তারপর বদলাত — প্রতিটা লোডে একবার ঝলকানি।
        //
        // মানটা হাতে লেখা নয়, তালিকা থেকেই নেওয়া: হাতে লিখলে রংটা এক ধাপ
        // গাঢ় করার সাথে সাথে টেস্টটাও ভাঙত, অথচ আচরণ ঠিকই থাকত।
        $expected = '--accent-600:'.Accent::get('emerald')['scale']['600'].';';

        $response->assertSee($expected, false);
    }

    public function test_the_choice_is_remembered_on_the_user_not_the_browser(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/appearance', [
            'accent' => 'violet',
            'theme' => 'dark',
            'locale' => 'en',
        ])->assertRedirect();

        // রেকর্ডে, সেশনে নয় — এক ডিপোর একটা কম্পিউটারে দিনে তিনজন বসে,
        // আর সকালের অপারেটরের পছন্দ সন্ধ্যারজনকে স্বাগত জানানো উচিত নয়।
        $fresh = $owner->fresh();
        $this->assertSame('violet', $fresh->accent);
        $this->assertSame('dark', $fresh->theme);
        $this->assertSame('en', $fresh->locale);
    }

    public function test_a_colour_outside_the_list_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->post('/appearance', ['accent' => '#ffff00', 'theme' => 'light', 'locale' => 'bn'])
            ->assertSessionHasErrors('accent');

        $this->assertSame('blue', $this->owner()->fresh()->accent);
    }

    public function test_two_people_can_have_different_colours(): void
    {
        $owner = $this->owner();
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $owner->forceFill(['accent' => 'teal'])->save();
        $salesman->forceFill(['accent' => 'violet'])->save();

        $this->assertSame('teal', $owner->fresh()->accent);
        $this->assertSame('violet', $salesman->fresh()->accent);
    }

    public function test_the_screen_lists_every_colour_in_both_languages(): void
    {
        $response = $this->actingAs($this->owner())->get('/appearance');

        $response->assertOk();

        foreach (Accent::all() as $accent) {
            foreach (['bn', 'en'] as $locale) {
                $this->assertNotSame(
                    $accent['label'],
                    __($accent['label'], [], $locale),
                    "Missing {$locale} label for {$accent['label']}.",
                );
            }
        }
    }

    public function test_an_unknown_key_falls_back_rather_than_breaking_the_page(): void
    {
        // ডাটাবেজে হাতে বসানো ভুল মান, বা তালিকা থেকে সরানো একটা রং —
        // পাতাটা ভেঙে পড়ার চেয়ে ডিফল্টে ফেরা ভালো।
        $style = Accent::styleFor('a-colour-that-was-removed');

        $this->assertStringContainsString('--accent-600:', $style);
    }

    /**
     * প্রতিটা চেহারার সুপারিশ করা রংটা সত্যিই তালিকায় আছে।
     *
     * ── এটা না থাকলে যা ঘটত ─────────────────────────────────────────
     * `Ui::accent()` একটা চাবি ফেরত দেয়, আর `Accent::get()` অচেনা
     * চাবিতে ব্যতিক্রম ছোঁড়ে। অর্থাৎ কেউ একটা চেহারায় ভুল বানানে রং
     * লিখলে চেহারার পাতা **সেভ করার সময়** ৫০০ দিত — আর কেবল তখনই,
     * যখন কেউ ওই রূপটা টিক দিয়ে বেছে সেভ চাপতেন।
     */
    public function test_every_look_recommends_a_colour_that_exists(): void
    {
        foreach (Ui::keys() as $look) {
            $this->assertArrayHasKey(
                Ui::accent($look),
                Accent::all(),
                "চেহারা {$look} এমন একটা রং সুপারিশ করে যেটা তালিকায় নেই।",
            );
        }
    }

    /**
     * যে রূপ কারও নকল, সে তার আসল রংটাই দেয় — হালকা কোনো রূপ নয়।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * অবার্জিনের আসল হেক্স (#714B67, Odoo-র নিজের) বসানো ছিল ৬০০-তে,
     * আর ৫০০-তে ছিল তার একটা হালকা রূপ। বোতাম, বাছাইয়ের বর্ডার ও
     * লিংক — সব `--color-brand-500` থেকে রং নেয়, তাই Odoo রূপে
     * প্রতিটা প্রধান বোতাম ভুল বেগুনিতে আঁকা হত।
     *
     * এক পর্দাতেই ধরা পড়েছে: টপবার rgb(113,75,103), আর তার পাশের
     * বোতাম rgb(138,94,126)। আসল Odoo-তে দুইটাই এক।
     *
     * ── কেন এই পরীক্ষাটা swatch ধরে মেলায় ───────────────────────────
     * swatch-টাই ঘোষণা: "এই রংটা পাবেন"। পর্দায় ওটাই গোল্লা হয়ে বসে,
     * আর মানুষ ওটা দেখেই বাছেন। ৫০০ তার সাথে না মিললে গোল্লাটা একটা
     * মিথ্যা প্রতিশ্রুতি।
     *
     * নিয়মটা কেবল নকল করা রূপগুলোতে খাটে। ABOS-এর নিজের রংগুলোতে
     * (নীল, সবুজ, ইন্ডিগো…) swatch ইচ্ছাকৃতভাবে এক ধাপ গাঢ় — ছোট
     * গোল্লায় গাঢ় রং ভালো পড়া যায়। ওখানে কোনো আসল রং নকল করার
     * দায় নেই, তাই ওটা পছন্দ; নকলে সেটা ভুল।
     */
    public function test_a_look_that_copies_an_erp_hands_over_that_erps_own_colour(): void
    {
        foreach (Ui::all() as $key => $look) {
            if (($look['imitates'] ?? null) === null) {
                continue;   // ABOS-এর নিজের রূপ — নকল করার কিছু নেই
            }

            $accent = Accent::all()[Ui::accent($key)];

            [$r, $g, $b] = array_map('intval', explode(' ', $accent['scale']['500']));

            $this->assertSame(
                strtolower($accent['swatch']),
                sprintf('#%02x%02x%02x', $r, $g, $b),
                "'{$key}' রূপটা {$look['imitates']}-এর নকল, কিন্তু তার রঙের ৫০০ ".
                'swatch-এর সাথে মিলছে না। বোতাম ও বাছাইয়ের বর্ডার ৫০০ থেকে রং নেয়, '.
                'তাই পর্দায় গোল্লা এক রং দেখাবে আর বোতাম আরেকটা।',
            );
        }
    }

    /**
     * ক্লিক করলে বাছাইটা সাথে সাথেই দেখা যায়।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * রেডিওগুলো `sr-only`, আর বাছাইয়ের বর্ডার-রংটা বসত সার্ভারের
     * `$selected` দেখে। ফলে কার্ডে ক্লিক করলে রেডিওটা ঠিকই বাছা হত,
     * অথচ **পর্দায় একটুও কিছু বদলাত না**।
     *
     * ব্যবহারকারীর কাছে সেটা "মাউস কাজ করছে না"। তিনি আবার ক্লিক করেন,
     * আবার কিছু হয় না, তৃতীয়বারে লেখাটা সিলেক্ট হয়ে যায় — আর তিনি
     * ধরে নেন পাতাটা ভাঙা, তাই সংরক্ষণও টেপেন না।
     *
     * ── কেন এই পরীক্ষাটা গড়ন দেখে, রং নয় ────────────────────────────
     * আসল আচরণটা CSS-এর, তাই সেটা মাপতে ব্রাউজার লাগে (আর মাপা
     * হয়েছে — ট্রানজিশনের পরে বর্ডার ব্র্যান্ড-নীল)। এখানে পাহারা
     * দেওয়া হয় শর্তটা: প্রতিটা বাছাইয়ের ঘর যেন রেডিওর নিজের অবস্থা
     * থেকে সাড়া দেয়, সার্ভারের রেন্ডার থেকে নয়। কেউ আবার
     * `@class([... => $selected])`-এ ফিরে গেলে এটাই ভাঙে।
     */
    public function test_clicking_a_choice_shows_itself_without_waiting_for_a_save(): void
    {
        $html = $this->actingAs($this->owner())
            ->get(route('appearance'))
            ->assertOk()
            ->getContent();

        /*
         * প্রতিটা `sr-only` রেডিওর নিজের মোড়কটা যেন `has-[:checked]`
         * দিয়ে সাড়া দেয়। গোনাটা রেডিওর সংখ্যা ধরে নয় — চারটা দল
         * (চেহারা, রং, থিম, ভাষা), প্রতিটার একটা করে মোড়ক-নকশা।
         */
        foreach (['ui', 'accent', 'theme', 'locale'] as $group) {
            $this->assertMatchesRegularExpression(
                '/has-\[:checked\][^>]*>\s*(?:\{\{--.*?--\}\}\s*)?<input type="radio" name="'.$group.'"/s',
                $html,
                "'{$group}' বাছাইয়ের ঘরটা ক্লিকের সাথে সাথে সাড়া দেয় না — ".
                'ব্যবহারকারীর কাছে ওটা "মাউস কাজ করছে না"।',
            );
        }

        // ✓ চিহ্নটাও তাই — সার্ভারের রেন্ডারের অপেক্ষায় নয়
        $this->assertStringContainsString('group-has-[:checked]:inline', $html);

        // ক্লিক করলে যেন লেখা সিলেক্ট হয়ে না যায়
        $this->assertStringContainsString('select-none', $html);
    }
}
