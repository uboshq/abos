<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\LookRegistry;
use App\Core\Support\Ui;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * রূপগুলো ডাটায় থাকে, স্টাইলশিটে নয় — আর ফিরে যাওয়া চলবে না।
 *
 * ── এই ফাইলটার একটা পূর্বপুরুষ ছিল ───────────────────────────────────
 * ধাপ ১-এর মাঝপথে `LooksMatchTheStylesheetTest` নামে একটা পরীক্ষা ছিল,
 * আর তার কাজ ছিল দুই জায়গার মান মিলিয়ে দেখা: টোকেনগুলো `themes.css`
 * থেকে `Looks/*.php`-তে সরানো হয়েছে, কিন্তু একটাও মান নড়েনি তো?
 *
 * সংকলক বসার পর CSS ব্লকগুলো উঠে গেছে, তাই মেলানোর দ্বিতীয় জায়গাটাই
 * আর নেই — ওই পরীক্ষাটা নিজের কাজ শেষ করে অর্থহীন হয়ে গেছে। সেটা তার
 * নিজের মন্তব্যেই লেখা ছিল।
 *
 * ── কিন্তু মুছে ফেলা নয়, উল্টো দিকে ঘোরানো ──────────────────────────
 * এখন প্রশ্নটা উল্টো: **কেউ যেন আবার CSS-এ রূপের টোকেন না লেখে**।
 *
 * ওটা ঘটা সহজ — একটা রূপের রং শোধরাতে গিয়ে `themes.css`-এ একটা ব্লক
 * লিখে ফেলা স্বাভাবিক অভ্যাস, আর সেটা কাজও করবে। কিন্তু তখন দুইটা
 * সত্য দাঁড়াবে, ইনলাইন টোকেন CSS-কে হারাবে, আর মানুষ ভাববে তার
 * সম্পাদনাটা "কাজ করেনি"।
 *
 * ── আসল প্রমাণটা এখানে নয় ────────────────────────────────────────────
 * "একটাও পিক্সেল নড়েনি" প্রমাণ করে `tools/verify-themes.py` — দশটা রূপ
 * ব্রাউজারে ঘুরিয়ে computed value পড়ে। সংকলক বসানোর পর সেটা **০
 * গরমিল, ৬৩/৬৩ চিহ্ন-অংশ** দিয়েছে।
 *
 * এই ফাইলটা কেবল কাঠামোটা ধরে রাখে।
 */
class TheLooksLiveInDataNotCssTest extends TestCase
{
    /**
     * স্টাইলশিটে আর কোনো রূপের টোকেন-ব্লক নেই।
     *
     * এটাই এই ফাইলের আসল দাবি। একটা ব্লক ফিরে এলে দুইটা সত্য দাঁড়ায়,
     * আর ইনলাইনটা জেতে — অর্থাৎ CSS-এ লেখা সম্পাদনাটা নীরবে হারায়।
     */
    public function test_no_look_keeps_a_block_in_the_stylesheet(): void
    {
        $css = (string) File::get(resource_path('css/themes.css'));

        /*
         * মন্তব্য একই দৈর্ঘ্যের ফাঁকা দিয়ে ঢাকা, মুছে ফেলা নয়।
         *
         * ব্যাখ্যায় `--color-module-{code}` ধরনের ছাঁচ লেখা থাকে, আর
         * ওই `{` ব্রেস ধরে পড়াটা ভেঙে দেয় — ধাপ ১-এ ঠিক এই কারণে
         * একটা ব্লক বাদ পড়া থেকে বেঁচে গিয়েছিল।
         */
        $masked = (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            fn (array $m) => str_repeat(' ', strlen($m[0])),
            $css,
        );

        preg_match_all("/\[data-ui='([a-z]+)'\]/", $masked, $found);

        $this->assertSame([], array_unique($found[1]),
            'রূপের টোকেন আবার স্টাইলশিটে ফিরেছে। ওগুলোর জায়গা '.
            '`app/Core/Support/Looks/*.php` — নাহলে দুইটা সত্য দাঁড়ায়, '.
            'আর ইনলাইনটা জেতে বলে CSS-এ লেখা সম্পাদনা নীরবে হারায়।');
    }

