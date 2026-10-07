<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * কম্পোনেন্টের ট্যাগের **ভিতরে** কোনো নির্দেশ নয় — তাতে ট্যাগটা মরে যায়।
 *
 * ── ⛔ কী ঘটেছিল, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * প্রতিষ্ঠানের পিকারে লেখা ছিল:
 *
 *     <x-ui.select :name="$field"
 *
 *                  @if ($onPick) x-on:change="{{ $onPick }}" @endif
 *                  placeholder="—" />
 *
 * ⚠️ Blade কম্পোনেন্টের ট্যাগ পড়ে একটা regex দিয়ে, আর সে ভিতরের নির্দেশ
 * সামলাতে পারে না — তাই গোটা ট্যাগটা **হুবহু লেখা হিসেবে** পাতায় চলে
 * যেত। ⓘ ব্রাউজার `<x-ui.select>` কে অচেনা এলিমেন্ট ধরে চুপচাপ আঁকে,
 * কোনো ত্রুটি দেয় না।
 *
 * ⛔ ফল: **প্রতিষ্ঠানের ড্রপডাউনটা কোনোদিন তৈরিই হয়নি** — তিনটা পর্দায়
 * (জমা · ব্যাংক সুবিধা · বিমা)। মালিক নিজে ধরেছেন: *"আর্থিক
 * প্রতিষ্ঠানের নাম নাই কেন"*।
 *
 * ── ⚠️ কেন কোনো পাহারা এটা ধরত না ────────────────────────────────────
 * ⓘ [[NoLeakedBladeTest]] পাতা খোলে, কিন্তু সে খুঁজত PHP অ্যারের টুকরা
 * (`'key' =>`, `=> fn (`)। একটা ট্যাগ যদি কেবল নিজের অ্যাট্রিবিউট নিয়ে
 * ছাপা হয়, তার একটাও মেলে না। ⛔ আর সে কেবল **প্যারামিটারহীন মেনু-রুট**
 * হাঁটে — "নতুন জমা" পাতাটা ওখানে পড়েই না।
 *
 * ⭐ তাই এই পাহারাটা পাতা খোলে না, **লেখা পড়ে**: কোন পাতা হাঁটা হলো তার
 * উপর নির্ভর করে না, আর ভুলটা লেখার দিনেই ধরে।
 */
class ADirectiveInsideAComponentTagStopsItCompilingTest extends TestCase
{
    /**
     * ট্যাগের ভিতরে যা থাকলে Blade আর ট্যাগটা চেনে না।
     *
     * ⓘ `@class`, `@checked`, `@selected`, `@disabled`, `@readonly` বাদ:
     * ওগুলো অ্যাট্রিবিউট লেখার **স্বীকৃত** রূপ, আর Blade ওগুলো ট্যাগের
     * ভিতরেই আশা করে।
     *
     * @var list<string>
     */
    private const DEADLY = ['@if', '@elseif', '@else', '@endif', '@foreach', '@endforeach', '@php'];

    /*
     * ⛔ `{{-- --}}` এই তালিকায় ছিল, আর সেটা ভুল ছিল — প্রথম চালেই
     * সাতটা মিথ্যা অভিযোগ করল (২১ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ Blade মন্তব্য মোছে **কম্পোনেন্ট পড়ার আগে**, তাই ট্যাগের ভিতরে
     * মন্তব্য নিরীহ — যাচাই করে দেখা হয়েছে: ঐ পাতাগুলোয় একটাও কাঁচা
     * `<x-` নেই। ⚠️ আর মিথ্যা অভিযোগ করা পাহারা কয়েক দিনেই ছাড় পেয়ে
     * যায়, আর তখন সে আসলটাও ধরে না।
     *
     * ⚠️ তবে ঐ মন্তব্যের **ভিতরে একটা ASCII ডবল কোট** থাকলে অ্যাট্রিবিউট
     * মাঝপথে শেষ হয়ে যায় — সেটা আলাদা ভুল, আর সেটা দেখে
     * [[AQuoteInsideAnAttributeEndsItEarlyTest]]।
     */

    public function test_no_component_tag_carries_a_directive_inside_it(): void
    {
        $broken = [];
        $tags = 0;

        foreach ($this->blades() as $path) {
            $source = File::get($path);

            /*
             * ⓘ ট্যাগের শুরু থেকে প্রথম `>` পর্যন্ত — ঠিক যতটুকু Blade
             * নিজে পড়ে। ⚠️ অ্যাট্রিবিউটের ভিতরে `>` থাকতে পারে (`=>`),
             * তাই ওটা বাদ দেওয়া হয়: `[^>]` নয়, `(?:[^>]|=>)`।
             */
            preg_match_all('/<x-[a-z0-9.\-]+((?:[^>]|=>)*?)\/?>/is', $source, $found, PREG_SET_ORDER);

            foreach ($found as $match) {
                $tags++;

                foreach (self::DEADLY as $directive) {
                    if (str_contains($match[1], $directive)) {
                        $short = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                        $line = substr_count(substr($source, 0, (int) strpos($source, $match[0])), "\n") + 1;

                        $broken[] = "{$short}:{$line} — {$directive}";

                        break;
                    }
                }
            }
        }

        /*
         * ⭐ "কিছু নেই" তখনই অর্থবহ, যখন জানা থাকে সত্যিই খোঁজা হয়েছে —
         * আর ছাঁকনিটা খুঁজে পেতে পারে।
         */
        $this->assertGreaterThan(
            300,
            $tags,
            "কেবল {$tags}টা কম্পোনেন্ট ট্যাগ পড়া হয়েছে — তালিকা খালি, কারণ খোঁজাই হয়নি।",
        );

        $sample = '<x-ui.select :name="$f" @if ($x) x-on:change="go()" @endif placeholder="—" />';

        $this->assertSame(
            1,
            preg_match('/<x-[a-z0-9.\-]+((?:[^>]|=>)*?)\/?>/is', $sample, $proof),
            'ছাঁকনিটা একটা সত্যিকারের কম্পোনেন্ট ট্যাগও চিনতে পারে না।',
        );

        $this->assertStringContainsString('@if', $proof[1], 'নমুনার ভিতরের নির্দেশটাই ধরা পড়ছে না।');

        $broken = array_values(array_unique($broken));
        sort($broken);

        $this->assertSame([], $broken, implode("\n", array_merge(
            ['এই কম্পোনেন্ট ট্যাগগুলোর ভিতরে একটা নির্দেশ বসানো আছে:', ''],
            $broken,
            [
                '',
                '⚠️ Blade তখন ট্যাগটা আর চেনে না, আর গোটা লেখাটা হুবহু পাতায়',
                'চলে যায় — ব্রাউজার চুপচাপ আঁকে, ঘরটা কেবল থাকে না।',
                '',
                'শর্তটা `@php`-তে বানিয়ে নিন, তারপর `:attributes="$extra"`।',
            ],
        )));
    }

    /** @return list<string> */
    private function blades(): array
    {
        $out = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }
}
