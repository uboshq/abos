<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Contracts\Drillable;
use App\Core\Engines\Drill\DrillResolver;
use Tests\TestCase;

/**
 * নামটা মানচিত্রে বসানো হলো, অথচ ক্লাসটা ড্রিল করা যায় না।
 *
 * ── ⛔ কী ঘটেছিল, ১৪ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * মূলধনের সারি রসিদ পোস্ট হলে নিষ্পন্ন হবে — এটা বানাতে গিয়ে
 * `Finance/module.php`-এর `drill_sources`-এ `capital_entry` বসানো হলো,
 * কারণ [[App\Core\Contracts\SettledByAVoucher]]-এর হুক ঐ মানচিত্র ধরেই
 * ক্লাস খোঁজে। কাজ করেছে, আর **লাইভে চলেও গেছে**।
 *
 * ⚠️ কিন্তু একই মানচিত্র [[DrillResolver::resolve()]]-ও পড়ে, আর সে
 * `Drillable` না পেলে ব্যতিক্রম ছোঁড়ে। ⓘ অর্থাৎ একটা নাম দুইটা কাজে
 * লাগে, আর একটার শর্ত পূরণ করলেই অন্যটার শর্ত পূরণ হয় না।
 *
 * ── ⭐ কেন এই ভুলটা নীরব ─────────────────────────────────────────────
 * নিষ্পত্তির পথটা ভাঙে না — সে `map()` পড়ে, `resolve()` নয়। তাই টাকা
 * ঠিকই খাতায় বসে, সব সবুজ দেখায়, আর ভুলটা ধরা পড়ে **মাস পরে**, যেদিন
 * কেউ খতিয়ানের একটা সারি থেকে নথিতে ফিরতে চান। ⛔ একটা ৫০০, আর কারণটা
 * ঐ দিনের কোনো কাজের সাথে মেলে না।
 *
 * ── ⓘ কেন `EveryDocumentGetsANumberTest` এটা ধরেনি ───────────────────
 * ওটা জিজ্ঞেস করে "এই মডেলের নম্বর আছে কি না"। এটা জিজ্ঞেস করে "যে নাম
 * মানচিত্রে বসানো হয়েছে, সে সত্যিই ড্রিল করা যায় কি না" — ⚠️ উল্টো
 * দিক থেকে, আর ঐ দিকটাই ফাঁকা ছিল।
 */
final class EveryDrillSourceCanActuallyBeDrilledIntoTest extends TestCase
{
    /**
     * ⛔ মানচিত্রের প্রতিটা ক্লাস `Drillable`।
     */
    public function test_every_registered_source_implements_drillable(): void
    {
        $map = app(DrillResolver::class)->map();

        /*
         * ⚠️ শূন্য মানচিত্রে দাবিটা সবসময় সবুজ। ⓘ মডিউল খোঁজা ভেঙে গেলে
         * বা কেউ `drill_sources` ব্লকের নাম বদলালে নিচের লুপটা কিছুই
         * ঘুরত না, আর পাহারাটা অলংকার হয়ে যেত।
         */
        $this->assertGreaterThan(30, count($map),
            'ড্রিল মানচিত্র প্রায় খালি — মডিউল খোঁজাটা কি আর কাজ করছে?');

        $broken = [];

        foreach ($map as $sourceType => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                $broken[] = "{$sourceType} → ক্লাসটাই নেই: ".var_export($class, true);

                continue;
            }

            if (! is_subclass_of($class, Drillable::class)) {
                $broken[] = "{$sourceType} → {$class}";
            }
        }

        sort($broken);

        $this->assertSame([], $broken, implode("\n", [
            'এই নামগুলো `drill_sources`-এ বসানো, অথচ ক্লাসগুলো Drillable নয়:',
            '',
            '⛔ DrillResolver::resolve() এদের জন্য ব্যতিক্রম ছুঁড়বে — অর্থাৎ',
            'খতিয়ানের সারি থেকে নথিতে ফিরতে গেলে পাতাটা ৫০০ দেবে।',
            '',
            '⚠️ আর ভুলটা নীরব: নিষ্পত্তির হুক map() পড়ে, resolve() নয়।',
            'তাই টাকা ঠিকই বসবে, আর ভুলটা ধরা পড়বে মাস পরে।',
            '',
            'সারাই: ক্লাসে `implements Drillable` আর চারটা পদ্ধতি —',
            'drillSourceType() · drillDocumentNo() · drillLabel() · drillRoute().',
            '',
            ...$broken,
        ]));
    }

    /**
     * ⭐ যে নামটা মানচিত্রে লেখা, ক্লাসও সেই নামটাই বলে।
     *
     * ⓘ দুইটা আলাদা হলে `resolve()` ক্লাসটা খুঁজে পেত, কিন্তু খতিয়ারের
     * সারিতে বসা `source_type` আর মানচিত্রের চাবি মিলত না — আর তখন
     * লিংকটা **কখনো তৈরিই হত না**, কোনো ত্রুটি ছাড়াই।
     */
    public function test_the_name_in_the_map_is_the_name_the_class_claims(): void
    {
        $mismatched = [];

        foreach (app(DrillResolver::class)->map() as $sourceType => $class) {
            if (! is_string($class) || ! is_subclass_of($class, Drillable::class)) {
                continue; // উপরের দাবিটা এটা আলাদা করে ধরে
            }

            $claims = $class::drillSourceType();

            /*
             * ⓘ ভাউচার ইচ্ছাকৃত ব্যতিক্রম: পাঁচটা ধরনের পাঁচটা
             * `source_type` (receipt_voucher, contra_voucher…), আর
             * ক্লাসটা একটাই। তার নিজের মন্তব্যে কারণ লেখা আছে।
             */
            if ($claims === 'voucher') {
                continue;
            }

            if ($claims !== $sourceType) {
                $mismatched[] = "মানচিত্রে '{$sourceType}', ক্লাস বলে '{$claims}' — {$class}";
            }
        }

        sort($mismatched);

        $this->assertSame([], $mismatched, implode("\n", [
            'মানচিত্রের নাম আর ক্লাসের নিজের নাম আলাদা:',
            '',
            '⚠️ লিংকটা কখনো তৈরি হবে না, আর কোনো ত্রুটিও আসবে না।',
            '',
            ...$mismatched,
        ]));
    }
}
