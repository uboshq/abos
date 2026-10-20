<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Tests\TestCase;

/**
 * ফোনে প্রোফাইলের বোতামটা পর্দার বাইরে ছিল।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ৩৭৫px-এ টপবারের ডান দিকের ঝাঁক ৩৪৭px চওড়া হয়ে **৪২৮** পর্যন্ত যেত।
 * পর্দা ৩৭৫। ফলে সবচেয়ে ডানের বোতামটা — প্রোফাইল — শুরুই হত ৩৮৪-এ,
 * অর্থাৎ **পুরোপুরি পর্দার বাইরে**।
 *
 * প্রোফাইল মেনুতেই লগআউট, চেহারা, নিরাপত্তা ও সেশন। তাই ফোনে ওগুলোর
 * কোনোটাতেই পৌঁছানো যেত না, আর সাথে প্রতিটা পাতা পাশে গড়াত। ৭৬৮px-এও
 * একই — ওখানে কোম্পানি-সুইচারটাও দেখা যায় বলে জায়গা আরও কম।
 *
 * ধরা পড়েছে ২৫ আগস্ট ২০২৬, লাইভের পর্দাগুলো ৩৭৫ ও ৭৬৮-তে মেপে।
 *
 * ── কেন কোনো পরীক্ষা এটা ধরেনি, আর এই পরীক্ষাটাও পুরোটা ধরে না ────────
 * প্রস্থ মাপতে হলে সত্যিকারের একটা ব্রাউজার লাগে — PHPUnit HTML পড়ে,
 * লেআউট আঁকে না। তাই মাপার কাজটা ব্রাউজারেই হয়েছে, হাতে।
 *
 * এই ফাইলটা তার বদলি নয়, তার **স্মৃতি**: সিদ্ধান্ত দুইটা লিখে রাখে,
 * যাতে পরে কেউ না জেনে ওগুলো তুলে না দেন। যা দাবি করা হচ্ছে তা-ই
 * পরীক্ষা করা হচ্ছে — বেশি নয়।
 */
class TheProfileButtonWasOffTheEdgeOfThePhoneTest extends TestCase
{
    /**
     * ভাষা ও থিমের শর্টকাট ছোট পর্দায় লুকানো থাকে — **১২৮০px-এর নিচে**।
     *
     * ── কেন সংখ্যাটা নাম ধরে নয় ──────────────────────────────────────
     * প্রথমে `xl:` লেখা হয়েছিল, ১২৮০ ভেবে। কিন্তু এই প্রকল্পে
     * `--breakpoint-xl` = **১৪৪০px**। ফলে সুইচ দুইটা ১৪৪০-এর নিচে
     * সবখানে হারিয়ে গিয়েছিল — **১৩৬৬×৭৬৮-এও**, সবচেয়ে সাধারণ
     * ল্যাপটপের মাপে। মালিক ধরেছেন "থিম বদলায় না" বলে; আসলে বোতামটাই
     * ছিল না।
     *
     * ১২৮০ মাপা মান: ওই প্রস্থেই উপচে পড়া প্রথম শূন্য হয়।
     */
    public function test_the_two_shortcuts_step_aside_on_a_small_screen(): void
    {
        /*
         * ⭐ ২০ অক্টোবর ২০২৬ থেকে (ওয়েবের মান ধাপ ২, SAP Fiori shell bar, মালিকের অনুমোদিত নমুনা): ভাষা আর রঙের সুইচ বারে
         * কোনো মাপেই নেই — ছবির মেনুতে। ⓘ দাবির উদ্দেশ্য একই, আরও কড়া: ডানের ঝাঁকে কেবল ＋ তৈরি · অনুমোদন · 🔔 · ছবি, তাই
         * ৩৭৫px-এও প্রোফাইলের বোতাম পর্দার ভেতরে; ১২৮০-র নিচে লুকানোর ফাঁদও আর নেই (কারণ ওরা বারেই নেই)।
         */
        $topbar = (string) file_get_contents(
            resource_path('views/components/shell/topbar.blade.php')
        );
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $topbar) ?? $topbar;

        $this->assertStringNotContainsString("route('locale.switch')", $code,
            '⛔ ভাষার সুইচ আবার বারে — ৩৭৫px-এ প্রোফাইলের বোতাম পর্দার বাইরে যাবে।');
        $this->assertStringNotContainsString('theme-toggle', $code, '⛔ রঙের সুইচ আবার বারে।');
        $this->assertStringNotContainsString('fullscreen-toggle', $code, '⛔ পূর্ণপর্দার বোতাম আবার বারে।');

        $actions = substr($code, (int) strpos($code, 'data-shell-actions'));
        $actions = substr($actions, 0, (int) strpos($actions, '</div>'));
        preg_match_all('/<x-shell\.([a-z-]+)/', $actions, $parts);
        $this->assertSame(['create-menu', 'approval-quick-access', 'notification-bell', 'profile-menu'], $parts[1],
            '⛔ ডানের ঝাঁকে বাড়তি কিছু — ছোট পর্দায় প্রোফাইলের বোতাম আবার ঠেলে বাইরে যেতে পারে।');

        $menu = (string) file_get_contents(resource_path('views/components/shell/profile-menu.blade.php'));
        $this->assertStringContainsString('data-menu-language', $menu, 'ভাষার সুইচ ছবির মেনুতেও নেই — তাহলে কোথাও নেই।');
        $this->assertStringContainsString('data-menu-theme', $menu, 'রঙের সুইচ ছবির মেনুতেও নেই।');
    }

    /**
     * লুকানো শর্টকাটগুলোর একটা ঘর আছে, আর সেখানে পৌঁছানো যায়।
     *
     * ── কেন এটাই আসল দাবি ────────────────────────────────────────────
     * উপরের পরীক্ষাটা বলে "বোতাম দুইটা ছোট পর্দায় নেই"। সেটা একা
     * থাকলে কেউ ভাবতে পারতেন ফোনে ভাষা বদলানোই যায় না।
     *
     * যায় — চেহারার পাতায়, আর সেই পাতার লিংক প্রোফাইল মেনুতে। এই
     * দুইটা একসাথে না থাকলে "লুকানো" আর "মুছে ফেলা" এক হয়ে যেত।
     */
    public function test_what_was_hidden_still_has_a_home(): void
    {
        $menu = (string) file_get_contents(
            resource_path('views/components/shell/profile-menu.blade.php')
        );

        $this->assertStringContainsString("route('appearance')", $menu,
            'প্রোফাইল মেনুতে চেহারার লিংক নেই — তাহলে ফোনে ভাষা ও থিম বদলানোর কোনো পথ থাকে না।');

        $this->assertStringContainsString("route('logout')", $menu);

        // আর চেহারার পাতায় দুইটাই সত্যিই আছে
        $appearance = (string) file_get_contents(
            resource_path('views/workspace/appearance.blade.php')
        );

        $this->assertStringContainsString('name="locale"', $appearance);
        $this->assertStringContainsString('name="theme"', $appearance);
    }
}
