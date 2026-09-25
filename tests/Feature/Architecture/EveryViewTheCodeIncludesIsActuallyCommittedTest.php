<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * কমিট করা ব্লেড যে ব্লেডগুলো ডাকে, সেগুলোও কমিট করা আছে কি না।
 *
 * ── ⛔ কেন, ২৬ সেপ্টেম্বর ২০২৬ ──────────────────────────────────────
 * মালিকের স্ক্রিনশট: ছাপার নিয়ন্ত্রণে বিক্রয় বিলের তেরোটা রূপের
 * **বারোটায়** নমুনার জায়গায় "500 · আমাদের দিকে কিছু ভেঙেছে"। লাইভের লগ:
 *
 *     View [print.partials.band-total] not found.
 *
 * ⓘ `document-body.blade.php` ২৩ সেপ্টেম্বর কমিট হয়েছিল, আর সে
 * `@include('print.partials.band-total')` ডাকে। ⚠️ ঐ partial-টা **ঐ রাতেই
 * লেখা, কিন্তু কখনো কমিট হয়নি** — ডিস্কে আছে, গিটে নেই। তাই এখানে সব
 * সবুজ, আর লাইভে ব্যান্ড করা প্রতিটা বিল (নমুনা নয় কেবল, আসল ছাপাও)
 * ৫০০।
 *
 * ⓘ দামহীন রূপটা বেঁচে গিয়েছিল কারণ সে টাকার পথে ঢোকেই না — ফলে
 * ভাঙনটা দেখতে লাগছিল রূপের সমস্যা, অথচ সেটা ছিল কমিটের।
 *
 * ── ⓘ ভাইবোন পাহারা ─────────────────────────────────────────────────
 * [[EveryClassTheCodeCallsIsActuallyCommittedTest]] একই রোগ ধরে PHP
 * ক্লাসে (২১ সেপ্টেম্বর, লগইনের প্রতিটা পাতা মৃত)। ⚠️ ব্লেড তার চোখের
 * বাইরে ছিল — `@include` কোনো `use` লাইন নয় — আর ঠিক ঐ ফাঁক দিয়েই এটা
 * ঢুকেছে।
 *
 * ── ⚠️ এটা কী মাপে না ──────────────────────────────────────────────
 * `<x-…>` কম্পোনেন্ট, আর চলক দিয়ে বানানো নাম (`@include($view)`)।
 * ⓘ কেবল আক্ষরিক নাম — আর ভুলটা ঠিক ঐ আকারেই হয়েছিল।
 */
