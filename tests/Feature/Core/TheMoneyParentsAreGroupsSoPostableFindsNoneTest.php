<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * টাকার মা-খাতগুলো দল — তাই `postable()` দিয়ে খুঁজলে একটাও মেলে না।
 *
 * ── ⛔ কী ঘটেছিল, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * মালিক সরাসরি ক্রয়ের পর্দায় পরিশোধ যোগ করতে গেলেন, আর "Paid from" ঘরটা
 * খালি — পাশে লেখা *"এই ধরনের কোনো খাত এখনো বসানো নেই"*। ⓘ তিনি সেটা
 * বিশ্বাস করে একটা ব্যাংক খাত বসাতে বললেন।
 *
 * ⚠️ কিন্তু ব্যাংক খাত বসানোর পরেও তালিকা খালিই থাকত। কারণ কোয়েরিটা
 * মা-খাত খুঁজত এভাবে:
 *
 *     Account::query()->postable()->whereIn('code', MONEY_PARENTS)
 *
 * `postable()` মানে `is_group = false`, আর টাকার তিন মা — ১১০১ নগদ ·
 * ১১০২ ব্যাংক · ১১০৫ MFS — **তিনটাই দল**। ⛔ অর্থাৎ মা খোঁজা শূন্য ফেরাত,
 * শূন্য মায়ের সন্তানও শূন্য, আর তালিকা প্রতিটা উপায়ে খালি — নগদেও,
 * অথচ প্রধান কাউন্টার শুরু থেকেই ছিল।
 *
 * ⓘ লাইভে মাপা: আজকের কোডে তালিকা ০টা, ঠিক কোডে ৮টা।
 *
 * ── ⭐ কেন উৎস পড়া হয় ───────────────────────────────────────────────
 * ভুলটা একটা ধাঁচের — একই লাইন তিনটা কন্ট্রোলারে লেখা ছিল, আর দুইটায়
 * ঠিক এই ভুল। ⚠️ নতুন কোনো পর্দা টাকার খাত চাইলে একই লাইন আবার লেখা
 * হবে, তাই পাহারাটা ধাঁচটাকেই খোঁজে, একটা নির্দিষ্ট পর্দাকে নয়।
 */
final class TheMoneyParentsAreGroupsSoPostableFindsNoneTest extends TestCase
{
    public function test_nobody_looks_for_the_money_parents_among_postable_accounts(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = $file->getContents();

            /*
             * ⓘ `postable()` আর `MONEY_PARENTS` একই কোয়েরিতে, মাঝে কেবল
             * অন্য শর্ত — কিন্তু কোনো `;` নয় (নতুন বাক্য শুরু হলে ওটা আলাদা
             * কোয়েরি, আর সেখানে দুইটা একসাথে থাকা বৈধ)।
             */
            if (preg_match_all('/->postable\(\)[^;]{0,120}?whereIn\(\s*\'code\'\s*,\s*StandardChart::MONEY_PARENTS/s', $source, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [, $offset]) {
                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.$line;
                }
            }
        }

        /*
         * ⚠️ বিক্রয়ের আদায় (`CollectionController`) একই দুইটা শব্দ ব্যবহার
         * করে, কিন্তু সন্তানদের আনে একটা আলাদা `orWhereIn`-এ, যেখানে
         * `postable()` খাটে না — তাই ওটা কাজ করে। ⓘ ওটা ছাড় দেওয়া হলো,
         * নাম ধরে, যাতে ছাড়টা চোখে পড়ে।
         */
        $offenders = array_values(array_filter(
            $offenders,
            fn (string $o) => ! str_contains($o, 'CollectionController'),
        ));

        $this->assertSame([], $offenders, implode("\n", [
            'টাকার মা-খাত খোঁজা হচ্ছে postable() দিয়ে — কিন্তু মা-খাতগুলো দল:',
            '',
            '⛔ ১১০১ · ১১০২ · ১১০৫ তিনটাই is_group = true, তাই postable() শূন্য ফেরায়,',
            '   আর "Paid from" তালিকা প্রতিটা উপায়ে খালি থাকে — কোনো ভুল বার্তা ছাড়াই।',
            '⭐ মা খোঁজায় postable() বাদ দিন; দল বাদ পড়ুক নিচের is_group = false-এ।',
            '',
            ...$offenders,
        ]));
    }
}
