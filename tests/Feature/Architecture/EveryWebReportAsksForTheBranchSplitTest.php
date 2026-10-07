<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * প্রতিটা ওয়েব রিপোর্ট-কন্ট্রোলার শাখা ধরে ভাগ চায় — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ মালিকের নির্দেশ "সব রিপোর্ট"; ভাগ হবে কি না তা ইঞ্জিন ঠিক করে ("সব শাখা",
 * নাগালে একাধিক শাখা, রিপোর্ট ভাগযোগ্য)। ⛔ কন্ট্রোলার `byBranch: true` না দিলে
 * ঐ রিপোর্ট নীরবে ভাগ ছাড়াই থাকত — আর নতুন রিপোর্ট-পর্দা ঠিক এভাবেই আসে। তালিকা
 * হাতে লেখা নয়: `$this->reports->run(` ডাকে এমন প্রতিটা কন্ট্রোলার খুঁজে দেখা হয়।
 * ফোন আর ড্যাশবোর্ড ইচ্ছা করে বাইরে (ওরা সমতল সারি চায়)।
 */
final class EveryWebReportAsksForTheBranchSplitTest extends TestCase
{
    public function test_every_web_report_controller_asks_for_the_split(): void
    {
        $files = glob(base_path('app/Modules/*/Http/Controllers/*.php')) ?: [];
        $calls = 0;
        $missing = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $runs = substr_count($source, '$this->reports->run(');

            if ($runs === 0) {
                continue;
            }

            $calls += $runs;

            if (substr_count($source, 'byBranch: true') < $runs) {
                $missing[] = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
            }
        }

        $this->assertGreaterThan(10, $calls, 'খোঁজাটাই ভেঙেছে — রিপোর্টের ডাক মাত্র '.$calls.'টা।');
        $this->assertSame([], $missing, "এই রিপোর্ট-পর্দা শাখা ধরে ভাগ চায় না (byBranch: true):\n".implode("\n", $missing));
    }
}
