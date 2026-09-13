<?php

declare(strict_types=1);

use App\Core\Support\DateFormat;
use App\Modules\SystemAdmin\Dashboard\SystemAdminDashboard;

/**
 * System Administration — কোম্পানি, শাখা, ব্যবহারকারী, Control Panel।
 *
 * যেসব সেটিং কোনো একটা ব্যবসায়িক মডিউলের নয় — যেমন ছাপার সাধারণ আচরণ —
 * সেগুলো এখানে ঘোষিত। নাহলে সেটিংটা কারও মালিকানায় থাকত না, আর
 * SettingsService অচেনা কী বলে ব্যতিক্রম ছুঁড়ত।
 */

return [
    'code' => 'system_admin',

    'name' => [
        'en' => 'System Administration',
        'bn' => 'সিস্টেম প্রশাসন',
    ],

    'version' => '1.0.0',

    /*
     * সাইডবারে কোথায় — নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     *
     * একা, আর সবার নিচে — রোজ লাগে না।
     *
     * দলগুলোর তালিকা আর কেন এটা `depends_on`-এর থেকে আলাদা:
     * [[ModuleDefinition::NAV_SECTIONS]].
     */
    'nav' => ['section' => 'system', 'order' => 10],

    /*
     * ── master_data কেন, ৪ সেপ্টেম্বর ২০২৬ ────────────────────────────
     * সেটআপের পর্দা ক্রেতাকে খাতার মুদ্রা বেছে নিতে দেয়, আর তালিকাটা
     * আসে [[MasterListService::CURRENCIES]] থেকে — অর্থাৎ যে তালিকাটা
     * নতুন কোম্পানিতে সারি হিসেবে বসে। ⓘ দুই জায়গায় লিখলে ক্রেতা এমন
     * একটা মুদ্রা বাছতে পারতেন যেটা পরে বসেই না।
     *
     * ⚠️ নির্ভরতাটা আগে **অঘোষিত** ছিল: `SetupController` ক্লাসটা
     * ব্যবহার করত অথচ এখানে `[]` লেখা ছিল, আর [[BoundariesTest]] সেটা
     * ধরেছে — *"module.php বলছে আমি একা চলি, অথচ কোড অন্য কথা বলছে"*।
     *
     * ⓘ ঘোষণাটা কেবল কাগজ নয়, **বুট ও মাইগ্রেশনের ক্রমও**
     * ([[ModuleRegistry::sortByDependency()]]): SystemAdmin এখন
     * master_data-র পরে বুট করে। SystemAdmin-এর নিজের কোনো
     * `provisions` নেই, তাই ক্রম বদলে কিছু নড়ে না — মেপে দেখা হয়েছে।
     */
    'depends_on' => ['master_data'],

    'dashboard' => SystemAdminDashboard::class,

    'menu' => [
        'dashboard' => [
            ['label' => 'system_admin::dashboard.title', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'system_admin'], 'permission' => 'system_admin.settings.manage'],
        ],

        'master' => [
            /*
             * কোম্পানি ও শাখা — একটাই পর্দা, দুইটা নয়।
             *
             * শাখা কোম্পানির ভেতরের জিনিস; আলাদা পাতায় রাখলে প্রথম
             * প্রশ্নটাই হত "কোন কোম্পানির শাখা?", আর উত্তরটা দিতে
             * আরেকটা বাছাইয়ের ঘর লাগত। কোম্পানির পাতাতেই তার শাখাগুলো
             * থাকলে প্রশ্নটাই ওঠে না।
             */
            ['label' => 'system_admin::menu.companies', 'route' => 'system_admin.company.index', 'permission' => 'system_admin.company.manage'],
            ['label' => 'system_admin::menu.users', 'route' => 'system_admin.user.index', 'permission' => 'system_admin.user.manage'],
            ['label' => 'system_admin::menu.roles', 'route' => 'system_admin.role.index', 'permission' => 'system_admin.role.manage'],
            /*
             * ⓘ সারিটা ব্যবহারকারী ও ভূমিকার **পরে**, কারণ কাজটা বছরে
             * একবারও হয় না — আর যে কাজ রোজ লাগে না, সেটা তালিকার মাথায়
             * বসলে রোজকার কাজগুলো একটা ঘর নিচে নেমে যায়।
             */
            ['label' => 'system_admin::menu.ownership', 'route' => 'system_admin.ownership.show', 'permission' => 'system_admin.ownership.transfer'],
        ],
        /*
         * নিরীক্ষার পর্দাগুলো এখানে নেই — Governance-এ আছে।
         *
         * ── কী সরানো হলো, আর কেন ────────────────────────────────────
         * এখানে চারটা সারি `planned` হিসেবে ঘোষিত ছিল: কার্যক্রমের
         * খাতা, লগইনের ইতিহাস, অর্থবছর, নম্বর সিরিজ। চারটাই
         * **ইতিমধ্যে তৈরি** — কেবল অন্য মডিউলে:
         *
         *   কার্যক্রমের খাতা → governance.audit.index
         *   লগইনের ইতিহাস   → governance.login.index
         *   অর্থবছর          → accounts.year_end.index
         *   নম্বর সিরিজ      → master_data.series.index
         *
         * সারিগুলো ছিল পড়ে থাকা প্রতিশ্রুতি: কাজটা অন্য নামে শিপ
         * হওয়ার পর এখানকার ঘোষণাটা মুছতে ভুলে গেছে।
         *
         * ── কেন লিংক না বসিয়ে সরানো হলো ─────────────────────────────
         * এক পর্দা দুই মেনুতে থাকলে "কোনটা আসল" প্রশ্ন ওঠে, আর একটা
         * বদলালে অন্যটা পুরনো থেকে যায়। এক পর্দা, এক মালিক।
         *
         * ── আর `planned`-এর মেয়াদ নেই, সেটাই আসল রোগ ─────────────────
         * পতাকাটা `ModuleMenuTest`-কে বলে "রুট নেই, স্বাভাবিক" — যা
         * লেখার সপ্তাহে সত্যি আর তারপর চিরকাল মিথ্যা। পাঁচটার চারটাই
         * বাসি ছিল, কেউ টের পায়নি।
         */
        'reports' => [
            ['label' => 'system_admin::menu.report_schedules', 'route' => 'system_admin.reports.schedule.index',
                'permission' => 'system_admin.reports.schedule'],
        ],
        'settings' => [
            ['label' => 'core.import.title', 'route' => 'system_admin.import.index',
                'permission' => 'system_admin.import.manage'],
            ['label' => 'system_admin::menu.control_panel', 'route' => 'system_admin.control-panel', 'permission' => 'system_admin.settings.manage'],

            /*
             * ⭐ প্রতিষ্ঠানের সেটিংস — কন্ট্রোল প্যানেলের ঠিক পরে, ৭ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ ক্রমটা ইচ্ছাকৃত: কন্ট্রোল প্যানেল ঠিক করে **কোন পর্দাগুলো
             * থাকবে**, আর এটা ঠিক করে **সেই পর্দাগুলো কীভাবে চলবে**। ⓘ উল্টো
             * ক্রমে মানুষ এমন মডিউলের নিয়ম বসাতেন যেটা তিনি এখনো চালুই করেননি।
             *
             * ⛔ একই চাবি (`settings.manage`) — দুইটা পর্দাই একই প্রশ্নের উত্তর
             * দেয়, আর আলাদা চাবি দিলে কাউকে অর্ধেক উত্তর দেওয়ার অধিকার দেওয়া হত।
             */
            ['label' => 'system_admin::settings.title', 'route' => 'system_admin.settings',
                'permission' => 'system_admin.settings.manage'],

            /*
             * ⭐ নম্বর সিরিজ — কন্ট্রোল প্যানেলের ঠিক পাশে।
             *
             * মালিকের নিয়ম: *"মাস্টারে তৈরি হয়, কন্ট্রোল প্যানেলে
             * নিয়ন্ত্রণ হয়।"* ⓘ এই পর্দায় `create` নেই, `destroy` নেই —
             * কেবল দেখা আর সম্পাদনা। অর্থাৎ ওটা নিয়ন্ত্রণ, তৈরি নয়।
             *
             * ⚠️ চাবিটা `system_admin.settings.manage` — `master_data.manage`
             * নয়, আর সেটা ঘোষণার পাহারাটাই শিখিয়ে দিল:
             *
             *   *"menu item asks for permission 'master_data.manage',
             *   which this module does not declare. Nobody would ever be
             *   granted it, so the item would be invisible to every user."*
             *
             * ⭐ পাহারাটা ঠিক — এক মডিউল অন্যের চাবি দিয়ে সারি বসালে
             * সেটা কারো কাছেই দেখা যেত না। ⓘ আর অর্থটাও মেলে: পর্দাটা
             * এখন নিয়ন্ত্রণের, তাই নিয়ন্ত্রণের চাবিই।
             *
             * ⚠️ কন্ট্রোলারের নিজের middleware এখনো `master_data.manage`
             * দেখে — অর্থাৎ মেনুতে দেখা আর ভিতরে ঢোকা দুইটা আলাদা চাবি।
             * ⓘ ওটা ইচ্ছাকৃতভাবে ছোঁয়া হয়নি: কন্ট্রোলারের চাবি বদলানো
             * মানে কার হাতে ক্ষমতা যাবে সেই সিদ্ধান্ত, আর সেটা মালিকের।
             */
            ['label' => 'master_data::menu.number_series', 'route' => 'master_data.series.index',
                'permission' => 'system_admin.settings.manage'],
            ['label' => 'core.custom_field.title', 'route' => 'system_admin.custom_field.index', 'permission' => 'system_admin.settings.manage'],
            ['label' => 'core.look.title', 'route' => 'system_admin.look.index', 'permission' => 'system_admin.look.manage'],
            /* ব্যাকআপের সারি সরেছে — এখন নিজের মডিউলে ([[Backup/module.php]]) */

            /*
             * ── প্রথম দরজার (`/setup`) কোনো সারি এখানে নেই, আর সেটা
             *    ইচ্ছাকৃত (৩ সেপ্টেম্বর ২০২৬) ─────────────────────────
             *
             * সারিটা **চিরকাল মৃত** হত, আর যুক্তিটা বৃত্তাকার:
             *
             *   মেনু দেখতে হলে লগইন লাগে
             *   → লগইন থাকা মানে অন্তত একজন ব্যবহারকারী আছেন
             *   → ব্যবহারকারী থাকা মানে `/setup` ৪০৪ ([[SetupController]])
             *
             * অর্থাৎ যে মুহূর্তে কেউ সারিটা **দেখতে** পারতেন, ঠিক সেই
             * মুহূর্তেই ওটা আর কোথাও নিয়ে যেত না — ১০০% ব্যবহারকারীর
             * জন্য, ১০০% সময়। `ModuleMenuTest::test_every_menu_row_
             * actually_opens()` ওটা ধরে লাল হত, আর ঠিকই হত।
             *
             * ⚠️ এটা ঠিক সেই রোগ যেটার কথা এই ফাইলের উপরেই লেখা আছে —
             * "পড়ে থাকা প্রতিশ্রুতি"। `planned` পতাকা দিয়েও ঢাকা যেত
             * না: পর্দাটা **আছে**, শুধু ওই দর্শকের জন্য নেই।
             *
             * দরজায় পৌঁছানোর পথ মেনু নয়, আর হওয়ার কথাও নয় — ইনস্টল
             * করার পর ব্রাউজারে ঠিকানাটা খোলা, একবার।
             */
        ],
    ],

    'permissions' => [
        'system_admin.company.manage',
        'system_admin.user.manage',
        /*
         * ── মালিকানা হস্তান্তরের নিজের অনুমতি, ব্যবহারকারী-ব্যবস্থাপনার
         *    সাথে নয় ────────────────────────────────────────────────────
         * ⓘ `system_admin.user.manage` দিয়ে গেট করলে যে কেউ ব্যবহারকারী
         * সামলাতে পারেন তিনিই মেনুতে সারিটা দেখতেন — অথচ কাজটা কেবল
         * **বর্তমান মালিকই** করতে পারেন।
         *
         * ⚠️ তবু অনুমতিটা একা যথেষ্ট নয়, আর সেটাই নকশা: `owner` রোল
         * সব অনুমতি পায়, কিন্তু কেউ চাইলে এই অনুমতিটা অন্য রোলেও বসাতে
         * পারেন। ⛔ তাই আসল পাহারা পরিচয়ে — `OwnershipController` নিজে
         * দেখে অনুরোধকারী সত্যিই এই কোম্পানির মালিক কি না, আর
         * `Ownership::transfer()` লেখার মুহূর্তে আবার দেখে।
         * অনুমতিটা কেবল মেনুর সারিটা লুকিয়ে রাখে।
         */
        'system_admin.ownership.transfer',
        'system_admin.role.manage',
        'system_admin.settings.manage',
        /*
         * রূপের নিজের অনুমতি, সাধারণ সেটিংসের সাথে নয়।
         *
         * এটাই একমাত্র সেটিং যা **সবার পর্দা** এক মুহূর্তে বদলে দেয়,
         * আর ভুল হলে কেউ কাজ করতে পারেন না। যিনি ছাপার কাগজ বা
         * তারিখের ছক ঠিক করেন, তাঁকে ওই ক্ষমতাটাও দিতে হবে এমন নয়।
         */
        'system_admin.look.manage',
        // ইমপোর্টের নিজের অনুমতি: একসাথে দুই হাজার সারি বসানো সেটিংস
        // বদলানোর চেয়ে ভিন্ন ক্ষমতা, আর ভুল ফাইল দিলে ফল অনেক বড়
        'system_admin.import.manage',
        'system_admin.audit.view',
        'system_admin.backup.manage',
        // নির্ধারিত রিপোর্টের সূচি বানানো ও চালানো — অন্য সেটিংস থেকে আলাদা,
        // কারণ এটা ঠিক করে কার কাছে কোন ব্যবসায়িক সংখ্যা নিজে থেকে পৌঁছাবে
        'system_admin.reports.schedule',
    ],

    'doc_types' => [],

    'drill_sources' => [],

    'settings' => [
        [
            'key' => 'print.show_vendor_credit',
            'label' => 'core.print.show_vendor_credit',
            'type' => 'boolean',
            // ডিফল্টে চালু, কিন্তু বন্ধ করা যায় — কিছু প্রতিষ্ঠান কর বা
            // সরকারি কাগজে বাইরের কোনো নাম রাখতে চায় না (সেকশন ১৭.২)।
            'default' => true,
            'group' => 'print',
        ],
        [
            'key' => 'print.default_paper',
            'label' => 'system_admin::settings.default_paper',
            'type' => 'string',
            'default' => 'a4',
            'group' => 'print',
        ],
        /*
         * তারিখ ও সময়ের ছক — মালিকের নির্দেশ (২০২৬-০৮-০৭)।
         *
         * ১৮/০২/২০২৬, ০২/১৮/২০২৬, Feb 18, 2026 — তিনটাই চলতে হবে। কারণটা
         * বাস্তব: ০২/০৩ দেখে কেউ বলতে পারে না ওটা ২ মার্চ না ৩ ফেব্রুয়ারি,
         * আর ওই ভুলটা একটা চেকের তারিখে ঘটলে টাকা ভুল দিনে যায়।
         *
         * group 'general' — এটা প্রতিষ্ঠানের নিজের রীতি, ছাপার সিদ্ধান্ত
         * নয়। ছাপা কাগজও এই একই ছকই মানে, নইলে পর্দায় এক তারিখ আর
         * কাগজে আরেক তারিখ দেখা যেত।
         *
         * ছকগুলোর তালিকা DateFormat-এ, এখানে নয় — সেটিংস কেবল বাছাইটা
         * জমা রাখে, আর কোনটা বৈধ তা ওখানেই যাচাই হয়।
         */
        [
            'key' => 'company.date_format',
            'label' => 'system_admin::settings.date_format',
            'type' => 'string',

            /*
             * তালিকাটা DateFormat থেকে, এখানে হাতে লেখা নয়।
             *
             * দুই জায়গায় লিখলে একদিন একটাতে নতুন ছক যোগ হত আর অন্যটাতে
             * হত না — তখন পর্দায় বাছা যেত এমন একটা ছক যেটা যাচাইয়ে
             * বাতিল, বা উল্টোটা। নমুনাগুলোও ওখানেই বানানো, তাই "d/m/Y"
             * এর পাশে "১৮/০২/২০২৬" সবসময় সত্যি।
             */
            'options' => [DateFormat::class, 'dateOptions'],
            'default' => 'd/m/Y',
            'group' => 'general',
        ],
        [
            'key' => 'company.time_format',
            'label' => 'system_admin::settings.time_format',
            'type' => 'string',
            'options' => [DateFormat::class, 'timeOptions'],
            'default' => 'h:i A',
            'group' => 'general',
        ],
        [
            'key' => 'system.auto_logout_minutes',
            'label' => 'system_admin::settings.auto_logout_minutes',
            'type' => 'integer',
            // সেকশন ৮-এর চেকলিস্ট
            'default' => 15,
            'group' => 'general',
        ],
        [
            /*
             * প্রতিষ্ঠানের নিজের নোটিশ — নিচের বারে সবার চোখে পড়ে।
             *
             * যেমন: "ওভার ডিউ আছে ও যাদের লেনদেন খারাপ তাদের বাকি দেওয়া
             * নিষেধ"। এটা সিস্টেমের কোনো অবস্থা নয়, প্রতিষ্ঠানের সিদ্ধান্ত —
             * তাই এটা লেখার জায়গা সেটিংস, আর দেখার জায়গা প্রতিটা পাতা।
             *
             * এখানে রাখা হয়েছে বারের ভেতরে সম্পাদনার বোতাম বসানোর বদলে:
             * সেটিংস এক জায়গায় থাকে, chrome-এর ভেতরে নয়।
             */
            'key' => 'system.notice',
            'label' => 'system_admin::settings.notice',
            'type' => 'string',
            'default' => '',
            'group' => 'general',
        ],
    ],
];
