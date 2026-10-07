<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use PHPUnit\Framework\TestCase;

/**
 * গাঢ় থিমে সাদা বাক্স আর ডুবে যাওয়া লেখা — মালিক, ৭ অক্টোবর ২০২৬: *"ডার্ক মোডে প্রতিটা পর্দায় কিছু না কিছু সমস্যা —
 * কিছু জায়গায় সাদা বাক্স, লেখাও সাদা, কিছু বোঝা যায় না"*।
 *
 * দাবি: পর্দায় জমিন বা লেখা হিসেবে যে রংগুলো চলে, তাদের প্রত্যেকের গাঢ় থিমে নিজের মান আছে — নাহলে হালকা থিমের
 * হালকা জমিন গাঢ় পাতায় সাদা বাক্স হয়ে থাকে আর গাঢ় লেখা কালো জমিনে ডোবে।
 */
final class TheDarkThemeLeftWhiteBoxesTest extends TestCase
{
    /** পর্দায় সবচেয়ে বেশি চলা জমিন ও লেখার রং — ৭ অক্টোবর গোনা (danger ১৬৩, brand-700 ৯৫, brand-50 ২১ …) */
    private const MUST_TURN_DARK = [
        '--color-brand-50', '--color-brand-100', '--color-brand-400', '--color-brand-700',
        '--color-success', '--color-danger', '--color-info', '--color-ink-faint',
    ];

    public function test_every_light_background_and_dark_ink_has_a_dark_value(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/tokens.css');

        preg_match_all("/:root\\[data-theme='dark'\\][^{]*\\{(.*?)\\n\\}/s", $css, $blocks);
        $dark = implode("\n", $blocks[1]);

        foreach (self::MUST_TURN_DARK as $token) {
            $this->assertMatchesRegularExpression('/'.preg_quote($token, '/').'\s*:/', $dark, "⛔ {$token}-এর গাঢ় থিমের মান নেই — গাঢ় পাতায় সাদা বাক্স বা ডুবে যাওয়া লেখা।");
        }

        // ⓘ ব্যবহার হওয়া কিন্তু কোথাও সংজ্ঞা না থাকা রং কিছুই আঁকে না
        foreach (['--color-brand-400', '--color-ink-faint', '--color-accent-600', '--color-accent-ink'] as $token) {
            $this->assertMatchesRegularExpression('/^\s*'.preg_quote($token, '/').'\s*:/m', $css, "⛔ {$token}-এর সংজ্ঞাই নেই।");
        }
    }
}
