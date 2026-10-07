<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⛔ Tailwind v3-এর `bg-[--x]` ছাঁচ কোনো ব্লেডে নেই।
 *
 * ── কী ভাঙা ছিল, ১৯ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * মালিক বললেন: *"Scan Crop Adjust হয় না"*। ⓘ আসল Chrome-এ চালিয়ে দেখা
 * গেল ক্রপের পর্দা **ঠিকই খোলে** — কিন্তু "এটাই রাখুন" বোতামটা প্রায়
 * অদৃশ্য: সাদা লেখা, পেছনে কোনো রং নেই।
 *
 * ⚠️ কারণ দুই স্তরের:
 *
 *   ১. পর্দাটা লেখা ছিল `bg-[--accent]`, `text-[--ink-soft]` ছাঁচে —
 *      Tailwind **v3**-এর লেখা। এই প্রকল্পে v4, আর v4-এ ভেরিয়েবল
 *      লেখা হয় `bg-(--x)`।
 *   ২. আর নামগুলো (`--accent`, `--surface-sunken`, `--ink-soft`, `--line`
 *      …) এই প্রকল্পে **কোনোদিন ছিলই না** — এখানে `--color-brand-500`,
 *      `--color-border` ইত্যাদি।
 *
 * ⛔ ফল: মালিক বোতামটা খুঁজে পেতেন না, মূল ছবিটাই জমা দিতেন, আর বলতেন
 * *"crop hoy na but upload hoy"*। ⓘ কোনো ভুলের বার্তা নেই — রং না
 * বসলে কিছুই ছোঁড়ে না, কেবল জিনিসটা দেখা যায় না।
 *
 * ── ⭐ কেন এটা একটা পাহারা ───────────────────────────────────────────
 * ভুলটা **নীরব** আর **চেহারায়**: টেস্ট সবুজ, পাতা ২০০, কেবল একটা
 * বোতাম অদৃশ্য। ⚠️ এই শ্রেণির ভুল কেবল চোখেই ধরা পড়ে, আর চোখ কেবল
 * মালিকের। তাই ছাঁচটাই নিষিদ্ধ — v4-এ ওটা কখনো কাজ করে না।
 */
final class NoColourNamesThatTailwindFourCannotReadTest extends TestCase
{
    /** ⓘ `bg-[--x]`, `hover:text-[--y]`, `border-[--z]/50` — সব রূপ। */
    private const PATTERN = '/[a-z:]+-\[--[a-z0-9-]+\]/';

    public function test_no_blade_uses_the_tailwind_three_variable_syntax(): void
    {
        $files = 0;
        $offenders = [];

        foreach ([resource_path('views'), base_path('app/Modules')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $files++;

                if (preg_match_all(self::PATTERN, $file->getContents(), $m) > 0) {
                    $short = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

                    foreach (array_unique($m[0]) as $class) {
                        $offenders[] = $short.'  →  '.$class;
                    }
                }
            }
        }

        /*
         * ⚠️ আগে দাবি করা হয় যে ফাইলগুলো সত্যিই পড়া হয়েছে — ⓘ খোঁজাটা
         * ভেঙে গেলে নিচের "শূন্য" দাবিটা নীরবে সবুজ থাকত, আর এই পাহারার
         * পুরো কাজটাই ঠিক ঐ ধরনের নীরবতা ঠেকানো।
         */
        $this->assertGreaterThan(200, $files, 'একটাও ব্লেড ফাইল পাওয়া গেল না — খোঁজাটা কি ভেঙেছে?');

        $this->assertSame([], $offenders, implode("\n", [
            'এই ক্লাসগুলো Tailwind v3-এর ছাঁচে — v4-এ কোনো রং বসে না, আর কেউ টেরও পায় না:',
            '',
            ...$offenders,
            '',
            'সারানোর উপায়: `bg-[--x]` → `bg-(--x)`, আর নামটা tokens.css-এ সত্যিই আছে',
            'কি না দেখে নাও — এই প্রকল্পে নামগুলো `--color-` দিয়ে শুরু।',
        ]));
    }

    /**
     * ⭐ আর পাহারাটা নিজে অন্ধ নয় — পুরনো ভাঙা লেখাটা সে ধরে।
     *
     * ⚠️ এই দাবিটা না থাকলে উপরেরটা "প্যাটার্ন ভুল লেখা, কিছুই মেলে না"
     * অবস্থাতেও সবুজ থাকত — ⓘ আজই একবার আমার একটা grep শূন্য বলেছিল
     * কেবল এস্কেপ ভুল ছিল বলে।
     */
    public function test_the_pattern_actually_catches_the_broken_form(): void
    {
        $broken = 'class="bg-[--accent] hover:bg-[--surface-hover] border-[--line]/50"';
        $fixed = 'class="bg-(--color-brand-500) hover:bg-(--color-surface-hover)"';

        $this->assertSame(3, preg_match_all(self::PATTERN, $broken), 'পাহারা পুরনো ছাঁচটাই চিনতে পারছে না।');
        $this->assertSame(0, preg_match_all(self::PATTERN, $fixed), 'পাহারা সঠিক v4 ছাঁচকেও ভুল ধরছে।');
    }
}
