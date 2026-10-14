<?php

declare(strict_types=1);

namespace App\Modules\Executive\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * একটা ঘরের "কোথায় যাবে" — [[OpenController]]-এর `go` মান।
 *
 * ⓘ পাতার প্রতিটা ঘর একই ফর্মের একটা বোতাম, আর বোতামের মান একটাই — তাই কোম্পানি, শাখা, পাতার নাম
 * আর ঠিকানার অংশ একটা কোয়েরি-লেখায় বাঁধা। খালি অংশ বাদ যায় (শাখা নেই = সব শাখা)।
 */
final class Go
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function to(int $company, ?int $branch, string $route, array $params = []): string
    {
        $params = array_filter($params, fn ($v) => $v !== null);

        /*
         * ⛔ পাতার দরকারি অংশ না থাকলে পথটাই নেই — খালি মান, দরজা যাচাইয়েই ফেরায়, কোম্পানি না বদলে। আগে দরজা আগে কোম্পানি
         * বদলাত, তারপর ঠিকানা বানাতে গিয়ে ৫০০ (ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[ALinkWithoutItsPageNumberDoesNotCrashTest]])।
         */
        if (! self::complete($route, $params)) {
            return '';
        }

        return http_build_query(array_filter(
            ['company' => $company, 'branch' => $branch, 'route' => $route, 'params' => $params],
            fn ($v) => $v !== null && $v !== [],
        ));
    }

    /**
     * পাতার প্রতিটা দরকারি অংশ (`{product}`; ঐচ্ছিক `{x?}` নয়) হাতে আছে কি না।
     *
     * @param  array<string, mixed>  $params
     */
    private static function complete(string $name, array $params): bool
    {
        $route = Route::getRoutes()->getByName($name);

        // ⓘ অচেনা নাম দরজা নিজেই ফেরায় — এখানে নয়
        if ($route === null) {
            return true;
        }

        preg_match_all('/\{(\w+)\}/', $route->uri(), $needed);

        foreach ($needed[1] as $part) {
            if (! array_key_exists($part, $params) && ! array_key_exists($part, $route->defaults)
                && ! array_key_exists($part, URL::getDefaultParameters())) {
                return false;
            }
        }

        return true;
    }
}
