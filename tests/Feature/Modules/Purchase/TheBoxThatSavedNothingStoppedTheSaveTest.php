<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * যে ঘরটা কিছুই সংরক্ষণ করে না, সে সংরক্ষণটাই আটকে দিয়েছিল।
 *
 * ── ⛔ কী ঘটেছিল, ১৮ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * মালিক "নতুন বিল" পর্দার ছবি পাঠালেন: markup ঘরে `4.0042`, আর ব্রাউজার
 * লাল বার্তা দিচ্ছে — *"Please enter a valid value. The two nearest valid
 * values are 4 and 4.01"*। ⛔ ফর্মটা সেভই হত না।
 *
 * ── ⓘ অঙ্কটা ভুল ছিল না ─────────────────────────────────────────────
 * [[resources/js/pricing]] ইচ্ছাকৃতভাবে **চার দশমিক** রাখে, আর কারণটা
 * ওই ফাইলেই মাপা আছে: দুই দশমিকে নোঙর বসালে পরে ক্রয়দর বদলালে দাম
 * তিনশো টাকা পর্যন্ত কম বসত — কোনো ত্রুটিবার্তা ছাড়াই।
 *
 * ⚠️ ভুলটা ছিল ঘরের নিয়মে: `step="0.01"`। অঙ্ক চার দশমিক দেয়, আর ঘর
 * দুই দশমিকের বেশি নেয় না — দুইজন দুই কথা বলত।
 *
 * ── ⭐ আর সবচেয়ে বড় কথা ──────────────────────────────────────────────
 * markup ও margin ঘর দুইটার **`name` নেই** (পণ্যের ফর্মে নামটা আছে,
 * কিন্তু `ProductRequest` ওটা চেনে না বলে `validated()` ছেঁটে ফেলে)।
 * ⓘ অর্থাৎ ওরা জানালা, তথ্য নয়।
 *
 * ⛔ **যে ঘর সার্ভারে কিছু পাঠায় না, সে সংরক্ষণ আটকাতেও পারে না** —
 * এটাই নিয়ম, আর এই পরীক্ষাটা সেটাই পাহারা দেয়।
 *
 * ── ⓘ কেন উৎস পড়া হয়, পর্দা আঁকা নয় ─────────────────────────────────
 * তিনটা পর্দার তিন রকম অনুমতি, ডেটা আর অবস্থা লাগে (ক্রয়দর দেখার
 * অনুমতি না থাকলে ঘর দুইটা আঁকাই হয় না)। ⚠️ দাবিটা পর্দার অবস্থার উপর
 * নির্ভর করে না — ঘরের নিয়মটা উৎসেই লেখা, আর সেখানেই ভাঙে।
 */
final class TheBoxThatSavedNothingStoppedTheSaveTest extends TestCase
{
    /**
     * যে ফাইলগুলোয় markup/margin ঘর আছে।
     *
     * ⚠️ তালিকাটা হাতে লেখা ইচ্ছাকৃতভাবে: নতুন কোথাও ঘরটা বসালে এই
     * তালিকাতেও বসাতে হবে, আর সেই বাধ্যবাধকতাটাই মনে করিয়ে দেয় যে
     * `step`-টা ভেবে বসাতে হয়।
     *
     * @var list<string>
     */
    private const SCREENS = [
        'app/Modules/Purchase/Resources/views/components/line-editor.blade.php',
        'app/Modules/Purchase/Resources/views/direct/index.blade.php',
        'app/Modules/Inventory/Resources/views/product/form.blade.php',
    ];

    /**
     * ⭐ markup ও margin-এর কোনো ঘরে `step="0.01"` নেই।
     */
    public function test_no_percent_box_is_narrower_than_the_maths_that_fills_it(): void
    {
        $offenders = [];

        foreach (self::SCREENS as $path) {
            $source = File::get(base_path($path));

            /*
             * ⓘ প্রতিটা `<input` বা `<x-ui.field` ট্যাগ আলাদা করে দেখা —
             * একটা ফাইলে দশটা ঘর থাকে, আর কেবল দুইটা শতাংশের।
             */
            preg_match_all('/<(?:input|x-ui\.field)\b[^>]*>/s', $source, $tags);

            foreach ($tags[0] as $tag) {
                if (! preg_match('/\b(markup|margin)/', $tag)) {
                    continue;
                }

                if (str_contains($tag, 'step="any"')) {
                    continue;
                }

                $offenders[] = $path.' → '.trim(preg_replace('/\s+/', ' ', mb_substr($tag, 0, 120)));
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'শতাংশের ঘর অঙ্কের চেয়ে সরু — ব্রাউজার সংরক্ষণ আটকাবে:',
            '',
            '⛔ pricing.js চার দশমিক দেয় (আর সেটা ইচ্ছাকৃত, কারণ ওই ফাইলে লেখা)।',
            '⚠️ ঘরে step="0.01" থাকলে ব্রাউজার বলে "The two nearest valid values are…"',
            '⭐ শতাংশের ঘরে step="any" — ওগুলো জানালা, সংরক্ষিত তথ্য নয়।',
            '',
            ...$offenders,
        ]));
    }

    /**
     * ⚠️ তালিকাটা খালি হয়ে গেলে উপরের দাবিটা চিরকাল সবুজ থাকত।
     */
    public function test_the_list_of_screens_still_has_those_boxes(): void
    {
        foreach (self::SCREENS as $path) {
            $this->assertStringContainsString('markup', File::get(base_path($path)),
                $path.'-এ markup ঘরটা আর নেই — তালিকাটা হালনাগাদ করুন।');
        }
    }
}
