<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alpine-এর হ্যান্ডলার কেবল x-data-র ভেতরেই চলে।
 *
 * ── যে ভুলটা এই পাহারা ধরে ───────────────────────────────────────────
 * ফেরতের দুইটা পর্দায় ইনভয়েস/বিলের ড্রপডাউনে `@change` লেখা ছিল, আর
 * তার কাজ ছিল ওই কাগজের লাইনগুলো নিয়ে পাতাটা আবার খোলা। কিন্তু Alpine
 * কোনো অ্যাট্রিবিউট পড়ে না যতক্ষণ না তার উপরে কোথাও `x-data` থাকে —
 * আর ওই দুইটা ফর্মে সেটা ছিল না।
 *
 * ফল: বাছাই করলে কিচ্ছু হত না। কনসোলে এরর নেই, পর্দা দেখতে নিখুঁত,
 * শুধু দর হাতে টাইপ করতে হত — অথচ পর্দার নিজের লেখা বলত "দর বিলের দর
 * থেকেই আসে"। পরীক্ষক দুইবার এটা ধরেছেন, দুইবারই "অটো-ফিল হয় না" বলে।
 *
 * ── কেন এটা টেস্টে ধরা কঠিন ──────────────────────────────────────────
 * সার্ভার ঠিকই কাজ করে: ঠিকানায় `?sales_invoice_id=` দিলে লাইনগুলো
 * ভরে আসে। ভাঙাটা কেবল ব্রাউজারে, আর কেবল ওই একটা ক্লিকে। তাই
 * সোর্স থেকেই ধরতে হয়।
 */
class AlpineHandlersHaveAScopeTest extends TestCase
{
    /**
     * Alpine যেসব উপসর্গ চেনে — সবগুলোরই x-data লাগে।
     *
     * @var list<string>
     */
    private const ALPINE_MARKERS = [
        'x-on:', 'x-model', 'x-show', 'x-text', 'x-html', 'x-if', 'x-for',
        'x-bind:', 'x-transition', 'x-ref', 'x-init', 'x-cloak', 'x-effect',
    ];

    public function test_no_blade_file_uses_an_alpine_handler_without_x_data(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);

            if ($source === false || $this->hasScope($source)) {
                continue;
            }

            /*
             * `@click` জাতীয় সংক্ষিপ্ত রূপ আর `x-` উপসর্গ — দুইটাই।
             *
             * `@change`-এর মতো লেখা Blade-এর নির্দেশ নয় বলে হুবহু
             * HTML-এ চলে যায়, আর Alpine ছাড়া কেউ ওটা পড়ে না।
             */
            $found = [];

            if (preg_match('/\s@(click|change|input|submit|keydown|keyup|blur|focus)[.=]/', $source, $m)) {
                $found[] = '@'.$m[1];
            }

            foreach (self::ALPINE_MARKERS as $marker) {
                if (str_contains($source, ' '.$marker)) {
                    $found[] = $marker;
                }
            }

            if ($found === []) {
                continue;
            }

            /*
             * পার্শিয়ালের `x-data` তাকে যে ডাকে তার কাছে থাকতে পারে।
             *
             * ---- কেন এই ছাড়টা লাগল, ৩০ আগস্ট ২০২৬ ----
             * পরীক্ষাটা এক ফাইল ধরে ধরে পড়ে, কিন্তু Blade-এর
             * `@include` রানটাইমে ফাইলটাকে ডাকার জায়গার **ভেতরে**
             * বসিয়ে দেয়। তাই একটা পার্শিয়ালে `x-model` থাকলেও তার
             * নিজের `x-data` লাগে না -- যে ফর্মটা তাকে ডাকে তার
             * থাকলেই যথেষ্ট, আর ব্রাউজারে সেটা কাজও করে।
             *
             * ছাড়টা না দিলে পরীক্ষাটা মিথ্যা অভিযোগ করত, আর মিথ্যা
             * অভিযোগ করা পাহারা কিছুদিনের মধ্যে বন্ধই করে দেওয়া হয় --
             * তারপর সে আর আসল ভুলটাও ধরে না।
             *
             * ---- আর ছাড়টা যেন ফাঁক না হয় ----
             * **সব** ডাকার জায়গায় `x-data` থাকতে হবে। একটাতেও না
             * থাকলে ওই পথে গিয়ে জিনিসটা নীরবে মরত, আর সেটাই মূল
             * ভুলটা। ডাকার জায়গা একটাও না থাকলেও অভিযোগ -- তখন
             * পার্শিয়ালটা কেউ ব্যবহারই করে না।
             */
            if ($this->everyIncluderHasScope($file)) {
                continue;
            }

