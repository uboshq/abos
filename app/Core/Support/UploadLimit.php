<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ ছবি তোলার সীমা — হাতে লেখা নয়, সার্ভার যা মানে তা থেকে (fe-র মাপা লাইভ PHP, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ভাঙত ─────────────────────────────────────────────────────────
 * লাইভে `post_max_size` ৮ MB, `upload_max_filesize` ৫০ MB, আর আমাদের দরজা লিখেছিল "ছবি ১২ MB পর্যন্ত"। ⓘ ৮ MB-র বেশি
 * পাঠালে PHP **গোটা POST** ফেলে দেয় — ফর্মের একটা ঘরও আসে না, `_token`-ও না; মানুষ দেখতেন ৪১৯ বা কিছুই না, আর জানতেন না কেন।
 *
 * ⭐ এখন একটাই সংখ্যা: min(১২ MB, post_max_size, upload_max_filesize) − ২৫৬ KB (ফর্মের বাকি ঘরের জায়গা)। দরজার নিয়ম,
 * ঘরের পাশের "সর্বোচ্চ X MB", ব্রাউজারের আগাম আপত্তি আর বড় POST-এর ফেরা — চারটাই এখান থেকে পড়ে।
 *
 * ⓘ পরীক্ষায় সীমা নামাতে `config('abos.uploads.post_max_size')` / `upload_max_filesize` (ini-র মতো লেখা, যেমন "1M") —
 * চলমান PHP-তে upload-এর ini নামানো যায় না, তাই এই দুইটা থাকলে ini-র বদলে এগুলোই পড়া হয়।
 */
final class UploadLimit
{
    /** দরজার সর্বোচ্চ — সার্ভার আরও দিলেও ছবি এর বেশি নয় (ইঞ্জিন ছোট করে, কিন্তু অস্বাভাবিক ফাইল ঠেকাতে) */
    public const PICTURE_CEILING = 12 * 1024 * 1024;

    /** SVG — ইঞ্জিনে ছোট হয় না, তাই ১ MB (মালিকের মাপ) */
    public const SVG_CEILING = 1024 * 1024;

    /** ফর্মের বাকি ঘর (নাম, ঠিকানা, `_token`) আর multipart-এর মোড়কের জায়গা */
    public const FORM_MARGIN = 256 * 1024;

    /** গোটা POST-এর সর্বোচ্চ বাইট; ০ মানে সীমা নেই */
    public static function postMax(): int
    {
        return self::bytes(self::setOr('abos.uploads.post_max_size', 'post_max_size'));
    }

    /** একটা ফাইলের সর্বোচ্চ বাইট; ০ মানে সীমা নেই */
    public static function fileMax(): int
    {
        return self::bytes(self::setOr('abos.uploads.upload_max_filesize', 'upload_max_filesize'));
    }

    /**
     * কনফিগের বদল, নইলে ini।
     *
     * ⛔ খালিটাও "নেই" — `.env.example`-এর খালি `ABOS_POST_MAX_SIZE=` `.env`-এ কপি হলে env() দেয় `''`, আর `??` সেখানে ini-তে
     * ফিরত না: `bytes('')` = ০ = "সীমা নেই", তাই লাইভের ৮ MB-র POST-সীমা পর্দা থেকে নিঃশব্দে উধাও হত।
     */
    private static function setOr(string $key, string $ini): string
    {
        $set = trim((string) config($key));

        return $set !== '' ? $set : (string) ini_get($ini);
    }

    /** ছবির (PNG/JPG/WebP) সীমা, বাইটে */
    public static function pictureBytes(): int
    {
        $limits = array_filter([self::PICTURE_CEILING, self::postMax(), self::fileMax()], fn (int $n): bool => $n > 0);

        return max(0, min($limits) - self::FORM_MARGIN);
    }

    /** SVG-র সীমা, বাইটে — ১ MB, কিন্তু ছবির সীমার বেশি কখনো নয় */
    public static function svgBytes(): int
    {
        return min(self::SVG_CEILING, self::pictureBytes());
    }

    /** Laravel-এর `max:` নিয়মের জন্য, KB-তে */
    public static function kilobytes(int $bytes): int
    {
        return intdiv($bytes, 1024);
    }

    /** পর্দার সংখ্যা — MB, ইংরেজি অঙ্কে, বড়জোর এক দশমিক, নিচের দিকে (৭.৭৫ → "7.7"; সীমা কখনো বাড়িয়ে বলা হয় না) */
    public static function megabytes(int $bytes): string
    {
        $tenths = intdiv($bytes * 10, 1024 * 1024);

        return $tenths % 10 === 0 ? (string) intdiv($tenths, 10) : sprintf('%d.%d', intdiv($tenths, 10), $tenths % 10);
    }

    /** ini-র লেখা ("8M", "512K", "1G", "8388608") → বাইট */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'K' => $number * 1024,
            'M' => $number * 1024 * 1024,
            'G' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
    }
}
