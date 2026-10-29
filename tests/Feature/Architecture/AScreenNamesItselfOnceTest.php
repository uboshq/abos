<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⛔ একই পাতায় দুটো মূল শিরোনাম — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ ([[PageHeading]], [[EveryScreenHasExactlyOneNameTest]])।
 *
 * <p>নিরীক্ষায় ১৪টা পাতায় দুটো h1: page-header আর toolbar দুজনেই নিজের শিরোনাম h1-এ আঁকত। এখন দুজনেই ট্যাগ চায়
 * [[PageHeading::tag()]]-এর কাছে — প্রথমজন h1, পরেরজন h2। এই দাবি ছাঁচটা পাহারা দেয়, যাতে আবার না ফেরে:
 *  - শিরোনামের দুই উপাদানে হাতে লেখা `<h1` নেই — ট্যাগ আসে [[PageHeading::tag()]] থেকে;
 *  - কোনো ব্লেডে হাতে লেখা `<h1` আর ঐ উপাদানের শিরোনাম একসাথে নেই;
 *  - কোনো ব্লেডে হাতে লেখা `<h1` একবারের বেশি নেই।
 */
final class AScreenNamesItselfOnceTest extends TestCase
{
    public function test_heading_components_ask_for_their_tag(): void
    {
        foreach (['resources/views/components/ui/page-header.blade.php', 'resources/views/components/ui/toolbar.blade.php'] as $file) {
            $source = self::withoutComments((string) file_get_contents(base_path($file)));

            $this->assertSame(0, preg_match_all('/<h1[\s>]/', $source), "⛔ {$file}-এ হাতে লেখা h1 — পাতায় দুটো মূল শিরোনাম হতে পারে");
            $this->assertStringContainsString('PageHeading::tag()', $source, "⛔ {$file} ট্যাগ চায় না");
        }
    }

    public function test_no_view_writes_a_second_h1(): void
    {
        $looked = 0;
        $wrong = [];

        exec('git -C '.escapeshellarg(base_path()).' ls-tree -r --name-only HEAD app resources 2>&1', $lines, $code);
        $this->assertSame(0, $code, 'গিটের তালিকা পাওয়া গেল না');

        foreach ($lines as $relative) {
            if (! str_ends_with($relative, '.blade.php') || ! is_file(base_path($relative))) {
                continue;
            }

            $looked++;
            $source = self::withoutComments((string) file_get_contents(base_path($relative)));
            $literal = preg_match_all('/<h1[\s>]/', $source);
            $components = preg_match_all('/<x-ui\.page-header\b/', $source)
                + preg_match_all('/<x-ui\.toolbar\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\s:?title=/s', $source);

            if ($literal > 1 || ($literal > 0 && $components > 0)) {
                $wrong[] = "{$relative} (হাতে লেখা h1 {$literal}টা, শিরোনামের উপাদান {$components}টা)";
            }
        }

        $this->assertGreaterThan(500, $looked, 'মাত্র '.$looked.'টা ব্লেড — তালিকা ভেঙেছে কি না দেখুন।');
        $this->assertSame([], $wrong, "⛔ এই পাতাগুলো দুটো মূল শিরোনাম আঁকতে পারে:\n  ".implode("\n  ", $wrong));
    }

    private static function withoutComments(string $source): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }
}
