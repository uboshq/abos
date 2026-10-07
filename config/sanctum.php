<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    /*
     * ⚠️ খালি — ডিফল্ট `['web']` এখানে একটা নিরাপত্তার ফুটো।
     *
     * ── Sanctum ঠিক কী করে ──────────────────────────────────────────
     * এই তালিকার গার্ডগুলো **আগে** চেষ্টা করা হয়; কেউ পারলে Sanctum
     * তার ব্যবহারকারীকেই মেনে নেয়, আর তখন `currentAccessToken()` হয়
     * একটা `TransientToken` — **যার `can()` প্রতিটা ability-তেই `true`
     * বলে**। কোনো টোকেন নেই বলেই সব ক্ষমতা।
     *
     * ── কেন সেটা এখানে ভুল ──────────────────────────────────────────
     * ABOS-এর ওয়েব অ্যাপ Sanctum দিয়ে লগইন করে **না** — সে সাধারণ
     * সেশন-গার্ডে চলে। অর্থাৎ `['web']` রেখে দেওয়ার কোনো লাভ নেই,
     * কিন্তু ক্ষতি আছে: **একটা খোলা ব্রাউজার সেশন থাকলেই
     * `/api/v1/sync/**` খুলে যেত**, আর `abilities:sync` /
     * `abilities:refresh` দুইটাই নীরবে পাশ করত।
     *
     * তখন দুই-টোকেন ব্যবস্থাটার পুরো মানেই থাকত না: চুরি যাওয়া refresh
     * টোকেন আর ৩০ মিনিটের access টোকেন — দুইটাই সমান ক্ষমতার।
     *
     * ── কীভাবে ধরা পড়ল ─────────────────────────────────────────────
     * টেস্ট লিখে, কোড পড়ে নয়। `test_a_refresh_token_cannot_open_the_sync_doors`
     * refresh টোকেন দিয়ে ২০০ পেল যেখানে ৪০৩ পাওয়ার কথা — TransientToken
     * সব ability-তে হ্যাঁ বলছিল।
     *
     * খালি করলে Sanctum সোজা bearer টোকেনেই যায়, যা টোকেন-API-র জন্য
     * ঠিক আচরণ। SPA কুকি-লগইন কোনোদিন লাগলে তখন এখানে ফিরে আসতে হবে —
     * কিন্তু তখন `abilities` আর ওই পথে ভরসা করা যাবে না।
     */
    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
