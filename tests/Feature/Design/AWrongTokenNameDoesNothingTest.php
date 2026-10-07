<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\LookRegistry;
use App\Core\Support\LookSchema;
use App\Core\Support\Ui;
use Tests\TestCase;

/**
 * ভুল টোকেনের নাম নীরবে কিছুই করে না।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * CSS-এ `--color-surfase-app` লিখলে ব্রাউজার নীরবে ওটা রেখে দেয়। কেউ
 * পড়ে না, রংটা ডিফল্ট থেকে আসে, আর পর্দাটা দেখতে **প্রায় ঠিক**।
 *
 * প্রায়-ঠিক জিনিসটাই সবচেয়ে খারাপ: ধরতে হলে দুইটা রূপ পাশাপাশি রেখে
 * চোখে মেলাতে হয়, আর কেউ সেটা করে না।
 *
 * থিম ইঞ্জিনের ধাপ ৩-এ মানুষ পর্দা থেকে রূপ বানাবেন। তখন এই নীরবতাটা
 * চলবে না — একটা বানান ভুল মানে একটা রূপ যেটা কেউ বুঝতে পারবে না কেন
 * অন্যরকম দেখাচ্ছে। তাই স্কিমাটা সম্পাদনার পর্দার অনেক আগেই।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * শেষেরটা: **আজকের দশটা রূপ নিজেরাই স্কিমা পাশ করে**।
 *
 * একটা যাচাই যদি সঠিক জিনিসকে ভুল বলে, সেটা কয়েক দিনের মধ্যেই বন্ধ
 * করে দেওয়া হয় — আর তখন ভুলগুলোও আর ধরা পড়ে না। লেখার সময় এটা দুইবার
 * ঘটেছে: প্রথমে চারটা মাপের টোকেন হাতে-লেখা তালিকা থেকে বাদ পড়েছিল,
 * তারপর `0` আর `1`-কে মাপ ধরায় দুইটা সঠিক মান ভুল বলা হচ্ছিল।
 */
class AWrongTokenNameDoesNothingTest extends TestCase
{
    /** বানান ভুল ধরা পড়ে — এটাই স্কিমার আসল কাজ। */
    public function test_a_misspelled_name_is_refused(): void
    {
        $said = LookSchema::complaints(['--color-surfase-app' => '#ffffff']);

        $this->assertCount(1, $said);
        $this->assertStringContainsString('--color-surfase-app', $said[0]);
    }

    /** রঙের ঘরে মাপ, আর মাপের ঘরে রং — দুইটাই ধরা পড়ে। */
    public function test_a_value_of_the_wrong_kind_is_refused(): void
    {
        $this->assertCount(1, LookSchema::complaints(['--row-height' => '#ffffff']));
        $this->assertCount(1, LookSchema::complaints(['--color-ink' => '44px']));
    }

    /** খালি মান — CSS-এ ওটা টোকেনটাকে অকার্যকর করে, নীরবে। */
    public function test_an_empty_value_is_refused(): void
    {
        $this->assertCount(1, LookSchema::complaints(['--radius-card' => '']));
    }

    /**
     * ফলব্যাকসহ ব্যবহৃত টোকেনগুলোও চেনা।
     *
     * `--cmd-font` ও `--grid-pad-y-head` ইচ্ছাকৃতভাবে `:root`-এ ঘোষিত
     * নয় — ওগুলো ঐচ্ছিক, `var(--cmd-font, 13px)` হয়ে ব্যবহার হয়।
     *
     * কেবল `--x:` ঘোষণা খুঁজলে ওগুলো "অচেনা" হত, আর NetSuite ও
     * ক্লাসিকের সঠিক রূপ সেভ করাই যেত না।
     */
    public function test_a_token_that_only_appears_as_a_fallback_is_still_known(): void
    {
        foreach (['--cmd-font', '--grid-pad-y-head'] as $name) {
            $this->assertContains($name, LookSchema::known(),
                "{$name} ঐচ্ছিক টোকেন, অচেনা নয় — ওটা ফেরালে সঠিক রূপই আটকে যেত।");
        }
    }

    /**
     * একক ছাড়া সংখ্যায় স্কিমা চুপ থাকে।
     *
     * `0` একটা বৈধ দৈর্ঘ্য (CSS-এ শূন্যের একক লাগে না), `--state-fill: 0`
     * একটা অনুপাত, আর `--stage-tile: 1` একটা গুণক। একই লেখা, তিন রকম
     * মানে — তাই বলা যায় না, আর না বলাটাই সৎ।
     */
    public function test_a_bare_number_is_not_second_guessed(): void
    {
        $this->assertSame([], LookSchema::complaints([
            '--state-fill' => '0',
            '--stage-tile' => '1',
            '--radius-field' => '0',
        ]));
    }

    /**
     * আজকের দশটা রূপ নিজেরাই স্কিমা পাশ করে।
     *
     * এটাই এই ফাইলের ভিত্তি। স্কিমা যদি সঠিক জিনিস ফিরিয়ে দেয়, সেটা
     * কয়েক দিনেই বন্ধ হয়ে যায় — আর তখন ভুলগুলোও ধরা পড়ে না।
     */
    public function test_the_ten_looks_we_already_have_pass_their_own_schema(): void
    {
        foreach (Ui::keys() as $look) {
            foreach (['light', 'dark'] as $mode) {
                $said = LookSchema::complaints(LookRegistry::of($look)[$mode]);

                $this->assertSame([], $said, sprintf(
                    "'%s' রূপের %s টোকেনে স্কিমা আপত্তি তুলছে: %s",
                    $look, $mode, implode(' | ', $said),
                ));
            }
        }
    }
}
