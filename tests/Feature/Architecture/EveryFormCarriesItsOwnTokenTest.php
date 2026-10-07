<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Services\FormIsNotSubmittedTwice;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⛔ প্রতিটা ফর্ম নিজের টোকেন বয়ে আনে — ১৩ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ঘটেছিল ────────────────────────────────────────────────────────
 * মালিক মূলধনের ফর্মে Save-এ **দুইবার ক্লিক করেছেন**, আর `CAP-0001` ও
 * `CAP-0002` — দুইটা সারি বসেছে, ২৫,০০,০০০ টাকা দুইবার।
 *
 * ── ⭐ এই ফাইলটা কেন আছে ─────────────────────────────────────────────
 * সারাইটা কেন্দ্রীয়: `@csrf` নির্দেশিকা নতুন করে সংজ্ঞায়িত করা হয়েছে
 * (`AppServiceProvider`), তাই প্রতিটা ফর্ম `_once` ঘরটা **কিছু না করেই**
 * পায়। ⚠️ কিন্তু কেন্দ্রীয় সারাইয়ের দাম হলো **নীরবতা**: কেউ override
 * তুলে দিলে কোনো পর্দা ভাঙে না, কোনো ত্রুটি আসে না — কেবল পাহারাটা
 * চুপচাপ চলে যায়, আর ছয় মাস পরে কেউ আবার দুইবার ক্লিক করেন।
 *
 * ⓘ তাই প্রমাণটা দুই টুকরোয়, আর দুইটা একসাথে পুরো শিকলটা বানায়:
 *
 *     ১. `@csrf` সত্যিই `_once` বসায়   (নির্দেশিকাটা কম্পাইল করে দেখা)
 *     ২. প্রতিটা POST ফর্মে `@csrf` আছে (১৪৭টা ফাইল পড়ে দেখা)
 *
 * ── ⚠️ আর তৃতীয় একটা দাবি, যেটা আজকের আসল শিক্ষা ────────────────────
 * ⛔ "যেগুলো পেয়েছি সবগুলোতেই `@csrf` আছে" — এই দাবিটা **শূন্যটা ফর্ম
 * পেলেও সত্য**। আজ এই রিপোতে ঠিক ওই আকারের তিনটা পাহারা ধরা পড়েছে যারা
 * সবুজ থেকেও কিছুই দেখত না। ⭐ তাই নিচে **সংখ্যাটা গোনা হয়**, আর ১৪০-এর
 * নিচে নামলে পরীক্ষাটা নিজেই লাল হয়।
 */
class EveryFormCarriesItsOwnTokenTest extends TestCase
{
    /**
     * ⭐ আজ মেপে পাওয়া সংখ্যা ১৪৭। সীমা ১৪০ — সাতটার ছাড় ইচ্ছাকৃত।
     *
     * ⓘ পর্দা মুছে যেতে পারে, দুইটা ফর্ম এক হয়ে যেতে পারে; ওগুলোর জন্য
     * পাহারাটা লাল হওয়া উচিত নয়। ⚠️ কিন্তু সংখ্যাটা হঠাৎ ২০ বা ০ হয়ে
     * গেলে সেটা পর্দা কমার খবর নয়, **খোঁজার ছাঁচ ভাঙার** খবর।
     */
    private const FEWEST_FORMS = 140;

