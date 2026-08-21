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
}