final class EveryViewTheCodeIncludesIsActuallyCommittedTest extends TestCase
{
    public function test_no_committed_blade_includes_a_view_git_has_never_seen(): void
    {
        $tracked = $this->trackedFiles();

        $this->assertGreaterThan(
            300,
            count($tracked),
            'গিট থেকে মাত্র '.count($tracked).'টা ফাইল পাওয়া গেল — তালিকাটাই ভেঙেছে।'
        );

        $missing = [];
        $read = 0;

        foreach (array_keys($tracked) as $relative) {
            if (! str_ends_with($relative, '.blade.php')) {
                continue;
            }

            $src = @file_get_contents(base_path($relative));

            if ($src === false) {
                continue;
            }

            $read++;

            foreach ($this->viewsNamedIn($src) as $view) {
                $path = $this->pathOf($view);

                if ($path !== null && $this->onDiskButNotInGit($path, $tracked)) {
                    $missing[] = $view.'  ('.$path.')  ← '.$relative;
                }
            }
        }

        /*
         * ⚠️ খালি জালের পাহারা: ব্লেড পড়াই না হলে নিচের দাবিটা সবসময়
         * সবুজ হত। ⓘ এই প্রকল্পে কয়েকশো ব্লেড — কয়েকটা পড়া মানে লুপটা
         * কিছু একটা এড়িয়ে যাচ্ছে।
         */
        $this->assertGreaterThan(200, $read, 'মাত্র '.$read.'টা ব্লেড পড়া হয়েছে — সুইপ অন্ধ।');

        $missing = array_values(array_unique($missing));
        sort($missing);

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['⛔ কমিট করা ব্লেড এমন ব্লেড ডাকছে যা গিটে নেই:', ''],
            $missing,
            ['',
                'ⓘ ফাইলগুলো ডিস্কে আছে, তাই এখানে সব চলে — লাইভে ৫০০ দেবে।',
                '',
                'চালান: git add -- <ঐ ফাইলগুলোর পাথ>, তারপর একই তালিকায় commit --only।']
        )));
    }

    /**
     * ⭐ পাহারাটা সত্যিই একটা ধরতে পারে — ঠিক ২৫ সেপ্টেম্বরের আকারে।
     *
     * ⓘ সত্যিকারের কমিট করা partial নিয়ে তালিকা থেকে নামটা তুলে নেওয়া
     * হয়, যেন কেউ কমিট করতে ভুলে গেছে। ⚠️ নকল যুক্তি নয় — সুইপ যে
     * তিনটা পদ্ধতি ডাকে, এখানেও সেগুলোই।
     */
    public function test_the_guard_catches_the_band_total_shape(): void
    {
        $tracked = $this->trackedFiles();
        $src = "@if (\$banded)\n    @include('print.partials.band-total', ['label' => 'x'])\n@endif";

        $this->assertContains('print.partials.band-total', $this->viewsNamedIn($src),
            '`@include`-এর নামটাই তোলা যাচ্ছে না — জালটা ছেঁড়া।');

        $path = $this->pathOf('print.partials.band-total');

        $this->assertSame('resources/views/print/partials/band-total.blade.php', $path);
        $this->assertArrayHasKey($path, $tracked, 'partial-টা গিটে নেই — নমুনাটাই ভুল, আগে কমিট করুন।');
        $this->assertFalse($this->onDiskButNotInGit($path, $tracked),
            'কমিট করা ফাইলকে পাহারা অপরাধী বলছে — তাহলে প্রতিটা রান লাল হত।');

        unset($tracked[$path]);

        $this->assertTrue($this->onDiskButNotInGit($path, $tracked),
            'ফাইলটা ডিস্কে আছে অথচ গিটে নেই, আর পাহারা ধরল না — লাইভে আবার ৫০০।');
    }

    /** ⭐ পাঁচ রকম ডাক, আর মডিউলের নামস্থান — প্রতিটা আলাদা করে। */
    public function test_the_reader_finds_every_shape_of_include(): void
    {
        $src = implode("\n", [
            "@extends('layouts.print')",
            "@include('print.partials.head')",
            "@includeWhen(\$x, 'print.partials.when')",
            "@includeUnless(\$x, \"print.partials.unless\")",
            "@include('sales::direct.partials.totals', ['a' => 1])",
            '@include($dynamic)',
            "{{-- @include('print.partials.in_a_comment') --}}",
        ]);

        $found = $this->viewsNamedIn($src);

        foreach (['layouts.print', 'print.partials.head', 'print.partials.when',
            'print.partials.unless', 'sales::direct.partials.totals'] as $name) {
            $this->assertContains($name, $found, "`{$name}` তোলা যাচ্ছে না।");
        }

        $this->assertNotContains('print.partials.in_a_comment', $found,
            'মন্তব্যের ভিতরের ডাকও তোলা হচ্ছে — মিথ্যা অভিযোগ আসবে।');

        $this->assertSame(
            'app/Modules/Sales/Resources/views/direct/partials/totals.blade.php',
            $this->pathOf('sales::direct.partials.totals'),
        );

        $this->assertSame(
            'app/Modules/SystemAdmin/Resources/views/role/form.blade.php',
            $this->pathOf('system_admin::role.form'),
            'দুই-শব্দের মডিউল কোড ভুল ফোল্ডারে মিলছে।',
        );
    }

    /**
     * ব্লেডে আক্ষরিক নাম ধরে যে ভিউগুলো ডাকা হয়।
     *
     * ⚠️ মন্তব্য (`{{-- … --}}`) আগে মুছে ফেলা হয় — এই প্রকল্পে মন্তব্যে
     * পুরনো ডাকের উল্লেখ প্রচুর, আর সেগুলো ধরলে পাহারা মিথ্যা বলত।
     *
     * @return list<string>
     */
    private function viewsNamedIn(string $src): array
    {
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);

        preg_match_all(
            '/@(?:include|extends|includeIf|includeFirst)\s*\(\s*[\'"]([A-Za-z0-9_.:\-]+)[\'"]/',
            $src,
            $direct,
        );

        preg_match_all(
            '/@(?:includeWhen|includeUnless)\s*\([^,]+,\s*[\'"]([A-Za-z0-9_.:\-]+)[\'"]/',
            $src,
            $conditional,
        );

        return array_values(array_unique(array_merge($direct[1], $conditional[1])));
    }

    /** ভিউয়ের নাম → প্রকল্পের ভিতরের পথ; অচেনা নামস্থানে null। */
    private function pathOf(string $view): ?string
    {
        if (str_contains($view, '::')) {
            [$namespace, $name] = explode('::', $view, 2);
            $base = 'app/Modules/'.Str::studly($namespace).'/Resources/views/';
        } else {
            $name = $view;
            $base = 'resources/views/';
        }

        return $base.str_replace('.', '/', $name).'.blade.php';
    }

    /** @param  array<string, true>  $tracked */
    private function onDiskButNotInGit(string $path, array $tracked): bool
    {
        return ! isset($tracked[$path]) && is_file(base_path($path));
    }

    /**
     * HEAD-এ কমিট করা ফাইল — ইনডেক্স থেকে নয়।
     *
     * ⓘ কারণ ভাইবোন পাহারায় লেখা: শেয়ার করা ইনডেক্সে পুরনো ছায়া বসে
     * থাকে, আর প্রশ্নটা হলো "ফাইলটা পুশ হবে কি না" — উত্তর HEAD-এ।
     *
     * @return array<string, true>
     */
    private function trackedFiles(): array
    {
        $out = [];

        exec('git -C '.escapeshellarg(base_path()).' ls-tree -r --name-only HEAD app resources 2>&1', $lines, $code);

        if ($code !== 0) {
            return [];
        }

        foreach ($lines as $line) {
            $out[trim($line)] = true;
        }

        return $out;
    }
}