    /**
     * ⛔ প্রথম টুকরো — `@csrf` দুইটা ঘরই বসায়, আর `_token` অক্ষত।
     *
     * ⚠️ নির্দেশিকাটা **কম্পাইল করে** দেখা হয়, ফাইল পড়ে নয়: কেউ
     * `AppServiceProvider`-এ লাইনটা রেখে দিয়েও ওটা অন্য কোথাও চাপা
     * দিতে পারেন, আর তখন ফাইলে লেখাটা থাকত অথচ আচরণ বদলে যেত।
     */
    public function test_the_csrf_directive_adds_the_token_without_taking_anything_away(): void
    {
        $compiled = Blade::compileString('@csrf');

        $this->assertStringContainsString('csrf_field()', $compiled, implode("\n", [
            '⛔ `@csrf` আর Laravel-এর নিজের `csrf_field()` ডাকছে না।',
            '',
            '⚠️ এটা দ্বৈত-জমার চেয়ে বড় সমস্যা: CSRF পাহারাটাই চলে গেছে।',
            'override-টা `csrf_field()` **ডাকতে** হবে, তার জায়গা নিতে নয়।',
        ]));

        $this->assertStringContainsString(FormIsNotSubmittedTwice::class, $compiled, implode("\n", [
            '⛔ `@csrf` আর দ্বৈত-জমার টোকেনটা বসাচ্ছে না।',
            '',
            'override-টা `AppServiceProvider::boot()`-এ; সরে গেছে কি না দেখুন।',
            '⚠️ এটা সরে গেলে কোনো পর্দা ভাঙে না — পাহারাটা কেবল চুপচাপ',
            'চলে যায়, আর মালিক আবার দুইবার ক্লিক করেন।',
        ]));

        /*
         * ⓘ কম্পাইল হওয়া কোডটা চালিয়েও দেখা — কেবল নামটা থাকা যথেষ্ট নয়,
         * ঘরটা সত্যিই HTML-এ বসতে হবে।
         */
        $html = app(FormIsNotSubmittedTwice::class)->field();

        $this->assertStringContainsString('name="'.FormIsNotSubmittedTwice::FIELD.'"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
    }

    /**
     * প্রতিবার নতুন টোকেন — একটা রেন্ডার, একটা টোকেন।
     *
     * ⚠️ ক্যাশ করলে বা সেশনে রাখলে একই পাতার দুইটা ফর্ম একই টোকেন পেত,
     * আর তখন একটা ফর্ম জমা দিলে **অন্যটা আটকে যেত** — একটা পর্দা যেখানে
     * দুইটা কাজের একটাই করা যায়।
     */
    public function test_each_render_gets_its_own_token(): void
    {
        $forms = app(FormIsNotSubmittedTwice::class);

        $this->assertNotSame($forms->field(), $forms->field());
    }

    /**
     * ⛔ দ্বিতীয় টুকরো — প্রতিটা POST ফর্মে `@csrf` আছে।
     *
     * ⓘ আর এই দাবিটা আগে থেকেই সত্য ছিল: `@csrf` ছাড়া Laravel-এ POST
     * ফর্ম এমনিতেই চলে না (419)। ⭐ সেজন্যই ওটা নোঙর হিসেবে বেছে নেওয়া
     * হয়েছে — যে জিনিস না থাকলে পর্দাটা **আজই** ভাঙে, সেটা ভুলে যাওয়া
     * যায় না।
     */
    public function test_every_post_form_carries_the_csrf_anchor(): void
    {
        $naked = [];
        $counted = 0;

        foreach ($this->bladeFiles() as $path => $source) {
            foreach ($this->postForms($source) as $form) {
                $counted++;

                if (! str_contains($form, '@csrf')) {
                    $naked[] = $path;
                }
            }
        }

        $naked = array_values(array_unique($naked));
        sort($naked);

        /*
         * ⭐ গোনাটা **আগে**, দাবির পরে নয় — আজকের নিয়মটাই এটা।
         *
         * ⛔ নিচের `assertSame([], $naked)` শূন্যটা ফর্ম পেলেও সবুজ
         * থাকত। আজ এই রিপোতে ঠিক ওই আকারে তিনটা পাহারা ধরা পড়েছে
         * (`ModuleMenuTest`, ডুপ্লিকেট-চাবির ৪% দৃষ্টি, আর একটা
         * স্কোপযুক্ত গণনা) — তিনটাই সবুজ ছিল আর কিছুই দেখত না।
         */
        $this->assertGreaterThanOrEqual(self::FEWEST_FORMS, $counted, implode("\n", [
            "⛔ POST ফর্ম খুঁজে পাওয়া গেল মাত্র {$counted}টা — আজ ছিল ১৪৭টা।",
            '',
            '⚠️ পর্দা কমার খবর নয়, খোঁজার ছাঁচ ভাঙার খবর।',
            'ফর্মগুলো কি এখন অন্যভাবে লেখা হয় (কম্পোনেন্ট, `@method`)?',
            'তাহলে `postForms()` ঠিক করুন — নাহলে এই পাহারাটা সবুজ',
            'থেকেও কিছুই দেখবে না।',
        ]));

        $this->assertSame([], $naked, implode("\n", array_merge(
            ['⛔ এই পর্দাগুলোর POST ফর্মে `@csrf` নেই:', ''],
            $naked,
            ['',
                '⚠️ দুইটা জিনিস একসাথে হারায়: CSRF পাহারা (ফর্মটা ৪১৯ পাবে),',
                'আর দ্বৈত-জমার টোকেন — কারণ `_once` ঘরটা `@csrf`-এর সাথেই আসে।'],
        )));
    }

    /**
     * ⛔ টেবিলটা সত্যিই আছে — নাহলে পাহারাটা নীরবে ছুটি নেয়।
     *
     * ── কেন এই দাবিটা আলাদা করে দরকার ───────────────────────────────
     * ⓘ [[FormIsNotSubmittedTwice::claim()]] ইচ্ছাকৃতভাবে **টেবিল না
     * থাকলে কাজ চালিয়ে যায়** (`42S02` গিলে ফেলে)। কারণটা `infra/deploy.sh`:
     * `git pull` লাইন ১৪২, `migrate` লাইন ১৮২ — অর্থাৎ নতুন কোড চলে
     * টেবিল বসার আগে, আর ঐ জানালায় ব্যতিক্রম ছুঁড়লে **প্রতিটা ফর্ম ৫০০
     * দিত**।
     *
     * ⚠️ কিন্তু ঐ ক্ষমাটার দাম হলো: মাইগ্রেশন কেউ ভুলে গেলে অ্যাপ
     * নিখুঁত চলবে আর পাহারাটা **থাকবেই না**, কোনো শব্দ ছাড়া।
     *
     * ⭐ তাই প্রশ্নটা এখানে করা হয় — CI-তে, ব্যবহারকারীর পর্দায় নয়।
     * যন্ত্র ভুলে যাওয়া মাইগ্রেশন ধরে, মানুষ নয়।
     */
    public function test_the_table_the_guard_needs_actually_exists(): void
    {
        $this->assertTrue(Schema::hasTable('submitted_forms'), implode("\n", [
            '⛔ `submitted_forms` টেবিলটা নেই — দ্বৈত-জমার পাহারা নীরবে বন্ধ।',
            '',
            'মাইগ্রেশন: `2026_11_09_100000_two_clicks_made_two_rows.php`',
            '⚠️ অ্যাপ এতে ভাঙে না, আর ঠিক সেজন্যই এই দাবিটা এখানে লেখা।',
        ]));

        foreach (['token', 'result_url', 'completed_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('submitted_forms', $column),
                "`submitted_forms.{$column}` নেই — মাইগ্রেশনটা অর্ধেক বসেছে।");
        }
    }

    /* ── খোঁজার কাজটা ──────────────────────────────────────────────── */

    /**
     * একটা ফাইলের ভেতরের POST ফর্মগুলো — প্রতিটা `<form …>` থেকে `</form>`।
     *
     * ⚠️ লাইন ধরে খোঁজা হয় না: ফর্মের খোলা ট্যাগটা প্রায়ই দুই-তিন লাইনে
     * ছড়ানো (`<form method="POST"` এক লাইনে, `action=…` পরের লাইনে)।
     * ⓘ তাই টুকরোটা ধরা হয় ট্যাগ থেকে ট্যাগ পর্যন্ত।
     *
     * @return list<string>
     */
    private function postForms(string $source): array
    {
        $forms = [];
        $offset = 0;

        while (($open = strpos($source, '<form', $offset)) !== false) {
            $close = strpos($source, '</form>', $open);
            $offset = $open + 5;

            if ($close === false) {
                continue;
            }

            $form = substr($source, $open, $close - $open);

            /*
             * ⓘ কেবল POST। খোঁজা, ছাঁকনি আর পাতা বদল GET-এ চলে, আর
             * সেখানে দ্বৈত-জমার কোনো প্রশ্ন নেই।
             *
             * ⚠️ `stripos` — কেউ `method="post"` ছোট হরফে লিখতে পারেন,
             * আর HTML সেটা মানে।
             */
            if (stripos($form, 'method="POST"') !== false || stripos($form, "method='POST'") !== false) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * প্রতিটা ব্লেড ফাইল — কোর ও প্রতিটা মডিউল।
     *
     * ⚠️ মডিউলের ভিউগুলো `resources/views`-এ নেই, তারা থাকে প্রতিটা
     * মডিউলের নিজের `Resources/views`-এ। ⓘ কেবল প্রথমটা দেখলে পাহারাটা
     * অ্যাপের বেশিরভাগ ফর্মই দেখত না — আর সংখ্যাটা গোনা হচ্ছে ঠিক সেই
     * ভুলটা ধরার জন্যই।
     *
     * @return array<string, string>
     */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walk as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
