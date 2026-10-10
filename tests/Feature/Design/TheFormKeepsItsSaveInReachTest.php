<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⭐ ফর্মের নিচের স্থির পট্টি — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"নিচে সবসময় দেখা যায় এমন পট্টিতে
 * 'বাতিল · সংরক্ষণ' ডানে"* ([[x-ui.form-actions]])।
 *
 * দাবি: পট্টিটা আঠালো (sticky), স্ট্যাটাস বারের ঠিক উপরে, ছাপায় নেই; ডানে "বাতিল" (দেওয়া ঠিকানায়) তারপর "সংরক্ষণ"
 * (জমা দেয়); বাড়তি বোতাম বাঁয়ে; "বাতিল" না দিলে আঁকা হয় না।
 */
final class TheFormKeepsItsSaveInReachTest extends TestCase
{
    public function test_the_bar_sticks_above_the_status_bar_with_cancel_then_save_on_the_right(): void
    {
        $html = Blade::render('<form><x-ui.form-actions cancel="/brands"><a href="#draft">খসড়া রাখুন</a></x-ui.form-actions></form>');

        $this->assertStringContainsString('data-form-actions', $html);
        $this->assertMatchesRegularExpression('/data-form-actions[^>]*class="[^"]*\bsticky\b/', $html, '⛔ পট্টিটা আঠালো নয় — সংরক্ষণ পর্দার নিচে হারাবে।');
        $this->assertStringContainsString('md:bottom-(--spacing-status-bar)', $html, '⛔ পট্টি নিচের স্ট্যাটাস বারের নিচে চাপা পড়বে।');
        $this->assertStringContainsString('print-hide', $html, '⛔ কাগজে "সংরক্ষণ" ছাপা হবে।');

        $cancel = strpos($html, 'href="/brands"');
        $save = strpos($html, 'type="submit"');
        $extra = strpos($html, 'খসড়া রাখুন');
        $this->assertNotFalse($cancel, '⛔ "বাতিল" নেই।');
        $this->assertNotFalse($save, '⛔ "সংরক্ষণ" জমা দেয় না।');
        $this->assertLessThan($cancel, $extra, '⛔ বাড়তি বোতাম ডানে — প্রধান কাজের জায়গায়।');
        $this->assertLessThan($save, $cancel, '⛔ "সংরক্ষণ" ডান প্রান্তে নয়।');
        $this->assertStringContainsString(e(__('core.action.save')), $html);
    }

    public function test_no_cancel_link_without_a_place_to_go(): void
    {
        $html = Blade::render('<form><x-ui.form-actions save="পাঠান" /></form>');

        $this->assertStringNotContainsString(e(__('core.action.cancel')), $html);
        $this->assertStringContainsString('পাঠান', $html, '⛔ নিজের দেওয়া "সংরক্ষণ"-এর নাম বসেনি।');
    }
}