            $offenders[] = $this->relative($file).' → '.implode(', ', array_unique($found));
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই ফাইলগুলোয় Alpine-এর হ্যান্ডলার আছে কিন্তু x-data নেই।',
            'Alpine ওগুলো পড়বেই না — কোনো এরর ছাড়াই কিছুই ঘটবে না।',
            'হয় একটা x-data মোড়ক দিন, নয় সাধারণ onclick/onchange লিখুন।',
        ]));
    }

    /**
     * `$el` দিয়ে অন্য ঘর খোঁজা — তিনবার একই ভুল।
     *
     * ── কেন এটা প্রতিবারই নীরবে মারে ────────────────────────────────
     * Alpine-এ `$el` মানে **যে এলিমেন্ট থেকে অভিব্যক্তিটা চলছে**। একটা
     * `@input="recount()"` হ্যান্ডলারের ভেতরে সেটা ওই input, আর
     * `@click`-এর ভেতরে ওই বোতাম। কেউই নিজের ভেতরে অন্য ঘর রাখে না,
     * তাই querySelectorAll সবসময় খালি ফেরে — যোগফল ০ থাকে, বোতাম
     * নিষ্ক্রিয় থাকে, আর কনসোলে একটা অক্ষরও ওঠে না।
     *
     * এভাবেই তিনটা জায়গা মরেছিল: নগদ গণনা (সেভই হত না), জাবেদা
     * ভাউচার (সেভই হত না), আর সরাসরি বিক্রয়ের focusField। কম্পোনেন্টের
     * গোড়া চাইলে `$root`।
     */
    public function test_no_component_hunts_for_other_fields_through_el(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            // মন্তব্যে লেখা থাকতে পারে (ঠিক কী ভুল হয়েছিল তার ব্যাখ্যায়),
            // তাই কেবল সত্যিকারের ডাকগুলোই দেখা হয়: this.$el.querySelector
            if (preg_match('/(?<![\w.])this->\$el|this\.\$el\.querySelector/', $source)) {
                foreach (explode("\n", $source) as $no => $line) {
                    if (str_contains($line, 'this.$el.querySelector')
                        && ! str_starts_with(ltrim($line), '*')
                        && ! str_contains($line, '//')) {
                        $offenders[] = $this->relative($file).':'.($no + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এখানে this.$el দিয়ে অন্য ঘর খোঁজা হচ্ছে।',
            'হ্যান্ডলারের ভেতরে $el হলো যে ঘরে ঘটনাটা ঘটেছে সেটাই —',
            'তার ভেতরে অন্য ঘর থাকে না, তাই তালিকা সবসময় খালি আসবে।',
            'কম্পোনেন্টের গোড়া চাইলে $root ব্যবহার করুন।',
        ]));
    }

    /**
     * `$refs.x` ডাকা হচ্ছে অথচ `x-ref="x"` কোথাও নেই।
     *
     * ── যে ভুলটা এই পাহারা ধরে ──────────────────────────────────────
     * সরাসরি ক্রয়ের পর্দায় পণ্য যোগ করার পর কার্সরটা সার্চ ঘরে ফেরত
     * যাওয়ার কথা ছিল: `this.$refs.search.focus()`। কিন্তু ঘরটা ছিল
     * `<template x-if>`-এর ভেতরে, আর Alpine ক্লোন করা টেমপ্লেটের
     * `x-ref` বাইরের কম্পোনেন্টে তোলে না। তাই `$refs.search` ছিল
     * `undefined`।
     *
     * ── কেন এটা শুধু কার্সর হারানোর চেয়ে অনেক বেশি ──────────────────
     * `undefined.focus()` একটা TypeError ছোড়ে, আর ওটা পড়ে ঠিক মাঝখানে
     * — লাইনটা কার্টে বসার পর, কিন্তু ফর্মের লুকানো ঘরগুলো তৈরি হওয়ার
     * আগে। পর্দায় লাইনটা দেখা যেত, যোগফলও ঠিক আসত, অথচ "Confirm"
     * চাপলে সার্ভার বলত "The lines field is required" আর পুরো কার্ট
     * উধাও হয়ে যেত। পরীক্ষক লিখেছেন: "এই সম্পূর্ণ নতুন ফিচারটা এই
     * মুহূর্তে ব্যবহারযোগ্য না।"
     *
     * অর্থাৎ একটা কসমেটিক লাইন পুরো পর্দাটা অকেজো করে দিয়েছিল, আর
     * সার্ভারের কোনো টেস্ট সেটা ধরতে পারত না — ভাঙাটা ব্রাউজারে।
     */
    public function test_every_ref_a_component_uses_actually_exists(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            preg_match_all('/\$refs\.([A-Za-z_][\w]*)/', $source, $uses);

            if ($uses[1] === []) {
                continue;
            }

            preg_match_all('/x-ref="([^"]+)"/', $source, $declared);

            foreach (array_unique($uses[1]) as $ref) {
                if (! in_array($ref, $declared[1], true)) {
                    $offenders[] = $this->relative($file).' → $refs.'.$ref;

                    continue;
                }

                /*
                 * ঘোষিত, কিন্তু টেমপ্লেটের ভেতরে — যা ঘোষিত না থাকারই সমান।
                 *
                 * `<template x-if>` বা `x-for` যা ক্লোন করে, তার `x-ref`
                 * বাইরের কম্পোনেন্ট থেকে দেখা যায় না। খুঁজে পাওয়া কঠিন,
                 * কারণ সোর্সে লেখাটা ঠিকই আছে।
                 */
                if (preg_match(
                    '/<template[^>]*\bx-(if|for)\b[^>]*>(?:(?!<\/template>).)*x-ref="'.preg_quote($ref, '/').'"/s',
                    $source,
                )) {
                    $offenders[] = $this->relative($file).' → $refs.'.$ref.' (x-ref sits inside a <template>)';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই $refs-গুলো কোনো x-ref-এর সাথে মেলে না, তাই undefined আসবে।',
            'তার উপর .focus() বা .value ডাকলে TypeError পড়ে, আর Alpine ওই',
            'হ্যান্ডলারের বাকি কাজটুকু আর করে না — অর্ধেক হয়ে থেমে থাকে।',
            'x-ref যোগ করুন, আর টেমপ্লেটের ভেতরে হলে x-show দিয়ে বাইরে আনুন।',
        ]));
    }

    /** @return list<string> */
    /**
     * সত্যিকারের `x-data` অ্যাট্রিবিউট আছে কি না — মন্তব্যে লেখা নাম নয়।
     *
     * ---- কেন এই পার্থক্যটা লাগল, ৩০ আগস্ট ২০২৬ ----
     * প্রথমে কেবল `str_contains($source, 'x-data')` দেখা হত। কিন্তু
     * এই রেপোর ব্লেড ফাইলগুলোয় মন্তব্যে প্রায়ই `x-data`-র কথা লেখা
     * থাকে ("একটা x-data মোড়ক দিন")।
     *
     * ফল: ছাড়টা ফাঁক হয়ে গিয়েছিল। ডাকার জায়গা থেকে আসল
     * অ্যাট্রিবিউটটা সরিয়ে দিয়ে পরীক্ষা চালালাম -- **সবুজই থাকল**,
     * কারণ মন্তব্যের শব্দটা তখনো ওখানে। ইচ্ছা করে ভেঙে দেখতে গিয়েই
     * ধরা পড়ল।
     *
     * ---- কেন `=` খোঁজা চলে না ----
     * প্রথমে `x-data=` খোঁজা হয়েছিল। কিন্তু Alpine-এ `x-data` একা
     * লিখলেও চলে (`<div x-data>` মানে খালি স্কোপ), আর এই রেপোতেই
     * অনেক ফাইল ওভাবে লেখা -- একষট্টিটা ফাইল হঠাৎ অভিযুক্ত হয়ে গেল।
     *
     * আসল পার্থক্যটা `=` নয়, **জায়গা**: বাক্যগুলো ব্লেড-মন্তব্যের
     * ভেতরে থাকে, অ্যাট্রিবিউট থাকে বাইরে। তাই মন্তব্য ছেঁটে নিয়ে
     * তারপর খোঁজা হয় -- ওটাই সত্যিকারের নিয়ম, আর ওটাই কড়া দিকেও
     * ভুল করে না।
     */
    private function hasScope(string $source): bool
    {
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;

        return str_contains($code, 'x-data');
    }

    /**
     * এই পার্শিয়ালটাকে যারা ডাকে, তাদের সবার কি `x-data` আছে?
     *
     * নামটা ফাইলের পথ থেকেই বানানো হয় -- `.../views/a/b.blade.php`
     * হয় `a.b`, আর মডিউলের হলে `code::a.b`। দুইটা রূপই খোঁজা হয়,
     * কারণ মডিউলের ভেতর থেকে ছোট নামেও ডাকা যায়।
     */
    private function everyIncluderHasScope(string $file): bool
    {
        $name = $this->viewName($file);

        if ($name === null) {
            return false;
        }

        $includers = [];

        foreach ($this->bladeFiles() as $other) {
            if ($other === $file) {
                continue;
            }

            $source = file_get_contents($other);

            if ($source === false) {
                continue;
            }

            foreach ([$name['full'], $name['short']] as $candidate) {
                if ($candidate !== null && str_contains($source, "'".$candidate."'")) {
                    $includers[$other] = $this->hasScope($source);

                    break;
                }
            }
        }

        return $includers !== [] && ! in_array(false, $includers, true);
    }

    /**
     * ফাইলের পথ থেকে ভিউয়ের নাম।
     *
     * @return array{full: ?string, short: string}|null
     */
    private function viewName(string $file): ?array
    {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file);

        if (preg_match('#/app/Modules/([^/]+)/Resources/views/(.+)\.blade\.php$#', $path, $m)) {
            $code = Str::snake($m[1]);
            $short = str_replace('/', '.', $m[2]);

            return ['full' => $code.'::'.$short, 'short' => $short];
        }

        if (preg_match('#/resources/views/(.+)\.blade\.php$#', $path, $m)) {
            return ['full' => null, 'short' => str_replace('/', '.', $m[1])];
        }

        return null;
    }

    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $walker = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($walker as $entry) {
                if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        $this->assertNotEmpty($files, 'No Blade files were checked — the walk is wrong.');

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
