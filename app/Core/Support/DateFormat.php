<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * তারিখ ও সময় কোন ছকে দেখানো হবে — কোম্পানি ঠিক করে।
 *
 * ── কেন এটা সেটিংস, ধ্রুবক নয় ───────────────────────────────────────
 * মালিকের নির্দেশ (২০২৬-০৮-০৭): ১৮/০২/২০২৬, ০২/১৮/২০২৬, Feb 02, 2026 —
 * তিনটাই চলতে হবে, আর বাছাইটা নিয়ন্ত্রণ প্যানেলে।
 *
 * কারণটা বাস্তব: বাংলাদেশে দিন-মাস-বছর, কিন্তু যে ডিপো বিদেশি সরবরাহকারীর
 * সাথে কাগজ মেলায় তার হয়তো মাস-দিন-বছর লাগে। আর "Feb 02, 2026" পড়তে
 * কোনো অনুমান লাগে না — ০২/০৩ দেখে কেউ বলতে পারে না ওটা ২ মার্চ না ৩
 * ফেব্রুয়ারি, আর ওই ভুলটা একটা চেকের তারিখে ঘটলে টাকা ভুল দিনে যায়।
 *
 * ── কেন ৫৭ জায়গায় হাতে লেখা 'd/m/Y' সরানো হলো ───────────────────────
 * ছকটা এক জায়গায় না থাকলে সেটিংস বদলে অর্ধেক পর্দা বদলাত আর বাকি
 * অর্ধেক পুরনো ছকে থাকত — আর সেটা ছক না থাকার চেয়েও খারাপ, কারণ তখন
 * এক পাতায় দুই রকম তারিখ দেখে মানুষ কোনটা বিশ্বাস করবে জানে না।
 *
 * ── যেগুলো এখানে আসে না ─────────────────────────────────────────────
 * ফর্মের <input type="date"> সবসময় Y-m-d — ওটা ব্রাউজারের নিজের ছক,
 * মানুষের নয়; ব্রাউজার সেটাকে ব্যবহারকারীর দেশের ছকে দেখায়। আর
 * ফাইলের নামে Y-m-d-His, কারণ ওতে সাজালে সময়ের ক্রমেই সাজে।
 */
final class DateFormat
{
    /**
     * যে ছকগুলো বেছে নেওয়া যায়।
     *
     * চাবিটাই PHP-র ছক-স্ট্রিং, তাই সেটিংসে যা জমা থাকে সেটা সরাসরি
     * format()-এ যায় — মাঝখানে কোনো অনুবাদের টেবিল নেই, আর তাই
     * একটা বাদ পড়ে যাওয়ার সুযোগও নেই।
     *
     * @return array<string, string> ছক => নমুনা
     */
    public static function dateOptions(): array
    {
        $sample = Carbon::create(2026, 2, 18);

        return collect(['d/m/Y', 'm/d/Y', 'd-m-Y', 'Y-m-d', 'M d, Y', 'd M Y'])
            ->mapWithKeys(fn (string $f) => [$f => $sample->format($f)])
            ->all();
    }

    /**
     * ঘড়ি — বারো নাকি চব্বিশ ঘণ্টা।
     *
     * ডিপোর কাউন্টারে "রাত ৮টা" বলা হয়, "২০:০০" নয়; কিন্তু হিসাবের
     * কাগজে ২৪ ঘণ্টা পড়তে ভুল কম হয়। তাই দুইটাই থাকে।
     *
     * @return array<string, string>
     */
    public static function timeOptions(): array
    {
        $sample = Carbon::create(2026, 2, 18, 20, 5);

        return collect(['h:i A', 'H:i'])
            ->mapWithKeys(fn (string $f) => [$f => $sample->format($f)])
            ->all();
    }

    /** কোম্পানির বেছে নেওয়া তারিখের ছক। */
    public static function date(): string
    {
        return self::pick('company.date_format', self::dateOptions(), 'd/m/Y');
    }

    /** কোম্পানির বেছে নেওয়া সময়ের ছক। */
    public static function time(): string
    {
        return self::pick('company.time_format', self::timeOptions(), 'h:i A');
    }

    /**
     * একটা তারিখ, কোম্পানির ছকে।
     *
     * null এলে খালি লেখা — ড্যাশ নয়। ড্যাশ বসালে টেবিলের ঘরে সেটা
     * "শূন্য" বলে পড়া যেত, অথচ মানে "জানা নেই"। যেখানে ড্যাশ দরকার
     * সেখানে ডাকা পক্ষই বসায়, কারণ সে জানে ফাঁকা মানে কী।
     */
    public static function format(Carbon|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::carbon($value)->format(self::date());
    }

    /** তারিখ ও সময়, দুইটাই কোম্পানির ছকে। */
    public static function formatWithTime(Carbon|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::carbon($value)->format(self::date().' '.self::time());
    }

    private static function carbon(Carbon|string $value): Carbon
    {
        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    /**
     * সেটিংসের মান, কিন্তু কেবল ঘোষিত ছকগুলোর একটা হলে।
     *
     * ── কেন যাচাই ───────────────────────────────────────────────────
     * ছকটা সরাসরি PHP-র format()-এ যায়। ডাটাবেজে যা আছে তাই মেনে নিলে
     * কেউ (বা কোনো পুরনো মাইগ্রেশন) সেখানে অদ্ভুত কিছু রেখে গেলে
     * প্রতিটা তারিখ আবর্জনা হয়ে ছাপা হত — আর ছাপা কাগজে সেটা আর
     * ফেরানো যায় না।
     *
     * @param  array<string, string>  $allowed
     */
    private static function pick(string $key, array $allowed, string $fallback): string
    {
        $chosen = (string) app(SettingsService::class)->get($key, $fallback);

        return array_key_exists($chosen, $allowed) ? $chosen : $fallback;
    }
}
