<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Models\ApprovalFlow;
use Tests\TestCase;

/**
 * ছকে লেখা ছিল "যত টাকার উপরে: ৫,০০০", অথচ ঠিক ৫,০০০-ও আটকাত।
 *
 * ── ⓘ লাইভে ধরা, ২৭ সেপ্টেম্বর (hp2-র QA, TCL) ─────────────────────
 * ইঞ্জিন মাপে `>=` ([[ApprovalFlow::appliesTo()]])। ⚠️ পর্দা বলত "উপরে",
 * অর্থাৎ `>`। যিনি ছক বসান তিনি ভাবতেন ৫,০০০-এর ভাউচার সই ছাড়াই যাবে —
 * অথচ সেটা আটকাত, আর কেন আটকাল তার কোনো ব্যাখ্যা পর্দায় ছিল না।
 *
 * ⭐ সিদ্ধান্ত (সমন্বয়ক, মালিকের "যেকোনো অঙ্কে সই" নিয়মের সাথে মিলিয়ে):
 * আচরণ `>=` থাকে, লেখাটা বদলায়। ⛔ তাই এই দাবি দুইটা জিনিস একসাথে বাঁধে —
 * সীমানার অঙ্কটা ধরা পড়ে, আর দুই ভাষার লেখা সেটাই বলে। ⓘ একটা বদলালে
 * অন্যটাও বদলাতে হবে, নাহলে লাল।
 */
final class TheFlowSaidAboveButCaughtTheExactAmountTest extends TestCase
{
    private function flowFrom(string $threshold): ApprovalFlow
    {
        return new ApprovalFlow(['threshold_amount' => $threshold]);
    }

    /** ⭐ ঠিক সীমানার অঙ্কটা আটকায়; এক পয়সা কম আটকায় না। */
    public function test_the_exact_threshold_is_caught_and_one_paisa_less_is_not(): void
    {
        $flow = $this->flowFrom('5000');

        $this->assertTrue($flow->appliesTo('5000'), 'ঠিক ৫,০০০ ছকে ধরা পড়েনি।');
        $this->assertTrue($flow->appliesTo('5000.01'));
        $this->assertFalse($flow->appliesTo('4999.99'), 'সীমার নিচের অঙ্কও ছকে ধরা পড়েছে।');
    }

    /**
     * ⛔ পর্দার লেখা "উপরে" বলে না — "বা তার বেশি" বলে, দুই ভাষাতেই।
     *
     * ⚠️ বিপজ্জনক ইনপুট: পুরনো লেখা "যত টাকার উপরে" / "Above this amount"।
     */
    public function test_the_label_says_the_same_thing_the_engine_does(): void
    {
        $bn = __('approval::field.threshold', [], 'bn');
        $en = __('approval::field.threshold', [], 'en');

        $this->assertStringContainsString('বা তার বেশি', $bn, 'বাংলা লেখা এখনো "উপরে" বলছে: '.$bn);
        $this->assertStringNotContainsString('উপরে', $bn);

        $this->assertStringContainsStringIgnoringCase('or more', $en, 'ইংরেজি লেখা এখনো "Above" বলছে: '.$en);
        $this->assertStringNotContainsStringIgnoringCase('above', $en);
    }
}
