<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * একটা পর্দার ঠিকানা এক লেখায় — `রুটের-নাম` বা `রুটের-নাম:প্যারামিটার` (রিপোর্ট সেন্টার ধাপ ১, ২ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কেন লাগল ─────────────────────────────────────────────────────
 * সংরক্ষিত দৃশ্য ([[SavedView]]) কেবল প্যারামিটারহীন পর্দা চিনত। ⚠️ অথচ প্রতিটা রিপোর্ট একটাই রুটের একটা স্লাগ
 * (`sales.report.show` + `by-customer`) — তাই রিপোর্টের পাতায় "এই দৃশ্যটা রেখে দিন" মেনুটাই আঁকা হত না, আর
 * মালিকের "ছাঁকনি সংরক্ষণ / প্রিয়" রিপোর্টে কোনোদিন পৌঁছাত না।
 *
 * ── ⭐ এখন ──────────────────────────────────────────────────────────
 * একই লেখার রীতি যা অর্থের মানচিত্র এক মাস ধরে লেখে (`accounts.report.show:ledger`) — দৃশ্য আর মানচিত্র এখন একই
 * ভাষায় ঠিকানা বলে, আর ঠিকানা থেকে লিংক বানানো এক জায়গায় ([[url()]])।
 *
 * ⛔ প্যারামিটারসহ পর্দার মধ্যে কেবল রিপোর্ট ([[isReportRoute()]]) দৃশ্য পায় — একটা রেকর্ডের পাতা (`/vouchers/{voucher}`)
 * একটা জিনিসেরই পাতা, তার "দৃশ্য" রাখার মানে নেই, আর অন্যের রেকর্ডের নম্বর ধরে রাখা একটা দরজা হত।
 */
final class ScreenAddress
{
    public const SEPARATOR = ':';

    /** ⓘ রিপোর্টের স্লাগ — ছোট হাতের অক্ষর, অঙ্ক, ড্যাশ; বাকি সব লেখা ঠিকানায় ঢোকে না */
    private const SLUG = '/^[a-z0-9][a-z0-9_-]{0,79}$/';

    /**
     * @return array{0: string, 1: ?string} রুটের নাম আর প্যারামিটার (না থাকলে নাল)
     */
    public static function split(string $address): array
    {
        [$name, $param] = array_pad(explode(self::SEPARATOR, $address, 2), 2, null);

        return [(string) $name, $param === '' ? null : $param];
    }

    /**
     * এই অনুরোধের পর্দার ঠিকানা — দৃশ্য রাখা যায় না এমন পর্দায় নাল।
     */
    public static function of(?RoutingRoute $route): ?string
    {
        $name = $route?->getName();

        if ($route === null || $name === null || $name === '') {
            return null;
        }

        $params = $route->parameterNames();

        if ($params === []) {
            return $name;
        }

        if (! self::isReportRoute($route)) {
            return null;
        }

        $slug = $route->parameter($params[0]);

        return is_string($slug) && preg_match(self::SLUG, $slug) === 1 ? $name.self::SEPARATOR.$slug : null;
    }

    /**
     * ঠিকানাটা কি একটা দৃশ্য রাখার যোগ্য পর্দা — রুট আছে, আর প্যারামিটার থাকলে সেটা রিপোর্টের স্লাগ।
     */
    public static function accepts(string $address): bool
    {
        [$name, $param] = self::split($address);
        $route = Route::getRoutes()->getByName($name);

        // ⛔ খোলা যায় এমন পাতা — লিংকে কেউ POST বা DELETE-এর রুটে যায় না
        if ($route === null || ! in_array('GET', $route->methods(), true)) {
            return false;
        }

        if ($route->parameterNames() === []) {
            return $param === null;
        }

        return $param !== null && self::isReportRoute($route) && preg_match(self::SLUG, $param) === 1;
    }

    /**
     * ঠিকানার লিংক, সাথে সংরক্ষিত কোয়েরি — রুট না থাকলে বা প্যারামিটার না মিললে নাল (মেনু আঁকার সময় ব্যতিক্রম নয়)।
     */
    public static function url(string $address, string $query = ''): ?string
    {
        [$name, $param] = self::split($address);
        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            return null;
        }

        $names = $route->parameterNames();

        if (($names === []) !== ($param === null) || count($names) > 1) {
            return null;
        }

        $base = $param === null ? route($name) : route($name, [$names[0] => $param]);
        $query = ltrim($query, '?&');

        return $query === '' ? $base : $base.'?'.$query;
    }

    /**
     * রিপোর্টের পর্দা — একটাই প্যারামিটার, `slug`, আর নামের শেষে `report.show` (প্রতিটা মডিউলের রিপোর্ট-রুটের রীতি)।
     */
    public static function isReportRoute(RoutingRoute $route): bool
    {
        return $route->parameterNames() === ['slug'] && str_ends_with((string) $route->getName(), 'report.show');
    }
}
