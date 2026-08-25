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
     * ভাষা ও থিমের শর্টকাট ছোট পর্দায় লুকানো থাকে।
     *
     * দুইটা মিলে ৯৮px — ঠিক যতটা কমালে প্রোফাইলের বোতামটা পর্দায় ফেরে।
     */
    public function test_the_two_shortcuts_step_aside_on_a_small_screen(): void
    {
        $topbar = (string) file_get_contents(
            resource_path('views/components/shell/topbar.blade.php')
        );

        $this->assertStringContainsString(
            'class="hidden xl:contents"',
            $topbar,
            'ভাষার সুইচটা আর ছোট পর্দায় লুকানো নেই — ৩৭৫px-এ প্রোফাইলের বোতাম আবার পর্দার বাইরে যাবে।',
        );

        $this->assertStringContainsString(
            '<span class="hidden xl:contents">',
            $topbar,
            'থিমের সুইচটা আর ছোট পর্দায় লুকানো নেই।',
        );
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