    /**
     * দশটা রূপেরই টোকেন আছে, আর একটাও অসম্পূর্ণ নয়।
     *
     * একটা রূপের ফাইল তৈরি হতে ভুলে গেলে বা অর্ধেক লেখা হয়ে থেমে গেলে
     * পর্দা ভাঙে না — কেবল ডিফল্ট রঙে নামে, আর দেখতে **প্রায় ঠিক**
     * লাগে।
     */
    public function test_every_look_brought_its_tokens_along(): void
    {
        foreach (Ui::keys() as $look) {
            $light = LookRegistry::of($look)['light'];

            $this->assertNotEmpty($light, "'{$look}' রূপের একটাও টোকেন নেই।");

            /*
             * চল্লিশ — সবচেয়ে ছোট রূপটাও (navy, ৫৩) এর অনেক উপরে।
             * সংখ্যাটা আঁটসাঁট নয় ইচ্ছাকৃতভাবে: দাবিটা "টোকেন গুনে দেখা"
             * নয়, "ফাইলটা অর্ধেক লেখা হয়ে থামেনি"।
             */
            $this->assertGreaterThan(40, count($light),
                "'{$look}' রূপে মাত্র ".count($light).'টা টোকেন — ফাইলটা অসম্পূর্ণ।');
        }
    }

    /**
     * গাঢ় রূপ মানে "গাঢ় সেটটা" নয়, "হালকাটার উপর গাঢ়টা"।
     *
     * গাঢ় সেটে কেবল সেটুকু লেখা যেটুকু রাতে বদলায় — ধার, ঘনত্ব ও
     * সারির উচ্চতা ওখানে নেই। কেবল গাঢ় সেটটা নিলে Redwood রাতে তার
     * ১৬px গোল কোণ হারাত, আর NetSuite তার ২৪px সারি।
     */
    public function test_dark_keeps_what_the_night_does_not_change(): void
    {
        foreach (Ui::keys() as $look) {
            $day = LookRegistry::tokens($look, 'light');
            $night = LookRegistry::tokens($look, 'dark');

            foreach (['--radius-card', '--row-height', '--font-size-table'] as $token) {
                if (! isset($day[$token])) {
                    continue;
                }

                $this->assertSame($day[$token], $night[$token], sprintf(
                    "'%s' রূপে %s রাতে বদলে গেছে — ওটা রঙের ব্যাপার নয়, গড়নের।",
                    $look, $token,
                ));
            }
        }
    }

    /**
     * সংকলক যা পাঠায় তা সত্যিই CSS-এ বসার মতো।
     *
     * ইনলাইন `style`-এ একটা ভাঙা ঘোষণা থাকলে ব্রাউজার **পুরো
     * attribute-টাই** ফেলে দেয় না, কিন্তু ওই ঘোষণাটা নীরবে হারায় —
     * আর তখন একটা রং ডিফল্ট থেকে আসে, কারণ ছাড়াই।
     */
    public function test_what_the_compiler_sends_is_shaped_like_css(): void
    {
        foreach (Ui::keys() as $look) {
            foreach (['light', 'dark'] as $theme) {
                $style = LookRegistry::styleFor($look, $theme);

                $this->assertNotSame('', $style, "'{$look}' রূপ কিছুই পাঠাচ্ছে না।");
                $this->assertStringEndsWith(';', $style);

                foreach (array_filter(explode(';', $style)) as $piece) {
                    $this->assertMatchesRegularExpression(
                        '/^--[a-zA-Z0-9_-]+:.+$/', $piece,
                        "'{$look}' রূপের একটা ঘোষণা ভাঙা: {$piece}",
                    );
                }
            }
        }
    }
}
