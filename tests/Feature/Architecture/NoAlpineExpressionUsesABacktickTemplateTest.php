<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⛔ লাইভ, ১৭ নভেম্বর ২০২৬ (মালিকের অডিট, পাতা ৪০৮ ও ৫৫৯): মাল গ্রহণ আর ক্রয়-বিল সম্পাদনার পাতায়
 * "CSP Parser Error: Unexpected token: OPERATOR "`"" — লাইন-সম্পাদকের মেয়াদের ঘরের নাম লেখা ছিল
 * `` bind-name="`lines[${i}][expiry_date]`" ``।
 *
 * ⓘ লাইভ চলে `@alpinejs/csp`-এ, আর তার পার্সার ছাঁচ-লেখা (backtick) চেনে না। সে ব্যতিক্রম ছুঁড়ে বাঁধনটা
 *   ফেলে দেয়: ঘরটা পর্দায় থাকে, কিন্তু নাম পায় না — লট-ধরা পণ্যের মেয়াদ কোনোদিন জমা হত না।
 *   পাতা ২০০ দিত, টেস্টও সবুজ ছিল, কারণ ফিচার টেস্ট জাভাস্ক্রিপ্ট চালায় না।
 *
 * ⭐ নাম জোড়া লাগান `+` দিয়ে: `'lines[' + (i) + '][expiry_date]'` — সারা ABOS-এ যেভাবে।
 *
 * ⓘ ব্লেডের মন্তব্য ({{-- … --}}) বাদ দিয়ে পড়া হয় — মন্তব্যে ছাঁচের কথা লেখা থাকতেই পারে।
 */
final class NoAlpineExpressionUsesABacktickTemplateTest extends TestCase
{
    /** যে অ্যাট্রিবিউটগুলো Alpine জাভাস্ক্রিপ্ট হিসেবে পড়ে — `:x`, `x-…`, `@…`, আর আমাদের কম্পোনেন্টের `bind-…` */
    private const EVALUATED = '(?::[\w.-]+|x-[\w.:-]+|@[\w.:-]+|bind-[\w-]+)';

    public function test_no_alpine_expression_uses_a_backtick_template(): void
    {
        $offences = [];

        foreach ($this->bladeFiles() as $path) {
            foreach ($this->offencesIn(File::get($path)) as $attribute) {
                $offences[] = $this->shortPath($path).'  →  '.$attribute;
            }
        }

        sort($offences);

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['⛔ এই বাঁধনগুলোতে ছাঁচ-লেখা (`…${…}…`) — লাইভের CSP-সংস্করণের Alpine এগুলো পড়তে পারে না:', ''],
            $offences,
            ['', '⭐ `+` দিয়ে জোড়া লাগান, যেমন `\'lines[\' + (i) + \'][expiry_date]\'`।'],
        )));
    }

    /** পাহারাটা নিজে অপরাধ চেনে, আর নিরীহকে অপরাধ বলে না */
    public function test_the_guard_sees_what_it_should(): void
    {
        $this->assertNotSame([], $this->offencesIn('<x-ui.date bind-name="`lines[${i}][expiry_date]`" />'),
            '⛔ পাহারা লাইভের সেই ভুলটাই চেনে না');
        $this->assertNotSame([], $this->offencesIn('<span :title="`${row.qty} pcs`"></span>'),
            '⛔ পাহারা `:` বাঁধনের ছাঁচ চেনে না');
        $this->assertSame([], $this->offencesIn('<x-ui.date bind-name="\'lines[\' + (i) + \'][expiry_date]\'" />'),
            '⛔ পাহারা ঠিক লেখাকেও অপরাধ বলছে');
        $this->assertSame([], $this->offencesIn("{{-- `lines[\${i}]` --}}<span :title=\"row.qty\"></span>"),
            '⛔ পাহারা মন্তব্যের ভেতরের লেখাকেও অপরাধ বলছে');

        $this->assertGreaterThan(100, count($this->bladeFiles()), '⛔ ব্লেডই পাওয়া যায়নি — খোঁজাটা ভেঙেছে');
    }

    /** @return list<string> */
    private function offencesIn(string $body): array
    {
        $body = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $body);
        preg_match_all('/<(x-[\w.:-]+)?[^<>]*?\s('.self::EVALUATED.')="([^"]*)"/', $body, $m, PREG_SET_ORDER);

        $offences = [];

        foreach ($m as [, $tag, $attribute, $value]) {
            // ⓘ ব্লেড কম্পোনেন্টের `:prop` PHP, জাভাস্ক্রিপ্ট নয় — সেখানে ছাঁচের প্রশ্ন নেই
            if ($tag !== '' && str_starts_with($attribute, ':')) {
                continue;
            }

            // ⓘ জাভাস্ক্রিপ্টের মন্তব্যে backtick থাকলে ক্ষতি নেই — মন্তব্য বাদ দিয়ে দেখা
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], $value);

            if (str_contains($code, '`')) {
                $offences[] = $attribute.'="'.trim(strtok($value, "\n")).'"';
            }
        }

        return $offences;
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function shortPath(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
    }
}
