<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⭐ ABOS Blue — বাইরের নীল ফ্রেম নেই (মালিক, ২৩ নভেম্বর ২০২৬, ছবিতে লাল বাক্সে: ডানের নীল পট্টি, নিচের নীল ফালি)।
 *
 * উপরের বার আর বাঁয়ের রেল-মেনু নীলই থাকে; কাজের জায়গা ডান কিনারা পর্যন্ত, নিচের বার পাতার সাধারণ জমিনে — ABOS স্মার্টের মতো।
 * ⛔ সব বদল কেবল `[data-ui='blue']`-এ — SAP, Dynamic আর বাকি রূপের একটা পিক্সেলও নড়ে না।
 */
class TheBlueLookHasNoOuterFrameTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/blue-look.css'));
    }

    /** CSS-এর মন্তব্য বাদ দিয়ে কেবল নিয়মগুলো। */
    private function rules(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $this->css());
    }

    public function test_the_outer_blue_frame_is_gone(): void
    {
        $rules = $this->rules();

        $this->assertStringNotContainsString('--blue-frame', $rules, '⛔ বাইরের নীল ফ্রেমের রং ফিরে এসেছে।');
        $this->assertDoesNotMatchRegularExpression('~--blue-ring:\s*+(?!0px)~', $rules, '⛔ ফ্রেমের চওড়া আবার ০-এর বেশি।');
        $this->assertStringNotContainsString('body::after', $rules, '⛔ নিচের বারের পেছনের নীল পট্টি ফিরে এসেছে।');
        $this->assertStringNotContainsString('body::before', $rules, '⛔ নিচের নীল ফালি ফিরে এসেছে।');
        // ⭐ নোটিশের বার গোল কোণার সাদা বাক্স (মালিক "ok", ২৪ নভেম্বর ২০২৬, নমুনা 8kuUTtFx v2)
        $this->assertMatchesRegularExpression('~footer\[data-footer\] \{[^}]*border-radius: 999px;~', $rules, '⛔ নিচের নোটিশের বার গোল বাক্স নয়।');
    }

    /** ⭐ অ্যাপের নীল পর্দার কিনারা পর্যন্ত, হালকা নীল ফ্রেম নেই (মালিক "ok", ২৪ নভেম্বর ২০২৬, নমুনা v2) */
    public function test_the_apps_blue_runs_to_the_edges_without_a_frame(): void
    {
        $this->assertMatchesRegularExpression(
            "~:root\[data-ui='blue'\] body \{\s*background: var\(--blue-shell\);\s*padding: 0;~",
            $this->rules(),
            '⛔ পাতার জমিন অ্যাপের নীল নয়, নয়তো চারপাশে ফাঁক।',
        );
    }

    /** প্রতিটা নিয়ম কেবল ABOS Blue-র — অন্য রূপে এই ফাইল কিছুই ছোঁয় না। */
    public function test_every_rule_is_scoped_to_the_blue_look(): void
    {
        preg_match_all('~([^{}]+)\{~', $this->rules(), $m);

        foreach ($m[1] as $selectorList) {
            $selectorList = trim($selectorList);

            if ($selectorList === '' || str_starts_with($selectorList, '@')) {
                continue;
            }

            foreach (explode(',', $selectorList) as $selector) {
                $this->assertStringContainsString("[data-ui='blue']", $selector, "⛔ রূপের বাইরে নিয়ম: {$selector}");
            }
        }
    }
}
