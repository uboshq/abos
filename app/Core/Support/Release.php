<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * ⭐ কোন সংস্করণ চলছে — config/release.php-র নম্বর আর তারিখ, আর deploy-এর লেখা commit (মালিক, ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ লাইভে .git নেই, তাই কোন commit চলছে তা deploy একটা ফাইলে লেখে: storage/app/release-build.json `{sha, deployed_at}`।
 * ⛔ ফাইল না থাকলে, পড়া না গেলে বা sha-র রূপ ভুল হলে sha কেবল দেখানো হয় না — কোনো পাতা ভাঙে না।
 */
final class Release
{
    public static function version(): string
    {
        return (string) config('release.version', '0.0.0');
    }

    /** YYYY-MM-DD, যেমন config-এ */
    public static function date(): string
    {
        return (string) config('release.date', '');
    }

    /** দেখানোর তারিখ — দিন-মাস-বছর, ইংরেজি অঙ্কে (মালিকের নিয়ম) */
    public static function shownDate(): string
    {
        try {
            return Carbon::createFromFormat('Y-m-d', self::date())->format('d-m-Y');
        } catch (Throwable) {
            return '';
        }
    }

    /** deploy-এর লেখা commit-এর ছোট রূপ (৭ অক্ষর), নইলে null */
    public static function sha(): ?string
    {
        $path = storage_path('app/release-build.json');

        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $sha = is_array($data) ? strtolower(trim((string) ($data['sha'] ?? ''))) : '';

        return preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1 ? substr($sha, 0, 7) : null;
    }

    /** "v1.0.0 · 24-10-2026 · 2c026a3" — যা জানা আছে কেবল সেটুকু */
    public static function label(): string
    {
        return implode(' · ', array_filter(['v'.self::version(), self::shownDate(), self::sha()], fn ($part) => $part !== null && $part !== ''));
    }

    /** @return array{version: string, date: string, sha: string|null} — ফোনের API-র জন্য */
    public static function forApi(): array
    {
        return ['version' => self::version(), 'date' => self::date(), 'sha' => self::sha()];
    }
}
