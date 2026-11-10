<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

/**
 * ⓘ ব্রাউজারের লম্বা লেখা (User-Agent) থেকে মানুষের পড়ার মতো নাম — "Chrome · Windows", "ABOS অ্যাপ · Android"।
 * ⓘ পুরো লেখাটা খাতায় থাকে, পাতায় সারির ওপর ধরলে দেখা যায় — এখানে কেবল ছোট নাম।
 */
final class Device
{
    public static function name(?string $agent, ?string $channel = null): string
    {
        $ua = (string) $agent;

        if ($ua === '' && $channel === 'system') {
            return (string) __('assistant::audit.system');
        }

        if ($ua === '') {
            return '—';
        }

        $app = $channel === 'app' || str_contains($ua, 'Dart/') || str_contains($ua, 'okhttp') || str_contains($ua, 'ABOS');
        $browser = match (true) {
            $app => (string) __('assistant::audit.app'),
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => (string) __('assistant::audit.browser'),
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return $os === '' ? $browser : $browser.' · '.$os;
    }
}
