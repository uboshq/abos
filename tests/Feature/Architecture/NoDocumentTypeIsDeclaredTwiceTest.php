<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * একটা কাগজের ধরন দুইবার ঘোষিত হয় না — নিরীক্ষা ২৭.০৯.২৬ §২ (L1)।
 *
 * ── ⛔ কেন এই পাহারা ───────────────────────────────────────────────────
 * `Purchase/module.php`-এর `doc_types`-এ `'PR'` দুইবার লেখা ছিল। PHP-র
 * অ্যারে ঘোষণায় একই চাবি দুইবার থাকলে **পরেরটা চুপচাপ জেতে** — কোনো
 * ভুল, কোনো সতর্কতা নেই। তাই চাহিদাপত্র মাসের পর মাস ফেরতের সিরিজে
 * নম্বর নিয়েছে, আর কোনো পরীক্ষা লাল হয়নি: চালু হওয়া অ্যারেতে চাবি
 * একটাই, দ্বিতীয়টার চিহ্নই থাকে না।
 *
 * ⓘ তাই মাপা হয় **উৎস-লেখা**, চালু অ্যারে নয় — শুধু লেখাতেই দুইটা দেখা যায়।
 */
final class NoDocumentTypeIsDeclaredTwiceTest extends TestCase
{
    public function test_no_module_declares_a_document_type_twice(): void
    {
        $files = $this->moduleFiles();

        /* ⚠️ মেঝে: আজ ১৫টা মডিউল। খোঁজার নিয়ম ভাঙলে তালিকা নীরবে খালি হত। */
        $this->assertGreaterThanOrEqual(12, count($files), 'module.php পাওয়া গেল মাত্র '.count($files).'টা।');

        $declared = 0;
        $twice = [];

        foreach ($files as $path => $source) {
            $keys = $this->docTypeKeys($source);
            $declared += count($keys);

            foreach (array_count_values($keys) as $key => $n) {
                if ($n > 1) {
                    $twice[] = "{$path} — '{$key}' ×{$n}";
                }
            }
        }

        /* ⚠️ মেঝে: কিছু না পেলেও "দুইবার নেই" সত্য হত — তাই আগে দেখা যে সে কিছু পড়েছে। */
        $this->assertGreaterThanOrEqual(30, $declared, "doc_types-এ মোট মাত্র {$declared}টা চাবি পড়া গেল — ব্লক চেনার নিয়ম ভেঙেছে।");

        $this->assertSame([], $twice, implode(PHP_EOL, [
            'একই কাগজের ধরন দুইবার ঘোষিত — পরেরটা চুপচাপ আগেরটাকে মুছে দেয়:',
            '',
            ...$twice,
            '',
            'দুইটা আলাদা কাগজ হলে দুইটা আলাদা কোড দিন।',
        ]));
    }

    /** ⭐ যন্ত্রটা সত্যিই দুইবার-লেখা চেনে — বানানো নমুনা দিয়ে, দুই দিকেই। */
    public function test_the_detector_sees_a_planted_duplicate(): void
    {
        $planted = "<?php return [\n    'doc_types' => [\n        'PR' => 'a',\n        /* মন্তব্য */\n        'PO' => 'b',\n        'PR' => 'c',\n    ],\n    'x' => ['PR' => 'd'],\n];";

        $this->assertSame(['PR', 'PO', 'PR'], $this->docTypeKeys($planted));
        $this->assertSame(['PR', 'PO'], $this->docTypeKeys("<?php return ['doc_types' => ['PR' => 'a', 'PO' => 'b']];"));
    }

    /** @return array<string, string> */
    private function moduleFiles(): array
    {
        $out = [];
        $root = dirname(__DIR__, 3);

        foreach ((new Finder)->files()->in($root.'/app/Modules')->depth('== 1')->name('module.php') as $file) {
            $out['app/Modules/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
        }

        return $out;
    }

    /**
     * `'doc_types' => [ … ]` ব্লকের ভিতরের প্রথম স্তরের চাবিগুলো, লেখার ক্রমে।
     *
     * টোকেন ধরে হাঁটা — মন্তব্য বাদ, আর বন্ধনীর গভীরতা গুনে ব্লকের শেষ চেনা।
     *
     * @return list<string>
     */
    private function docTypeKeys(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $keys = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING || trim($tokens[$i][1], '\'"') !== 'doc_types') {
                continue;
            }

            // 'doc_types' => [
            if (($tokens[$i + 1][0] ?? null) !== T_DOUBLE_ARROW || ($tokens[$i + 2] ?? null) !== '[') {
                continue;
            }

            $depth = 0;

            for ($j = $i + 2; $j < $count; $j++) {
                $t = $tokens[$j];

                if ($t === '[' || $t === '(') {
                    $depth++;
                } elseif ($t === ']' || $t === ')') {
                    if (--$depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING
                    && ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW) {
                    $keys[] = trim($t[1], '\'"');
                }
            }
        }

        return $keys;
    }
}
