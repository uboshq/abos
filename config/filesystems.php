<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),

            /*
             * ব্যক্তিগত ডিস্ক সার্ভ হয় না — নামটাই সেটা বলছিল, সেটিংসটা নয়।
             *
             * ── কী ছিল, আর তার দাম ──────────────────────────────────
             * `serve => true` লারাভেলের ডিফল্ট, আর সেটা দুইটা রুট
             * বসায়: `GET /storage/{path}` ও `PUT /storage/{path}` —
             * **কোনো `auth` ছাড়া**। এই ডিস্কে থাকে সব সংযুক্তি: চেকের
             * ছবি, NID, সরবরাহকারীর কাগজ, যা কোম্পানি তোলে।
             *
             * ফাইলের নাম UUID, তাই অনুমান করে বের করা যেত না — কিন্তু
             * সেটা নিরাপত্তা নয়, আড়াল। একটা ঠিকানা একবার ফাঁস হলে
             * (লগে, ব্রাউজারের ইতিহাসে, referrer হেডারে, কারও পাঠানো
             * লিংকে) সেটা চিরকাল খোলা থাকত — লগইন ছাড়াই, অন্য
             * কোম্পানির লোকের কাছেও।
             *
             * ── কেন কিছু ভাঙে না ────────────────────────────────────
             * সংযুক্তির নিজের রুট আছে (`attachment.download`), আর সেটা
             * AttachmentEngine দিয়ে যায় — লগইন, কোম্পানি স্কোপ ও
             * অনুমতি তিনটাই সেখানে দেখা হয়। কাঁচা রুটটা ওই তিনটাই
             * পাশ কাটাত। পুরো কোডবেসে কেউ `storage.local` বা এই
             * ডিস্কের `url()` ডাকে না।
             *
             * ছবি ও লোগোর জন্য আলাদা `public` ডিস্ক — ওগুলো সত্যিই
             * সবার দেখার জিনিস, আর ওগুলো এখানে থাকে না।
             */
            'serve' => false,

            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            /*
             * মূল-আপেক্ষিক, পরম নয়।
             *
             * APP_URL ধরে পরম URL বানালে ছবিগুলো শুধু ওই একটা হোস্টনেম
             * থেকেই আসত। ABOS চলে অফিসের LAN সার্ভারে — একই অ্যাপে ঢোকা
             * হয় নামে, 127.0.0.1-এ, আর ফোন থেকে সার্ভারের IP-তে।
             * পরম URL-এ শেষ দুইটায় প্রতিটা ছবি ভাঙা থাকত।
             *
             * ইমেইল বা বাইরে পাঠানো লিংকে পরম ঠিকানা লাগলে সেখানে
             * url() দিয়ে বানাতে হবে — ডিফল্ট এটা নয়।
             */
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
