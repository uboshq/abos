<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\LookRegistry;
use App\Core\Support\Ui;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * রূপগুলো ডাটায় সরল — কিন্তু একটাও মান নড়ল না।
 *
 * ── কেন এই পরীক্ষাটা ─────────────────────────────────────────────────
 * থিম ইঞ্জিনের প্ল্যানে হার্ড রুল লেখা: *"ইঞ্জিন বদলাতে পারবে মানগুলো
 * কোথায় থাকে। মানগুলো নিজে নয়।"*
 *
 * ধাপ ১-এ টোকেনগুলো `themes.css` থেকে `Looks/*.php`-তে সরেছে। সরানোটা
 * যন্ত্রে হয়েছে, হাতে নয় — কিন্তু "যন্ত্রে করেছি" কোনো প্রমাণ নয়।
 * প্রমাণটা এই: **দুই জায়গায় একই কথা লেখা আছে কি না**।
 *
 * ── কেন দুই জায়গাতেই আপাতত থাকছে ─────────────────────────────────────
 * সংকলক (ধাপ ১-এর ৪ নং) বসার পর CSS ব্লকগুলো উঠে যাবে, আর তখন এই
 * পরীক্ষাটাও অর্থহীন হয়ে যাবে — কারণ মেলানোর মতো দ্বিতীয় কোনো জায়গা
 * থাকবে না।
 *
 * ততদিন এটাই জাল: ডাটা আর স্টাইলশিট আলাদা কিছু বললে সাথে সাথে ধরা
 * পড়বে, বছরখানেক পরে কারো চোখে নয়।
 */
