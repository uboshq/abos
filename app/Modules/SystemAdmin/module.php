<?php

declare(strict_types=1);

use App\Core\Support\DateFormat;

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

    'depends_on' => [],

    'menu' => [
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
            ['label' => 'system_admin::menu.financial_years', 'route' => 'admin.financial-years', 'permission' => 'system_admin.company.manage', 'planned' => true],
            ['label' => 'system_admin::menu.number_series', 'route' => 'admin.number-series', 'permission' => 'system_admin.company.manage', 'planned' => true],
        ],
        'reports' => [
            ['label' => 'system_admin::menu.activity_log', 'route' => 'admin.activity', 'permission' => 'system_admin.audit.view', 'planned' => true],
            ['label' => 'system_admin::menu.login_history', 'route' => 'admin.logins', 'permission' => 'system_admin.audit.view', 'planned' => true],
        ],
        'settings' => [
            ['label' => 'core.import.title', 'route' => 'system_admin.import.index',
                'permission' => 'system_admin.import.manage'],
            ['label' => 'system_admin::menu.control_panel', 'route' => 'system_admin.control-panel', 'permission' => 'system_admin.settings.manage'],
            ['label' => 'core.custom_field.title', 'route' => 'system_admin.custom_field.index', 'permission' => 'system_admin.settings.manage'],
            ['label' => 'core.look.title', 'route' => 'system_admin.look.index', 'permission' => 'system_admin.look.manage'],
            ['label' => 'system_admin::menu.backup', 'route' => 'admin.backup', 'permission' => 'system_admin.backup.manage', 'planned' => true],
        ],
    ],

    'permissions' => [
        'system_admin.company.manage',
        'system_admin.user.manage',
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
