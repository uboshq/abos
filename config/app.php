<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | ডিপোর ঘড়ি — Asia/Dhaka, UTC নয়।
    |
    | ── কী ভাঙা ছিল ────────────────────────────────────────────────────
    | এখানে `'UTC'` **হাতে বসানো** ছিল, অথচ লাইভ সার্ভারের `.env`-এ
    | `APP_TIMEZONE=Asia/Dhaka` লেখা। env-টা পড়াই হত না, তাই ঘোষণাটা
    | কোনো কাজ করত না — আর কেউ টের পেত না, কারণ কোথাও কোনো ভুল বার্তা
    | নেই। কেবল প্রতিটা সময় ছয় ঘণ্টা পিছিয়ে।
    |
    | ── দামটা কত ───────────────────────────────────────────────────────
    | ঢাকার ভোর ৫:৩০ পর্যন্ত অ্যাপের কাছে **আগের দিন**। তার মানে:
    |
    |   · সকাল ১০টার বিলে ছাপা হত ভোর ৪টা
    |   · রাত ১টার বিক্রি আগের দিনের হিসাবে বসত
    |   · ব্যাক-ডেট লক আর দিন-বন্ধ ভুল দিনের সীমা ধরত
    |   · রোজকার ব্যাকআপ চলত ঢাকার সকাল ৭:৩০-এ, রাত ১:৩০-এ নয়
    |
    | ── কেন সংরক্ষণও ঢাকার সময়ে, UTC নয় ────────────────────────────────
    | "UTC-তে রাখো, স্থানীয় সময়ে দেখাও" — বহু দেশের সফটওয়্যারে ওটাই
    | ঠিক। এখানে নয়, দুইটা কারণে:
    |
    |   · MySQL-এর DATETIME ঘরে কোনো অফসেট থাকে না। রূপান্তরটা তাই
    |     অ্যাপের একটা চুক্তি — আর ওই চুক্তি দুই জায়গায় ভুললে (রিপোর্টের
    |     কাঁচা SQL, বা একটা এক্সপোর্ট) সংখ্যাটা নীরবে ছয় ঘণ্টা সরে যায়।
    |   · ABOS-এর প্রতিটা কোম্পানি বাংলাদেশে। একটাই ঘড়ি রাখলে now(),
    |     today(), সংরক্ষণ ও পর্দা — চারটাই একমত, আর মেলানোর কোনো
    |     জায়গাই থাকে না।
    |
    | `companies.timezone` ঘরটা তাই আজ একটা **ঘোষণা, বিকল্প নয়** —
    | `TheClockRanSixHoursBehindTest` ওটাকে config-এর সাথে মিলিয়ে
    | দেখে। একদিন সত্যিই দ্বিতীয় দেশ এলে ওই পরীক্ষাটাই আগে ভাঙবে, আর
    | তখন সিদ্ধান্তটা জেনেশুনে নেওয়া হবে — আবিষ্কার হবে না।
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
