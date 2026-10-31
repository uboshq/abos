<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use Tests\TestCase;

/**
 * ⭐ খাতায় ওঠা কাগজ "পোস্ট" — বাংলা লেখায় "পাকা" নয় (মালিকের কথা fe হয়ে, ২৫ অক্টোবর ২০২৬; অ্যাপে a4-এর 339d24786-এর সাথে এক)।
 *
 * ⓘ ভাউচার আর বিল দুটোই: হিসাবের পর্দা, ফোন, উল্টো কাগজ, সংশোধন আর মাস বন্ধের বার্তায় যা দেখায় তা "পোস্ট"। কেবল হিসাব মডিউল —
 * অন্য মডিউলের মালিক নিজেরটা বদলান। ⓘ মন্তব্য গোনা হয় না: কেবল ফাইল যা ফেরায় (মানুষ যা পড়ে)।
 *
 * দাবি:
 *   ১. হিসাব মডিউলের কোনো বাংলা লেখায় "পাকা" নেই
 *   ২. যে লেখাগুলো আগে "পাকা" বলত, সেগুলো এখন "পোস্ট" বলে (খালি বা মুছে ফেলা লেখা দাবিটা পার হত না)
 */
final class APostedPaperSaysPostNotPakaTest extends TestCase
{
    /** ১ */
    public function test_no_accounts_bengali_string_says_paka(): void
    {
        $files = glob(base_path('app/Modules/Accounts/Resources/lang/bn/*.php')) ?: [];
        $this->assertNotEmpty($files, 'দৃশ্যটাই ঠিক নয় — হিসাব মডিউলের বাংলা লেখার ফাইল নেই');

        $found = [];
        foreach ($files as $file) {
            $strings = (array) require $file;
            array_walk_recursive($strings, function ($text, $key) use (&$found, $file): void {
                if (is_string($text) && str_contains($text, 'পাকা')) {
                    $found[] = basename($file, '.php').'.'.$key.' = '.$text;
                }
            });
        }

        $this->assertSame([], $found, '⛔ হিসাবের বাংলা লেখায় এখনো "পাকা" — খাতায় ওঠা কাগজ "পোস্ট": '.json_encode($found, JSON_UNESCAPED_UNICODE));
    }

    /** ২ */
    public function test_the_strings_that_said_paka_now_say_post(): void
    {
        foreach ([
            'accounts::phone_voucher.state_posted', 'accounts::phone_voucher.posted', 'accounts::reversal.not_posted',
            'accounts::revision.owned_elsewhere', 'accounts::validation.month_has_open_papers', 'accounts::validation.signed_elsewhere',
            'accounts::asset.funded_bill', 'accounts::asset.bill_line_missing', 'accounts::note.against_not_found', 'revision.switched_off',
        ] as $key) {
            $text = trans($key, [], 'bn');
            $this->assertStringContainsString('পোস্ট', $text, "⛔ {$key} \"পোস্ট\" বলে না: {$text}");
            $this->assertStringNotContainsString('পাকা', $text, "⛔ {$key} এখনো \"পাকা\" বলে: {$text}");
        }
    }
}
