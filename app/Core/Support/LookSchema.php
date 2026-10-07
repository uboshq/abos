<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Support\Facades\File;

/**
 * একটা রূপ কোন টোকেনগুলো বলতে পারে, আর কোন আকারে — থিম ইঞ্জিনের ধাপ ১।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * CSS-এ অচেনা টোকেনের নাম **কিছুই করে না**। `--color-surfase-app` লিখলে
 * ব্রাউজার নীরবে ওটা রেখে দেয়, কেউ পড়ে না, আর পর্দায় রংটা ডিফল্ট থেকে
 * আসে। কোনো ভুল বার্তা নেই, কোনো লাল দাগ নেই — কেবল একটা রূপ যেটা
 * দেখতে প্রায় ঠিক।
 *
 * প্রায়-ঠিক জিনিসটাই সবচেয়ে খারাপ, কারণ ওটা ধরতে হলে দুইটা রূপ পাশাপাশি
 * রেখে চোখে মেলাতে হয়।
 *
 * ধাপ ৩-এ যখন মানুষ পর্দা থেকে রূপ বানাবেন, তখন এটা নীরব থাকা চলবে না।
 * তাই স্কিমাটা এখনই, সম্পাদনার পর্দার অনেক আগে।
 *
 * ── নামের তালিকাটা হাতে লেখা হয় না ───────────────────────────────────
 * `tokens.css`-এই ২২০টা টোকেন ঘোষিত, আর ওটাই চুক্তি (প্ল্যানের ১ নং
 * শর্ত: টোকেনের নাম দুই ইঞ্জিনে হুবহু এক)। হাতে দ্বিতীয় একটা তালিকা
 * রাখলে একদিন একটায় নাম যোগ হত আর অন্যটায় হত না — আর তখন স্কিমাই
 * সঠিক টোকেন ফিরিয়ে দিত।
 *
 * তাই তালিকাটা ফাইল থেকেই পড়া হয়।
 */
final class LookSchema
{
    /** @var array<string, string>|null */
    private static ?array $kinds = null;

    /**
     * একটা রূপের টোকেনগুলো যাচাই — ভুলগুলোর তালিকা ফেরে, খালি মানে ঠিক।
     *
     * ── কেন ব্যতিক্রম নয়, তালিকা ─────────────────────────────────────
     * সম্পাদনার পর্দায় মানুষ একসাথে অনেকগুলো ঘর ভরেন। প্রথম ভুলে
     * ব্যতিক্রম ছুঁড়লে তিনি একটা একটা করে দশবার সেভ করতেন, আর প্রতিবার
     * একটা নতুন ভুল জানতেন।
     *
     * @param  array<string, string>  $tokens
     * @return list<string>
     */
    public static function complaints(array $tokens): array
    {
        $said = [];

        $kinds = self::kinds();

        foreach ($tokens as $name => $value) {
            $name = (string) $name;
            $value = trim((string) $value);

            if (! isset($kinds[$name])) {
                $said[] = __('core.look.unknown_token', ['name' => $name]);

                continue;
            }

            if ($value === '') {
                $said[] = __('core.look.empty_token', ['name' => $name]);

                continue;
            }

            $wants = $kinds[$name];
            $got = self::kindOf($value);

            /*
             * `free` মানে ধরনটা ধরা যায়নি — গ্রেডিয়েন্ট, ছায়া,
             * `none`, রূপান্তর। ওগুলোয় দাবি করার মতো কিছু নেই, আর
             * আন্দাজে দাবি করলে সঠিক মানও ফিরে যেত।
             */
            if ($wants === 'free' || $got === 'free' || $wants === $got) {
                continue;
            }

            $said[] = __('core.look.wrong_kind', [
                'name' => $name,
                'wants' => __('core.look.kind_'.$wants),
                'value' => $value,
            ]);
        }

        return $said;
    }

