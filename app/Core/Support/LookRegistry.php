<?php

declare(strict_types=1);

namespace App\Core\Support;

use RuntimeException;

/**
 * দশটা রূপ, ডাটা হিসেবে — থিম ইঞ্জিনের ধাপ ১।
 *
 * ── কী বদলাচ্ছে, আর কী বদলাচ্ছে না ───────────────────────────────────
 * বদলাচ্ছে: মানগুলো **কোথায় থাকে**। আজ পর্যন্ত `themes.css`-এ দশটা
 * `[data-ui='x']` ব্লক; এখন `Looks/` ফোল্ডারে দশটা PHP ফাইল।
 *
 * বদলাচ্ছে না: **মানগুলো নিজে**। প্ল্যানের হার্ড রুল — একটাও পিক্সেল
 * নড়বে না। ফাইলগুলো `themes.css` পড়েই তৈরি, হাতে টোকা নয়, আর
 * `LooksMatchTheStylesheetTest` দুই দিক মিলিয়ে দেখে।
 *
 * ── কেন এটা দরকার ────────────────────────────────────────────────────
 * তিনটা জিনিস CSS ফাইলে অসম্ভব, আর ডাটায় সহজ:
 *
 * ১ · **কেবল চলতি রূপটা পাঠানো।** আজ পাতায় দশটা রূপেরই টোকেন নামে —
 *     যিনি Navy চালান তিনিও Odoo, Fiori ও Linear-এর রং ডাউনলোড করেন।
 *
 * ২ · **ভুল টোকেন সেভের সময় ফেরানো।** CSS-এ অচেনা নাম নীরবে কিছুই
 *     করে না; ডাটায় স্কিমা ধরে যাচাই করা যায়।
 *
 * ৩ · **উত্তরাধিকার।** কোম্পানির নিজের রূপ = Navy + ছয়টা টোকেন বদল,
 *     ষাটটা নয়। ধাপ ২-এর কাজ, কিন্তু ভিতটা এখানেই।
 *
 * ── কেন `Ui` নয়, আলাদা একটা ──────────────────────────────────────────
 * `Ui` বলে রূপগুলো **কী** — নাম, ব্লার্ব, কার নকল, মেনু কোথায়। সেটা
 * পর্দার কথা, আর ওটা বদলায় কদাচিৎ।
 *
 * এটা বলে রূপগুলো **দেখতে কেমন** — ছয়শোর বেশি টোকেন। দুইটা এক ক্লাসে
 * রাখলে একটা রূপের নাম শোধরাতে গিয়ে ছয়শো লাইনের ফাইল খুলতে হত।
 */
final class LookRegistry
{
    /**
     * এক অনুরোধে একবারই পড়া।
     *
     * প্রতিটা পাতায় টোকেন লাগে একবার, কিন্তু `tokens()` একাধিক জায়গা
     * থেকে ডাকা হতে পারে। ফাইল দশবার পড়ার কোনো কারণ নেই।
     *
     * @var array<string, array{light: array<string, string>, dark: array<string, string>}>
     */
    private static array $cache = [];

    /**
     * একটা রূপের সব টোকেন — হালকা ও গাঢ়, আলাদা করে।
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public static function of(string $look): array
    {
        $look = Ui::clean($look);

        if (isset(self::$cache[$look])) {
            return self::$cache[$look];
        }

        $file = self::directory().DIRECTORY_SEPARATOR.$look.'.php';

        if (! is_file($file)) {
            throw new RuntimeException("রূপ '{$look}'-এর টোকেন ফাইলটা নেই: {$file}");
        }

        /** @var array{light?: array<string, string>, dark?: array<string, string>} $said */
        $said = require $file;

        return self::$cache[$look] = [
            'light' => $said['light'] ?? [],
            'dark' => $said['dark'] ?? [],
        ];
    }

    /**
     * একটা রূপ ও একটা থিমের **চূড়ান্ত** টোকেনগুলো।
     *
     * ── কেন হালকাটাও গাঢ়ে লাগে ──────────────────────────────────────
     * গাঢ় ব্লকে কেবল সেই টোকেনগুলো লেখা যেগুলো বদলায় — ধার, ঘনত্ব ও
     * সারির উচ্চতা ওখানে নেই, কারণ রাতে ওগুলো বদলায় না।
     *
     * তাই গাঢ় রূপ মানে "গাঢ় ব্লকটা" নয়, "হালকাটার উপর গাঢ়টা" — নাহলে
     * Redwood রাতে তার ১৬px গোল কোণ হারাত।
     *
     * @return array<string, string>
     */
    public static function tokens(string $look, string $theme = 'light'): array
    {
        $sets = self::of($look);

        return $theme === 'dark'
            ? [...$sets['light'], ...$sets['dark']]
            : $sets['light'];
    }

    /**
     * সব রূপের নাম — `Ui`-এর ক্রমেই।
     *
     * ক্রমটা `Ui` থেকে আসে, ফোল্ডার থেকে নয়: ফাইলের নাম বর্ণানুক্রমে
     * সাজে, আর তাতে বাছাইয়ের পর্দায় ক্লাসিক দ্বিতীয় হয়ে যেত।
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return Ui::keys();
    }

    private static function directory(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'Looks';
    }
}
