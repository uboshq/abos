<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * ক্রয় দর, বিক্রয় দর, মার্কআপ ও মার্জিন — একটাই সূত্র-ঘর।
 *
 * ── কেন মডেলে accessor নয়, আলাদা helper ─────────────────────────────
 * সূত্রটা এক জায়গায় থাকা দরকার — নাহলে ফর্মের JS একরকম গুনত, পণ্যের
 * তালিকা আরেকরকম, আর একদিন দুইটা আলাদা উত্তর দিত। কিন্তু মডেলে বসালে
 * ছবি-আপলোডের কাজের সাথে একই ফাইলে ঠোকাঠুকি হত; তাই সূত্রটা এখানে, আর
 * তালিকা-পর্দা-ফর্ম সবাই এখান থেকেই ডাকে।
 *
 * ── কেন কিছুই "স্টোর" হয় না, শুধু গোনা হয় ───────────────────────────
 * মার্কআপ/মার্জিন কলামে বসালে দর একবার বদলানোর পর সংখ্যাটা পুরনো হয়ে
 * যেত আর তালিকা মিথ্যা বলত। তাই ডাটাবেজে থাকে কেবল ক্রয় ও বিক্রয় দর;
 * শতাংশ দুইটা সবসময় ওই দুই থেকে **এখনই** গোনা — কখনো সংরক্ষিত নয়।
 *
 * ── মার্কআপ আর মার্জিন এক নয় ────────────────────────────────────────
 * ১০০ টাকার মাল ১৫০-এ:
 *   মার্কআপ = ৫০/১০০ = ৫০%     (ক্রয়ের উপরে কত চড়ল — দোকানদারের ভাষা)
 *   মার্জিন  = ৫০/১৫০ = ৩৩.৩৩%  (বিক্রয়ের কত অংশ লাভ — হিসাবরক্ষকের ভাষা)
 *
 * ── পাঁচটা ফাঁদ, পাঁচটাই এখানে আটকানো ───────────────────────────────
 *   ১ · ক্রয় শূন্য       → মার্কআপ অসীম; গোনা হয় না, null
 *   ২ · বিক্রয় শূন্য      → মার্জিন অসীম; null
 *   ৩ · মার্কআপ ≤ −১০০%  → বিক্রয় শূন্য বা নিচে; null
 *   ৪ · মার্জিন ≥ ১০০%    → হর শূন্য বা ঋণাত্মক, অসীম বিক্রয়; null
 *                          (কেউ "১০০% লাভ" ভেবে মার্জিনে ১০০ বসান — বেশি ঘটে)
 *   ৫ · রাউন্ডিং          → এগুলো প্রদর্শিত মান; সত্য হলো ব্যবহারকারী যে
 *                          দরটা লিখেছেন (ফর্মের JS ঠিক করে কোনটা ধ্রুব)
 *
 * সব হিসাব bcmath-এ, scale 4 — দর `decimal(…,4)`, float নয়।
 */
final class Margin
{
    /** দর ও শতাংশের মাপ — Product-এর decimal:4-এর সাথে মেলানো। */
    private const SCALE = 4;

    /**
     * ক্রয়ের উপরে কত শতাংশ চড়ল — (বিক্রয় − ক্রয়) ÷ ক্রয় × ১০০।
     *
     * ক্রয় শূন্য বা ঋণাত্মক হলে null: শূন্য দিয়ে ভাগ হয় না, আর "৳০ মালের
     * উপর কত লাভ" প্রশ্নটারই মানে নেই (ফাঁদ ১)।
     */
    public static function markup(?string $cost, ?string $sale): ?string
    {
        if (! self::positive($cost) || ! self::number($sale)) {
            return null;
        }

        return self::pct(bcsub((string) $sale, (string) $cost, self::SCALE), (string) $cost);
    }

    /**
     * বিক্রয়ের কত অংশ লাভ — (বিক্রয় − ক্রয়) ÷ বিক্রয় × ১০০।
     *
     * বিক্রয় শূন্য বা ঋণাত্মক হলে null (ফাঁদ ২)।
     */
    public static function margin(?string $cost, ?string $sale): ?string
    {
        if (! self::positive($sale) || ! self::number($cost)) {
            return null;
        }

        return self::pct(bcsub((string) $sale, (string) $cost, self::SCALE), (string) $sale);
    }

    /**
     * মার্কআপ থেকে বিক্রয় দর — ক্রয় × (১০০ + মার্কআপ) ÷ ১০০।
     *
     * মার্কআপ −১০০%-এ নামলে দর শূন্য, তার নিচে ঋণাত্মক — কোনোটাই দর নয়,
     * তাই null (ফাঁদ ৩)।
     */
    public static function saleFromMarkup(?string $cost, ?string $markup): ?string
    {
        if (! self::positive($cost) || ! self::number($markup)) {
            return null;
        }

        if (bccomp((string) $markup, '-100', self::SCALE) <= 0) {
            return null;
        }

        return bcdiv(
            bcmul((string) $cost, bcadd('100', (string) $markup, self::SCALE), self::SCALE),
            '100',
            self::SCALE,
        );
    }

    /**
     * মার্জিন থেকে বিক্রয় দর — ক্রয় × ১০০ ÷ (১০০ − মার্জিন)।
     *
     * ⚠️ মার্জিন ১০০%-এ হর শূন্য (অসীম দর), তার উপরে হর ঋণাত্মক (ঋণাত্মক
     * দর)। কেউ "১০০% লাভ" ভেবে এখানে ১০০ বসান — মার্কআপের সাথে গুলিয়ে।
     * দুই ক্ষেত্রেই null (ফাঁদ ৪)।
     */
    public static function saleFromMargin(?string $cost, ?string $margin): ?string
    {
        if (! self::positive($cost) || ! self::number($margin)) {
            return null;
        }

        if (bccomp((string) $margin, '100', self::SCALE) >= 0) {
            return null;
        }

        return bcdiv(
            bcmul((string) $cost, '100', self::SCALE),
            bcsub('100', (string) $margin, self::SCALE),
            self::SCALE,
        );
    }

    /** পার্থক্যকে একটা ভিত্তির শতাংশে — ভিত্তি আগেই ধনাত্মক প্রমাণিত। */
    private static function pct(string $diff, string $base): string
    {
        return bcdiv(bcmul($diff, '100', self::SCALE), $base, self::SCALE);
    }

    private static function number(?string $value): bool
    {
        return $value !== null && $value !== '' && is_numeric($value);
    }

    private static function positive(?string $value): bool
    {
        return self::number($value) && bccomp((string) $value, '0', self::SCALE) > 0;
    }
}
