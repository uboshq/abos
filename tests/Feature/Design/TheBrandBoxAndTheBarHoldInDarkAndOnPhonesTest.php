<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Tests\TestCase;

/**
 * ওয়েবের নতুন চেহারার লাইভ-পরখে চোখে ধরা দুই ভুল (৫-পাতার পরখ, ২১ অক্টোবর ২০২৬; স্বয়ংক্রিয় ৯০টা যাচাই সবুজ ছিল, ছবিতে ধরা):
 *
 * ১) অন্ধকারে টাকার বাক্স: বাক্সের জমিন brand-700→900 আর লেখা ink-inverse — অথচ গাঢ় থিমে brand-700 = হালকা অ্যাকসেন্ট (লেখার রং
 *    হিসেবে) আর ink-inverse = গাঢ়, তাই অঙ্কগুলো হালকা নীলে কালো লেখা। ⭐ এখন দুই বাক্স থিম-স্থির টোকেনে: --color-on-brand-ink আর
 *    --color-on-brand-from/to, যা গাঢ় থিম আবার লেখে না।
 * ২) ৩৭৫px-এ "＋ তৈরি করুন" পুরো লেখায় বসত আর কোম্পানির নাম ঢাকত: স্তরের বাইরের `.topbar .topbar-label { display: inline }`
 *    Tailwind-এর `hidden sm:inline`-কে হারাত। ⭐ এখন sm-এর নিচে একই নিয়মেই লুকানো, আর কোম্পানির নাম কাটতে পারে (min-w-0)।
 *
 * ⓘ মাপ আর রং ব্রাউজারে দেখা (livecheck.py-র ছবি); এটা কোডের স্থায়ী পাহারা — কেউ বাক্সকে আবার থিম-নির্ভর রঙে ফেরালে লাল।
 */
final class TheBrandBoxAndTheBarHoldInDarkAndOnPhonesTest extends TestCase
{
    public function test_the_brand_boxes_wear_theme_fixed_tokens_the_dark_theme_does_not_redefine(): void
    {
        foreach (['views/workspace/home.blade.php' => 'data-money-position', 'views/components/dashboard/hero-listing.blade.php' => 'data-hero-listing'] as $file => $mark) {
            $source = (string) file_get_contents(resource_path($file));
            $box = substr($source, (int) strpos($source, $mark), 600);

            $this->assertStringContainsString('text-(--color-on-brand-ink)', $box, "⛔ {$file}: বাক্সের লেখা থিম-স্থির কালিতে নয়।");
            $this->assertStringContainsString('var(--color-on-brand-from), var(--color-on-brand-to)', $box, "⛔ {$file}: বাক্সের জমিন থিম-স্থির নয়।");
            $this->assertStringNotContainsString('color-ink-inverse', $box, "⛔ {$file}: বাক্স আবার ink-inverse নিল — অন্ধকারে গাঢ় লেখা।");
            $this->assertStringNotContainsString('var(--color-brand-700), var(--color-brand-900)', $box, "⛔ {$file}: বাক্স আবার brand-700 নিল — অন্ধকারে হালকা জমিন।");
        }

        $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
        foreach (['--color-on-brand-ink', '--color-on-brand-from', '--color-on-brand-to'] as $token) {
            $this->assertSame(1, substr_count($tokens, $token.':'), "⛔ {$token} একবারই ঘোষিত হওয়ার কথা — গাঢ় থিম আবার লিখলে বাক্স অন্ধকারে বদলায়।");
        }
    }

    public function test_on_a_phone_the_create_button_is_an_icon_and_the_company_name_can_shrink(): void
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', (string) file_get_contents(resource_path('css/app.css'))) ?? '';
        $this->assertMatchesRegularExpression('/@media\s*\(width\s*<\s*40rem\)\s*\{\s*\.topbar\s+\.topbar-label\s*\{\s*display:\s*none;/', $css,
            '⛔ sm-এর নিচে "＋ তৈরি"-র লেখা লুকানোর নিয়ম নেই — ৩৭৫px-এ কোম্পানির নাম ঢাকা পড়বে।');

        $switcher = (string) file_get_contents(resource_path('views/components/shell/company-switcher.blade.php'));
        $this->assertMatchesRegularExpression('/class="relative min-w-0" data-company-branch/', $switcher, '⛔ কোম্পানির মোড়ক কাটতে পারে না।');
        $this->assertStringContainsString("'flex min-h-(--spacing-touch) min-w-0 max-w-full items-center", $switcher, '⛔ কোম্পানির বোতাম কাটতে পারে না — নাম পাশের বোতামের নিচে গড়াবে।');
    }
}