class LooksMatchTheStylesheetTest extends TestCase
{
    /** @var array<string, array{light: array<string, string>, dark: array<string, string>}> */
    private array $css = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->css = $this->readStylesheet();
    }

    /**
     * প্রতিটা রূপের প্রতিটা টোকেন — দুই জায়গায় হুবহু এক।
     *
     * এটাই এই ফাইলের একমাত্র আসল দাবি। বাকিগুলো এটার পাহারা।
     */
    public function test_not_one_value_moved_when_the_looks_became_data(): void
    {
        foreach (Ui::keys() as $look) {
            foreach (['light', 'dark'] as $mode) {
                $fromCss = $this->css[$look][$mode] ?? [];
                $fromData = LookRegistry::of($look)[$mode];

                ksort($fromCss);
                ksort($fromData);

                $this->assertSame($fromCss, $fromData, sprintf(
                    "'%s' রূপের %s টোকেনগুলো স্টাইলশিট আর ডাটায় আলাদা। ".
                    'ইঞ্জিন মানগুলো বদলাতে পারবে না — কেবল কোথায় থাকে সেটা।',
                    $look, $mode === 'dark' ? 'গাঢ়' : 'হালকা',
                ));
            }
        }
    }

    /**
     * দশটা রূপেরই ফাইল আছে, আর একটাও খালি নয়।
     *
     * উপরের পরীক্ষাটা দুইটা খালি অ্যারে মিলিয়েও পাশ করত — একটা রূপের
     * ফাইল তৈরি হতে ভুলে গেলে সেটাই ঘটত, আর কেউ টের পেত না।
     */
    public function test_every_look_brought_its_tokens_along(): void
    {
        foreach (Ui::keys() as $look) {
            $light = LookRegistry::of($look)['light'];

            $this->assertNotEmpty($light, "'{$look}' রূপের একটাও টোকেন নেই।");

            /*
             * চল্লিশ — সবচেয়ে ছোট রূপটাও (navy, ৫৩) এর অনেক উপরে।
             * সংখ্যাটা আঁটসাঁট নয় ইচ্ছাকৃতভাবে: এটা "টোকেন গুনে দেখা"
             * নয়, "ফাইলটা অর্ধেক লেখা হয়ে থেমে যায়নি" — আর ওটাই
             * একমাত্র ব্যর্থতা যা উপরের পরীক্ষাটা মিস করতে পারত।
             */
            $this->assertGreaterThan(40, count($light),
                "'{$look}' রূপে মাত্র ".count($light).'টা টোকেন — ফাইলটা অসম্পূর্ণ।');
        }
    }

    /**
     * গাঢ় রূপ মানে "গাঢ় ব্লকটা" নয়, "হালকাটার উপর গাঢ়টা"।
     *
     * গাঢ় ব্লকে কেবল সেই টোকেনগুলো লেখা যেগুলো রাতে বদলায় — ধার,
     * ঘনত্ব ও সারির উচ্চতা ওখানে নেই। কেবল গাঢ় ব্লকটা নিলে Redwood
     * রাতে তার ১৬px গোল কোণ হারাত, আর NetSuite তার ২৪px সারি।
     */
    public function test_dark_keeps_what_the_night_does_not_change(): void
    {
        foreach (Ui::keys() as $look) {
            $day = LookRegistry::tokens($look, 'light');
            $night = LookRegistry::tokens($look, 'dark');

            foreach (['--radius-card', '--row-height', '--font-size-table'] as $token) {
                if (! isset($day[$token])) {
                    continue;
                }

                $this->assertSame($day[$token], $night[$token], sprintf(
                    "'%s' রূপে %s রাতে বদলে গেছে — ওটা রঙের ব্যাপার নয়, গড়নের।",
                    $look, $token,
                ));
            }
        }
    }

    /**
     * স্টাইলশিট থেকে রূপগুলো পড়া — মন্তব্য বাদ দিয়ে।
     *
     * মন্তব্যে হেক্স থাকে (কারণ ব্যাখ্যা করতে গেলে রংটার নাম লিখতেই
     * হয়), তাই ওগুলো আগে ছেঁটে ফেলা হয় — নাহলে ব্যাখ্যার রং টোকেন
     * হয়ে ঢুকে পড়ত।
     *
     * @return array<string, array{light: array<string, string>, dark: array<string, string>}>
     */
    private function readStylesheet(): array
    {
        $css = (string) File::get(resource_path('css/themes.css'));
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $found = [];

        preg_match_all('/([^{}]*?)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER);

        foreach ($blocks as [, $selector, $body]) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $selector));

            preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $pairs, PREG_SET_ORDER);

            $tokens = [];

            foreach ($pairs as [, $name, $value]) {
                $tokens[$name] = trim((string) preg_replace('/\s+/', ' ', $value));
            }

            if ($tokens === []) {
                continue;
            }

            foreach ($this->looksIn($selector) as $look => $mode) {
                $found[$look][$mode] = [...($found[$look][$mode] ?? []), ...$tokens];
            }
        }

        return $found;
    }

    /**
     * একটা নির্বাচক কোন রূপের, আর কোন থিমের।
     *
     * তিনটা আকার আছে, আর তিনটাই ফাইলে সত্যিই ব্যবহার হয়:
     *   `[data-ui='x']`                              — হালকা
     *   `:root[data-theme='dark'][data-ui='x']`      — সেই রূপের গাঢ়
     *   `:root[data-theme='dark']:is([data-ui='a'], [data-ui='b'])`
     *
     * তৃতীয়টা না ধরলে rose ও redwood-এর গাঢ় রেলের মানগুলো হারাত।
     *
     * @return array<string, string>
     */
    private function looksIn(string $selector): array
    {
        if (preg_match("/^\[data-ui='([a-z]+)'\]$/", $selector, $m) === 1) {
            return [$m[1] => 'light'];
        }

        if (preg_match("/^:root\[data-theme='dark'\]\[data-ui='([a-z]+)'\]$/", $selector, $m) === 1) {
            return [$m[1] => 'dark'];
        }

        if (preg_match("/^:root\[data-theme='dark'\]:is\((.*)\)$/", $selector, $m) === 1) {
            preg_match_all("/\[data-ui='([a-z]+)'\]/", $m[1], $names);

            return array_fill_keys($names[1], 'dark');
        }

        return [];
    }
}
