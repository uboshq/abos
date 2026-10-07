<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * ⭐ অ্যাপ বন্ধ থাকলেও ফোনে বার্তা — Firebase Cloud Messaging (২ অক্টোবর ২০২৬, [[FcmSender]])।
     * ⛔ `credentials` কেবল ফাইলের **পথ** — চাবিটা ওয়েব রুটের বাইরের গোপন ফাইলে, এখানে বা .env-এ কখনো নয়।
     * ফাঁকা থাকলে পুশ চুপচাপ বন্ধ।
     */
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS', ''),
        'project_id' => env('FIREBASE_PROJECT_ID', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