    /**
     * প্রতিটা টোকেনের নাম ও তার **ধরন** — ডিফল্ট মান দেখে বোঝা।
     *
     * ── কেন হাতে একটা তালিকা রাখা হয় না ─────────────────────────────
     * প্রথম লেখায় দৈর্ঘ্যের টোকেনগুলোর একটা হাতে-লেখা তালিকা ছিল, আর
     * সেটা সাথে সাথেই ফাঁকি দিল: `--stage-gap`, `--stage-overlap`,
     * `--spacing-command`, `--table-head-spacing` চারটাই বাদ পড়েছিল,
     * তাই স্কিমা সঠিক মানগুলোকেই "রং চাই" বলে ফিরিয়ে দিচ্ছিল।
     *
     * অথচ উত্তরটা ফাইলেই লেখা: `--row-height: 44px` ঘোষণাটাই বলে দেয়
     * ওটা একটা মাপ। তাই ধরনটা ডিফল্ট মান থেকে পড়া হয় — নতুন টোকেন
     * যোগ হলে দ্বিতীয় কোথাও কিছু লিখতে হয় না।
     *
     * ── ফলব্যাকসহ ব্যবহৃত টোকেনগুলোও চেনা ──────────────────────────
     * কিছু টোকেন ইচ্ছাকৃতভাবে `:root`-এ ঘোষিত নয়, কেবল ফলব্যাকসহ
     * ব্যবহার হয় — `var(--cmd-font, 13px)`, `var(--grid-pad-y-head,
     * var(--grid-pad-y))`। ওগুলো ঐচ্ছিক: রূপ চাইলে বলে, না বললে
     * ফলব্যাকটা চলে।
     *
     * ঘোষণা না খুঁজে কেবল `--x:` খুঁজলে ওগুলো "অচেনা" হত, আর একটা
     * সঠিক রূপ সেভ করাই যেত না। তাই `var(--x, …)` থেকেও নাম ও ধরন
     * দুইটাই তোলা হয়।
     *
     * @return array<string, string> নাম => colour|length|number|free
     */
    public static function kinds(): array
    {
        if (self::$kinds !== null) {
            return self::$kinds;
        }

        $kinds = [];

        foreach (['tokens.css', 'themes.css', 'app.css'] as $file) {
            $path = resource_path('css'.DIRECTORY_SEPARATOR.$file);

            if (! is_file($path)) {
                continue;
            }

            /*
             * মন্তব্য আগে ছেঁটে ফেলা।
             *
             * ব্যাখ্যায় টোকেনের নাম লেখা থাকে (`--color-module-{code}`
             * ধরনের ছাঁচও), আর ওগুলো ঘোষণা নয়। না ছাঁটলে স্কিমা এমন
             * নাম চিনত যা কোথাও সংজ্ঞায়িত নয়।
             */
            $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) File::get($path));

            preg_match_all('/(--[a-zA-Z0-9_-]+)\s*:\s*([^;{}]+)[;}]/', $css, $said, PREG_SET_ORDER);

            foreach ($said as [, $name, $value]) {
                $kinds[$name] ??= self::kindOf(trim($value));
            }

            // ফলব্যাকসহ ব্যবহার — `var(--cmd-font, 13px)`
            preg_match_all('/var\(\s*(--[a-zA-Z0-9_-]+)\s*,\s*([^)]+)\)/', $css, $used, PREG_SET_ORDER);

            foreach ($used as [, $name, $fallback]) {
                $kinds[$name] ??= self::kindOf(trim($fallback));
            }
        }

        /*
         * অ্যাকসেন্টের ঘরগুলোও চেনা।
         *
         * ওগুলো `<html style="…">`-এ ইনলাইনে বসে, CSS ফাইলে নয়, তাই
         * উপরের পড়াটা ধরত না। রূপ চাইলে ওগুলোও বদলাতে পারে।
         */
        foreach (Accent::all() as $accent) {
            foreach (array_keys($accent['scale']) as $step) {
                $kinds['--accent-'.$step] ??= 'free';
            }
        }

        foreach (['--accent-ink', '--accent-dark', '--accent-dark-600', '--accent-dark-ink'] as $name) {
            $kinds[$name] ??= 'free';
        }

        ksort($kinds);

        return self::$kinds = $kinds;
    }

    /** চেনা নামগুলো — পর্দা ও পরীক্ষার জন্য। @return list<string> */
    public static function known(): array
    {
        return array_keys(self::kinds());
    }

    /**
     * একটা মান দেখে ধরন বলা।
     *
     * `free` মানে "ধরা গেল না" — গ্রেডিয়েন্ট, ছায়া, `none`, রূপান্তর।
     * ওগুলোয় দাবি করার মতো কিছু নেই, আর আন্দাজে দাবি করলে সঠিক মানও
     * ফিরে যেত।
     */
    private static function kindOf(string $value): string
    {
        if (preg_match('/^-?\d*\.?\d+(px|rem|em|%|vh|vw|ch)$/', $value) === 1) {
            return 'length';
        }

        /*
         * একক ছাড়া একটা সংখ্যা — ধরা যায় না, তাই দাবিও করা হয় না।
         *
         * ── কেন এটা `number` বললে ভুল হত ─────────────────────────
         * `0` CSS-এ একটা বৈধ দৈর্ঘ্য (শূন্যের একক লাগে না), আবার
         * `--state-fill: 0` একটা অনুপাত, আর `--stage-tile: 1` একটা
         * গুণক। একই লেখা, তিন রকম মানে।
         *
         * প্রথম লেখায় `0`-কে দৈর্ঘ্য ধরা হয়েছিল, আর স্কিমা সাথে
         * সাথেই দুইটা **সঠিক** মানকে ভুল বলল। যে পাহারা সঠিক জিনিস
         * ফিরিয়ে দেয় সেটা কয়েকদিনের মধ্যেই বন্ধ করে দেওয়া হয়।
         *
         * তাই এখানে চুপ থাকা — আর যেখানে সত্যিই বলা যায় (`#fff` একটা
         * মাপের ঘরে, `44px` একটা রঙের ঘরে) সেখানে কড়া।
         */
        if (preg_match('/^-?\d*\.?\d+$/', $value) === 1) {
            return 'free';
        }

        if (preg_match('/^(#[0-9a-fA-F]{3,8}|transparent|currentColor)$/', $value) === 1
            || preg_match('/^(rgb|rgba|hsl|hsla|oklch|color-mix)\(/', $value) === 1) {
            return 'colour';
        }

        return 'free';
    }
}
