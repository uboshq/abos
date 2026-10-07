<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `onclick=` লেখা একটা বোতাম লাইভে কিছুই করে না।
 *
 * ── ⛔ মালিকের অভিযোগ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"print buton kaj kore na kintu"* — আর কথাটা সত্যি ছিল। বোতামটায়
 * লেখা ছিল `onclick="window.print()"`।
 *
 * ⓘ লাইভের CSP: `script-src 'self' 'nonce-…'` — কোথাও `'unsafe-inline'`
 * নেই। ⛔ তখন CSP-র নিয়মেই ব্রাউজার প্রতিটা `on*=` অ্যাট্রিবিউট
 * **চালাতে দেয় না**। বোতামটা দেখা যায়, চাপা যায়, কিচ্ছু হয় না।
 *
 * ⚠️ আর কিছুই ভাঙে না: পাতা ২০০ দেয়, কোনো পরীক্ষা লাল হয় না, সার্ভারে
 * কোনো চিহ্ন থাকে না। ⓘ কেবল ব্রাউজারের কনসোলে একটা লাইন — যেটা কেউ
 * দেখে না।
 *
 * ⭐ একই কারণে "সাজাও" ড্রপডাউনটাও মরে ছিল (`onchange="this.form
 * .submit()"`)। ⓘ অর্থাৎ মালিকের *"কোনোটাই কাজ করে না"* কথাটা একটা
 * অভিযোগ ছিল না, একটা **সঠিক রোগনির্ণয়** ছিল।
 *
 * ── ⓘ বদলে কী ─────────────────────────────────────────────────────
 * `data-action="print"` / `data-action="submit-form"`, আর শ্রোতা
 * [[actions.js]]-এ। ⚠️ সেটা বান্ডিলের ভিতরে, তাই `'self'`-এর জোরেই
 * চলে।
 */
final class NoInlineHandlerSurvivesTheContentPolicyTest extends TestCase
{
    /**
     * ⚠️ CSP বন্ধ থাকলে এগুলো লোকালে দিব্যি চলে — আর সেটাই ফাঁদ:
     * ডেভ মেশিনে কাজ করে, লাইভে করে না।
     */
    private const HANDLERS = [
        'onclick', 'onchange', 'onsubmit', 'oninput', 'onload', 'onerror',
        'onfocus', 'onblur', 'onkeydown', 'onkeyup', 'onkeypress',
        'onmouseover', 'onmouseout', 'onmousedown', 'onmouseup', 'ondblclick',
    ];

    public function test_no_blade_carries_an_inline_event_handler(): void
    {
        $found = [];

        foreach ($this->blades() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::HANDLERS as $handler) {
                if (preg_match_all('/\s'.$handler.'\s*=\s*"/i', $source, $m, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($m[0] as [$_, $at]) {
                    $line = substr_count(substr($source, 0, $at), "\n") + 1;
                    $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.$line.' — '.$handler;
                }
            }
        }

        sort($found);

        $this->assertSame([], $found, implode("\n", array_merge(
            ['⛔ এই অ্যাট্রিবিউটগুলো লাইভে কোনোদিন চলে না:', ''],
            $found,
            ['',
                '⚠️ CSP-তে `unsafe-inline` নেই, তাই ব্রাউজার `on*=` চালাতেই দেয় না।',
                'ⓘ পাতা ২০০ দেবে, কোনো পরীক্ষা লাল হবে না — কেবল বোতামটা মরা থাকবে।',
                '',
                '⭐ বদলে একটা `data-action="…"` বসান, আর শ্রোতাটা',
                'resources/js/components/actions.js-এ যোগ করুন।']
        )));
    }

    /**
     * ⭐ আর `javascript:` ঠিকানাও একই কারণে মৃত।
     *
     * ⓘ `<a href="javascript:…">` CSP-র চোখে ইনলাইন স্ক্রিপ্ট, তাই
     * ওটাও আটকে যায়। ⚠️ আলাদা দাবি, কারণ নোঙরটা আলাদা — উপরেরটা
     * খুঁজলে এটা কোনোদিন ধরা পড়ত না।
     */
    public function test_no_link_runs_javascript_from_its_href(): void
    {
        $found = [];

        foreach ($this->blades() as $file) {
            if (preg_match('/href\s*=\s*"javascript:/i', (string) file_get_contents($file)) === 1) {
                $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        sort($found);

        $this->assertSame([], $found, implode("\n", array_merge(
            ['⛔ `javascript:` ঠিকানা CSP-তে চলে না:', ''],
            $found,
        )));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দুইটা দাবি চিরকাল সবুজ থাকত যদি `blades()` খালি ফেরাত।
     * ⚠️ আর ঠিক সেটাই সবচেয়ে সহজ নীরব ব্যর্থতা — একটা পথ বদলে গেলেই
     * খোঁজা বন্ধ, আর পাহারাটা তবু সবুজ।
     */
    public function test_the_search_really_reads_the_blades(): void
    {
        $blades = $this->blades();

        $this->assertGreaterThan(300, count($blades),
            'মাত্র '.count($blades).'টা ব্লেড পাওয়া গেল — খোঁজাটাই ভেঙেছে।');

        $sample = tempnam(sys_get_temp_dir(), 'blade').'.blade.php';
        file_put_contents($sample, '<button onclick="window.print()">x</button>');

        $hit = preg_match('/\sonclick\s*=\s*"/i', (string) file_get_contents($sample)) === 1;

        unlink($sample);

        $this->assertTrue($hit, 'নোঙরটা একটা আসল ইনলাইন হ্যান্ডলারও ধরে না।');
    }

    /**
     * অ্যাপের প্রতিটা ব্লেড — কোর ও মডিউল দুইটাই।
     *
     * @return list<string>
     */
    private function blades(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
