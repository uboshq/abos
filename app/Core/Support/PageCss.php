<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ কোন পাতা কোন CSS পায় — সবাই base.css আর app.css, তার মাঝে কেবল নিজের রূপেরটা আর নিজের পাতারটা
 * (abos-b1, ২৮ নভেম্বর ২০২৬; Lighthouse-এ কাউন্টারের পারফরম্যান্স ৮৭ → ৭৯, প্রতি পাতায় ২৩৭ KiB অব্যবহৃত CSS)।
 *
 * ── কেন ───────────────────────────────────────────────────────────────
 * আগে চারটা রূপের খোলস (ABOS, Blue, SAP, Dynamic), কাউন্টার, Copilot পাতা আর ভাউচার — সব একটা app.css-এ, প্রতিটা পাতায়।
 * যিনি ABOS চালান তিনিও Dynamic-এর ৪৭ KB নামাতেন, আর হোম পাতাও কাউন্টারের ১০০ KB।
 * ⓘ মিটিং আর বার্তা সব পাতায় থাকে (app.css) — তাদের ডাকের পপআপ যেকোনো পাতায় আসে।
 *
 * ── ক্রম কেন ঠিক এটাই ────────────────────────────────────────────────
 * পাতার `<head>`-এ: base.css → রূপ → পাতা → app.css। এটা আগের একটা ফাইলের ভেতরের ক্রমই — রূপের ফাইলগুলো app.css-এর মাথায়
 * `@import` হত, তাই app.css-এর নিজের নিয়মগুলোর (তিন রূপের ভাগ করা `:root:is([data-ui='rose'], …)`) **আগে** বসত, আর একই
 * জোরের নিয়মে app.css জিতত। ⛔ রূপের ফাইলটা app.css-এর পরে বসালে সেই নিয়মগুলো নীরবে হারত — তাই Tailwind আর সবার
 * `@import` এখন base.css-এ (আগে), আর app.css-এর নিয়মগুলো সবার শেষে।
 *
 * ⓘ রূপ বাছা হয় পাতার নিজের `data-ui` থেকে ([[LookRegistry::lookFor()]], প্রিভিউসহ) — তাই প্রিভিউতে দেখানো রূপের CSS-ই আসে।
 * ⓘ যে রূপের নিজের খোলস নেই (Navy, আর রং-বদলানো নকলগুলো), তার কোনো আলাদা ফাইল নেই — তাদের নিয়ম app.css-এই।
 */
final class PageCss
{
    public const HEAD = 'resources/css/base.css';

    public const BODY = 'resources/css/app.css';

    /** @var array<string, string> রূপ (`data-ui`) → তার খোলসের ফাইল */
    public const LOOKS = [
        'rose' => 'resources/css/abos-look.css',
        'blue' => 'resources/css/blue-look.css',
        'tiles' => 'resources/css/fiori-look.css',
        'dynamic' => 'resources/css/dynamic-look.css',
    ];

    /** @var array<string, string> পাতার নাম → তার ফাইল ([[x-page-css]] দিয়ে চাওয়া) */
    public const PAGES = [
        'counter' => 'resources/css/pages/counter.css',
        'copilot' => 'resources/css/copilot-page.css',
        'trade-voucher' => 'resources/css/trade-voucher.css',
    ];

    /** @return list<string> রূপের ফাইল — থাকলে একটা, না থাকলে কিছু না */
    public static function look(string $look): array
    {
        return isset(self::LOOKS[$look]) ? [self::LOOKS[$look]] : [];
    }

    public static function page(string $name): string
    {
        return self::PAGES[$name] ?? throw new \InvalidArgumentException("No page CSS named '{$name}'; known: ".implode(', ', array_keys(self::PAGES)).'.');
    }

    /** @return list<string> vite-এর সব CSS প্রবেশ — vite.config.js-এর তালিকার সাথে মেলে ([[EveryPageCssIsBuiltTest]]) */
    public static function all(): array
    {
        return [self::HEAD, ...array_values(self::LOOKS), ...array_values(self::PAGES), self::BODY];
    }
}
