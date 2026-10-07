<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ভুল হলেও পর্দা মালিকের ভাষায় কথা বলে।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ⛔ `resources/views/errors/` ছিলই না, তাই ৪০৩/৪০৪/৫০০ সব ল্যারাভেলের
 * নিজের **ইংরেজি** পাতায় পড়ত। ⓘ গোটা সিস্টেম বাংলায়, অথচ ঠিক যে
 * মুহূর্তে মানুষ থমকে যান — সেখানেই ইংরেজি, আর অনুবাদ করার উপায়ও নেই।
 *
 * ── ⚠️ কেন পাতাগুলো নিজের খোলস পরে ──────────────────────────────────
 * অ্যাপের খোলস `$menu` চায়, আর মেনু বানাতে লগইন লাগে। কিন্তু ত্রুটির
 * পাতা ঠিক তখনই আসে যখন লগইন নেই বা কিছু ভেঙে গেছে — তাই ওখানে মেনু
 * বানাতে গেলে **ত্রুটির পাতা নিজেই ত্রুটি দিত**, আর সেটাই সবচেয়ে
 * বিরক্তিকর ধরনের ভাঙা।
 */
final class TheErrorPagesSpokeOnlyEnglishTest extends TestCase
{
    /**
     * ⛔ লগইন **ছাড়া** — এখানেই আসল পরীক্ষা।
     *
     * ⓘ লগইন করে মাপলে দাবিটা দুর্বল হত: তখন সেশন আছে, মেনু বানানো যায়,
     * আর যে ফাঁদটা ধরার কথা সেটা কখনো পাতা হয় না।
     */
    public function test_a_missing_page_speaks_the_users_language(): void
    {
        $response = $this->get('/no-such-address-anywhere');

        $response->assertNotFound();

        $response->assertSee(__('core.error.title_404'), false);
        $response->assertSee(__('core.error.go_home'), false);
    }

    /**
     * ⛔ ল্যারাভেলের নিজের পাতাটা আর আসছে না।
     *
     * ⓘ ওর ইংরেজি লেখাটা খুঁজে দেখা হয়: থাকলে বুঝতে হবে আমাদের ভিউটা
     * কোনো কারণে ব্যবহার হয়নি, আর তখন উপরের দাবিটাও ভুল কারণে সবুজ
     * থাকতে পারত।
     */
    public function test_the_framework_page_is_not_what_shows(): void
    {
        $this->get('/no-such-address-anywhere')
            ->assertDontSee('Not Found', false)
            ->assertDontSee('Sorry, the page you are looking for could not be found.', false);
    }

    /**
     * ⭐ পাতাটা কোনো stylesheet টানে না — আর সেটা ইচ্ছাকৃত।
     *
     * ⚠️ ৫০০-এর একটা সাধারণ কারণ বিল্ড বা স্টোরেজ ভেঙে যাওয়া। তখন
     * stylesheet টানলে পাতাটা **সাদা** আসত, আর ব্যবহারকারী কিছুই বুঝতেন
     * না — অর্থাৎ ভুলের পাতাটা ঠিক ভুলের দিনেই অকেজো।
     */
    public function test_it_does_not_depend_on_the_build(): void
    {
        $html = $this->get('/no-such-address-anywhere')->getContent();

        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
        $this->assertStringNotContainsString('/build/assets/', $html);
    }

    /**
     * প্রতিটা ত্রুটির পাতা সত্যিই আঁকা যায়, আর তার লেখাও আছে।
     *
     * ⚠️ ওগুলো সরাসরি খোলা যায় না (৫০০ ঘটাতে হয়), তাই ভিউটা নিজে
     * আঁকা হয় — তাতে অন্তত অনুপস্থিত চাবি বা ভাঙা ব্লেড ধরা পড়ে।
     */
    public function test_every_error_page_renders_with_words_in_it(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $html = view('errors.'.$code)->render();

            $this->assertStringContainsString((string) $code, $html, "{$code}: কোডটাই নেই।");
            $this->assertStringContainsString(__('core.error.title_'.$code), $html, "{$code}: শিরোনাম নেই।");
            $this->assertStringNotContainsString('core.error.', $html, "{$code}: চাবিটাই ছাপা হয়েছে।");
        }
    }

    /** ⓘ শুরুর বোতামটা `/`, রুটের নাম নয় — ভাঙা অবস্থায় `route()` নিজেই ভাঙতে পারে। */
    public function test_the_way_home_does_not_go_through_the_route_table(): void
    {
        $this->assertStringContainsString('href="/"', view('errors.404')->render());

        /* ধনাত্মক নিয়ন্ত্রণ: রুটের তালিকা সত্যিই আছে, তবু ওটা ব্যবহার করা হয়নি। */
        $this->assertGreaterThan(100, count(Route::getRoutes()->getRoutes()));
    }
}
