<?php

declare(strict_types=1);

use App\Core\Engines\Print\PrintFormat;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Support\DateFormat;
use App\Modules\SystemAdmin\Dashboard\SystemAdminDashboard;
use App\Modules\SystemAdmin\Dashboard\NoticeWidgets;
use App\Modules\SystemAdmin\Reports\NoticeReports;

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

    /*
     * ⭐ এই মডিউলটা বন্ধ করা যায় না — মালিকের নির্দেশ, ২৪ সেপ্টেম্বর ২০২৬।
     *
     * *"সিস্টেম এডমিন বাই ডিফল্ট কোনোভাবে বন্ধ হবে না। একটা লক করে দিও।"*
     *
     * ── ⛔ কী ভাঙত ──────────────────────────────────────────────────
     * কন্ট্রোল প্যানেলের প্রথম ট্যাবে প্রতিটা মডিউলের একটা সুইচ, আর
     * এই মডিউলেরটাও। ⚠️ ওটা বন্ধ করে সংরক্ষণ করলে
     * [[RefuseSwitchedOffScreens]] `system_admin.` উপসর্গের **সব**
     * রুটে ৪০৪ দিত — আর কন্ট্রোল প্যানেল নিজেই ঐ উপসর্গের ভিতরে।
     *
     * ⓘ অর্থাৎ দরজাটা ভিতর থেকে বন্ধ হয়ে যেত, চাবিসহ: ইউজার, রোল,
     * কোম্পানি, সেটিংস — ফেরার কোনো পথ নেই, ডাটাবেসে হাত না দিয়ে।
     *
     * ⚠️ এটা কেবল **গোটা মডিউলের** সুইচ আটকায়। ⓘ ভিতরের পর্দাগুলো
     * (নোটিশ, রিপোর্টের সময়সূচি…) আগের মতোই বন্ধ করা যায় — কারণটা
     * [[ModuleDefinition::$essential]]-এ।
     */
    'essential' => true,

    'menu' => [
        'dashboard' => [
            ['label' => 'system_admin::dashboard.title', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'system_admin'], 'permission' => 'system_admin.settings.manage'],
        ],

        /*
         * ⭐ এই দলটা ভাঁজ হয় না — সারিগুলো সোজা বারে, ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মালিকের কথা: *"System Administration e master group bad diye
         * direct bare menu bosaw"*। ⚠️ চারটা সারির জন্য একটা ড্রপডাউন
         * মানে প্রতিবার একটা বাড়তি ক্লিক, আর চারটা এমনিতেই এক সারিতে ধরে।
         *
         * ⛔ `'loose'` সারি-প্রতি, দল-প্রতি নয় — [[MenuBuilder]]-এ কারণটা।
         */
        'master' => [
            /*
             * কোম্পানি ও শাখা — একটাই পর্দা, দুইটা নয়।
             *
             * শাখা কোম্পানির ভেতরের জিনিস; আলাদা পাতায় রাখলে প্রথম
             * প্রশ্নটাই হত "কোন কোম্পানির শাখা?", আর উত্তরটা দিতে
             * আরেকটা বাছাইয়ের ঘর লাগত। কোম্পানির পাতাতেই তার শাখাগুলো
             * থাকলে প্রশ্নটাই ওঠে না।
             */
            ['label' => 'system_admin::menu.companies', 'icon' => 'building', 'route' => 'system_admin.company.index', 'loose' => true, 'permission' => 'system_admin.company.manage'],
            ['label' => 'system_admin::menu.users', 'icon' => 'people', 'route' => 'system_admin.user.index', 'loose' => true, 'permission' => 'system_admin.user.manage'],
            ['label' => 'system_admin::menu.roles', 'icon' => 'lock', 'route' => 'system_admin.role.index', 'loose' => true, 'permission' => 'system_admin.role.manage'],
            /*
             * ⓘ সারিটা ব্যবহারকারী ও ভূমিকার **পরে**, কারণ কাজটা বছরে
             * একবারও হয় না — আর যে কাজ রোজ লাগে না, সেটা তালিকার মাথায়
             * বসলে রোজকার কাজগুলো একটা ঘর নিচে নেমে যায়।
             */
            ['label' => 'system_admin::menu.ownership', 'icon' => 'handover', 'route' => 'system_admin.ownership.show', 'loose' => true, 'permission' => 'system_admin.ownership.transfer'],
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
            /* নোটিশের দুইটা রিপোর্ট — স্পেক, ধারা ২৯ */
            ['label' => 'core.notice.report_register', 'icon' => 'list', 'route' => 'system_admin.report.show',
                'route_params' => ['slug' => 'notice-register'], 'permission' => 'system_admin.notice.analytics'],
            ['label' => 'core.notice.report_signatures', 'icon' => 'check_circle', 'route' => 'system_admin.report.show',
                'route_params' => ['slug' => 'notice-signatures'], 'permission' => 'system_admin.notice.analytics'],

            ['label' => 'system_admin::menu.report_schedules', 'icon' => 'calendar', 'route' => 'system_admin.reports.schedule.index',
                'permission' => 'system_admin.reports.schedule'],
        ],
        'settings' => [
            ['label' => 'core.import.title', 'route' => 'system_admin.import.index',
                'permission' => 'system_admin.import.manage'],
            /*
             * ⭐ সারিটা বারে, সেটিংসের ভাঁজে নয় — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ মালিকের কথা: *"Control Panel setings er baire thakbe bare"*।
             * ⚠️ দলটা `settings`-ই থাকল, কারণ সেটাই সত্যি — কেবল আঁকার
             * জায়গাটা বদলাল। ⛔ দল বদলালে সেটিংসের পর্দায় সারিটা হারাত।
             */
            ['label' => 'system_admin::menu.control_panel', 'icon' => 'settings',
                'route' => 'system_admin.control-panel', 'loose' => true,
                'permission' => 'system_admin.settings.manage'],

            /*
             * ⭐ শাখার মডিউল — কন্ট্রোল প্যানেলের ঠিক নিচে, ২৮ নভেম্বর ২০২৬।
             *
             * ⓘ ক্রমটা ইচ্ছাকৃত: উপরেরটা ঠিক করে **প্রতিষ্ঠান কোন মডিউল নিয়েছে**,
             * আর এটা ঠিক করে **সেগুলোর কোনটা কোন ডিপোতে চলবে**। ⚠️ উল্টো ক্রমে
             * মানুষ এমন মডিউল শাখায় খুঁজতেন যেটা কোম্পানিই নেয়নি।
             *
             * ⛔ একই চাবি — দুইটা একই প্রশ্নের দুই অর্ধেক।
             */
            ['label' => 'system_admin::branch_module.title', 'icon' => 'building', 'route' => 'system_admin.branch-module',
                'permission' => 'system_admin.settings.manage'],

            /*
             * ⭐ নোটিশ — ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ সারিটা `notice.manage` চাবির পিছনে, অথচ পাতাটা সবাই
             * খুলতে পারেন। ⛔ অসংগতি নয়: মেনুর সারি বলে *"এটা আপনার
             * রোজকার কাজ"*, আর নোটিশ লেখা রোজকার কাজ কেবল প্রশাসকের।
             *
             * ⓘ বাকিরা নোটিশে পৌঁছান নিচের চলন্ত বারের লিংক থেকে —
             * যেখানে নোটিশটা এমনিতেই তাঁদের চোখের সামনে ঘুরছে।
             */
            /*
             * নিচের বারের নোটিশ — বোর্ডের পাশে, আর সেটা ইচ্ছাকৃত।
             *
             * ⓘ দুইটাই বার্তা, কিন্তু আকার আলাদা: বোর্ডে নোটিশের
             * নিজের কাগজ — লেখক, তারিখ, কারা দেখবেন, কে পড়েছেন।
             * ⚠️ এটা একটা লাইন, সবার নিচে, সবসময় — ঘোষণা নয়,
             * দেয়ালে সাঁটা কাগজ।
             */

            /*
             * ⭐ নোটিশের পর্দাগুলো নিজের ভাঁজে, বারেই — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ── ⓘ মালিকের কথা ──────────────────────────────────────
             * *"Notices holo main menu tar vitore sub menu gulo thakbe …
             * Bare Notice er vitore notice er sob thakbe"*।
             *
             * ── ⛔ আগে যা ছিল ───────────────────────────────────────
             * চারটা নোটিশের সারি সেটিংসের ভাঁজে ছড়িয়ে ছিল — কোম্পানির
             * সেটিংস আর নম্বর সিরিজের মাঝখানে। ⚠️ ওরা একসাথে নয় বলে
             * *"নোটিশের জিনিসগুলো কোথায়"* প্রশ্নের উত্তর ছিল
             * *"সেটিংস খুলে খুঁজুন"*।
             *
             * ⓘ `cluster` একই নামের সারিগুলোকে এক ড্রপডাউনে বসায়
             * ([[shell.modulebar]]), আর `loose` সেটাকে বারে তোলে।
             */
            ['label' => 'system_admin::notice.title', 'icon' => 'bell',
                'route' => 'system_admin.notice.index',
                'cluster' => 'notice', 'loose' => true,
                'permission' => 'system_admin.notice.manage'],

            /*
             * নোটিশের হিসাব — কতজন পড়েছেন, কতজন মেনেছেন।
             *
             * ⓘ নিজের চাবি, কারণ সংখ্যাগুলো কর্মীদের নাম ধরে বলে
             * *"কে এখনো মানেননি"* — ⚠️ ওটা সবার দেখার জিনিস নয়।
             */
            ['label' => 'core.notice.analytics_title', 'icon' => 'reports', 'route' => 'system_admin.notice.analytics',
                'cluster' => 'notice', 'loose' => true,
                'permission' => 'system_admin.notice.analytics'],
            /*
             * নোটিশ সেন্টারের তিনটা দরজা — স্পেক, ধারা ২।
             *
             * ⓘ স্পেকে ষোলটা সাব-মেনুর কথা লেখা, কিন্তু তার বেশিরভাগই
             * একটাই তালিকার **ছাঁকনি** — খসড়া, অনুমোদনের অপেক্ষা,
             * নির্ধারিত, প্রকাশিত, মেয়াদ শেষ, সংরক্ষণাগার।
             *
             * ⛔ প্রতিটাকে নিজের মেনু দিলে সাতটা মেনু একই পর্দায় যেত,
             * কেবল আলাদা ছাঁকনিতে — ⚠️ আর মেনুর লম্বা তালিকাই মানুষকে
             * মেনু পড়া বন্ধ করে দেয়।
             *
             * ⭐ তাই ছাঁকনিগুলো তালিকার ভিতরে, আর মেনুতে তিনটা সত্যিকারের
             * আলাদা কাজ: বোর্ড, হিসাব, আর ছাঁচ।
             */
            ['label' => 'core.notice.categories_title', 'icon' => 'filter', 'route' => 'system_admin.notice.category.index',
                'cluster' => 'notice', 'loose' => true,
                'permission' => 'system_admin.notice.manage'],

            ['label' => 'core.notice.templates_title', 'icon' => 'book', 'route' => 'system_admin.notice.template.index',
                'cluster' => 'notice', 'loose' => true,
                'permission' => 'system_admin.notice.manage'],

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
            ['label' => 'system_admin::settings.title', 'icon' => 'settings', 'route' => 'system_admin.settings',
                'permission' => 'system_admin.settings.manage'],

            /*
             * ── ⛔ ছাপার সারিটা এখানে আর নেই, ২৪ সেপ্টেম্বর ২০২৬ ─────
             *
             * ⓘ মালিকের কথা: *"Control Panel e printing ache aber menute
             * keno dila, ekoi jinis dui jaygay keno?"* — আর কথাটা ঠিক।
             *
             * ⚠️ ২২ সেপ্টেম্বর সারিটা বসানো হয়েছিল একটা সত্যি ভয় থেকে:
             * পর্দা তৈরি হয়েও কেউ খুঁজে পান না। ⓘ কিন্তু তার পরদিনই
             * পর্দাটা কন্ট্রোল প্যানেলের **ট্যাব** হয়েছে
             * ([[ControlPanelTabs]]) — মালিকেরই কথায়: *"print seting
             * alada seting hobe, ekhane alada tab hobe"*।
             *
             * ⛔ তখন মেনুর সারিটা আর দ্বিতীয় দরজা নয়, **দ্বিতীয় ঠিকানা**:
             * একই পর্দা দুই জায়গায় থাকলে "কোনটা আসল" প্রশ্ন ওঠে।
             * ⚠️ ভয়টা মিটে গেছে — কন্ট্রোল প্যানেল এখন নিজেই বারে।
             */

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
            ['label' => 'master_data::menu.number_series', 'icon' => 'sort', 'route' => 'master_data.series.index',
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
        /*
         * ⭐ নোটিশ লেখার নিজের চাবি — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ **পড়ার** কোনো চাবি নেই, আর সেটাই নকশা: নোটিশ সবার জন্য।
         * ⚠️ পড়তে চাবি লাগলে ঠিক তাঁরাই বাদ পড়তেন যাঁদের জন্য নোটিশটা
         * লেখা। ⓘ কে কোনটা দেখবেন সেটা ঠিক করে **ভূমিকা**
         * ([[NoticeBoard::forUser()]]), অনুমতি নয়।
         */
        /*
         * নোটিশের চাবিগুলো ভাগ করা — স্পেক, ধারা ১৬।
         *
         * ⚠️ একটা `manage` চাবিতে সব থাকলে যিনি খসড়া লেখেন তিনি
         * নিজেই প্রকাশ আর প্রত্যাহারও করতে পারতেন — ⛔ আর তখন
         * অনুমোদনের ধাপটা কেবল একটা বোতাম, পাহারা নয়।
         *
         * ⓘ পুরনো `manage` চাবিটা **রয়ে গেল** — লাইভে ভূমিকার সাথে
         * ওটা বাঁধা, আর একই দিনে চাবি ভাগ করা আর পুরনোটা কাড়া করলে
         * পরদিন কেউ নোটিশের পর্দায় ঢুকতেই পারতেন না।
         */
        /*
         * ⛔ `approve` আর `publish` এখনো ঘোষিত নয়, আর সেটাই সৎ।
         *
         * ⓘ আজ পর্দা থেকে লেখা নোটিশ সাথে সাথেই প্রকাশিত হয় — সই আর
         * প্রকাশের নিজের কোনো পর্দা নেই। ⚠️ তবু চাবি দুইটা ঘোষণা করলে
         * ভূমিকার পর্দায় দুইটা টিকের ঘর বসত যা কিছুই আটকায় না —
         * ⛔ আর মিথ্যা টিকের ঘর না থাকা চাবির চেয়েও খারাপ।
         *
         * ⓘ অনুমোদনের প্রবাহটা যেদিন নিজের পর্দা পাবে, সেদিন দুইটা
         * ফিরবে — যাচাইয়ের জায়গাসহ।
         */
        'system_admin.notice.recall',
        'system_admin.notice.analytics',
        'system_admin.notice.manage',
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
        /*
         * ⛔ `system_admin.backup.manage` তোলা হলো — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ ব্যাকআপের দরজা ৩ সেপ্টেম্বরেই Backup মডিউলে সরেছিল, আর সেখানে
         * নিজের ছয়টা অনুমতি আছে (`backup.view` … `backup.failover`)। ⚠️ এই
         * নামটা রয়ে গিয়েছিল, কিন্তু কোথাও যাচাই হত না — অর্থাৎ ভূমিকার
         * পর্দায় একটা টিকের ঘর, যেটা কিছুই খোলে না আর কিছুই আটকায় না।
         *
         * ⛔ ঐ রকম ঘর কেবল অকেজো নয়, **মিথ্যা**: কেউ ওটা টিক দিয়ে ভাবতেন
         * ব্যাকআপের অধিকার দেওয়া হলো (abos-8b-র অডিট, চ৩)।
         */
        // নির্ধারিত রিপোর্টের সূচি বানানো ও চালানো — অন্য সেটিংস থেকে আলাদা,
        // কারণ এটা ঠিক করে কার কাছে কোন ব্যবসায়িক সংখ্যা নিজে থেকে পৌঁছাবে
        'system_admin.reports.schedule',
    ],

    /*
     * ⭐ ভূমিকার ছাঁচ — ১৮ সেপ্টেম্বর ২০২৬, নিরীক্ষার ধাপ ৩.৪।
     *
     * ── ⛔ কেন এই মডিউলে ছাঁচটা সবচেয়ে জরুরি ─────────────────────────
     * এখানকার চাবিগুলো **অন্য সব চাবির উপরে**: যিনি ভূমিকা বানাতে পারেন,
     * তিনি নিজেকে যেকোনো ক্ষমতা দিতে পারেন। ⚠️ তাই "সব দিয়ে দাও" এখানে
     * সবচেয়ে সহজ আর সবচেয়ে বিপজ্জনক পথ।
     *
     * ── ⚠️ তিনটা চাবি ইচ্ছাকৃতভাবে কোনো ছাঁচে নেই ────────────────────
     * `system_admin.ownership.transfer` — মালিকানা হস্তান্তর। ⓘ আসল
     * পাহারা পরিচয়ে (কেবল বর্তমান মালিক), কিন্তু ছাঁচে রাখলে সারিটা
     * অকারণে মানুষের মেনুতে দেখা যেত।
     *
     * `system_admin.role.manage` — ⛔ এটাই সেই চাবি যা দিয়ে বাকি সব চাবি
     * বানানো যায়। ⚠️ ছাঁচে বসালে "শুধু ইউজার সামলানোর" লোকও একদিন
     * নিজেকে সব দিয়ে ফেলতেন, আর অডিট ছাড়া কেউ জানত না।
     *
     * `system_admin.look.manage` — রূপ বদলালে **সবার পর্দা** এক মুহূর্তে
     * বদলায়; ভুল হলে কেউ কাজ করতে পারেন না।
     */
    'role_templates' => [
        /*
         * ⓘ যিনি মানুষ বসান ও সরান — কিন্তু ক্ষমতার ছক বানান না।
         */
        'User Admin' => [
            'system_admin.user.manage',
            'system_admin.audit.view',
        ],

        /*
         * ⭐ যিনি প্রতিষ্ঠানের কাগজপত্র ও সূচি সামলান।
         *
         * ⓘ ইমপোর্ট এখানে আছে কারণ ওটা রোজকার কাজ (নতুন দামের তালিকা,
         * নতুন পণ্য)। ⚠️ কিন্তু `company.manage` নেই — নতুন কোম্পানি
         * খোলা প্রতিষ্ঠানের সিদ্ধান্ত, কেরানির নয়।
         */
        'Settings Keeper' => [
            'system_admin.settings.manage',
            'system_admin.import.manage',
            'system_admin.reports.schedule',
        ],
    ],

    /*
     * হোম পর্দায় নোটিশের দুইটা সংখ্যা — স্পেক, ধারা ৫।
     *
     * ⓘ দশটা KPI কার্ডের কথা লেখা, আর সবগুলোর জায়গা নোটিশের
     * নিজের হিসাবের পর্দায়। ⛔ হোম পর্দায় দশটা বসালে বাকি মডিউলের
     * সংখ্যাগুলো চাপা পড়ত, আর হোম পর্দাটাই একটা রিপোর্ট হয়ে যেত।
     */
    /*
     * নোটিশের দুইটা রিপোর্ট — স্পেক, ধারা ২৯।
     *
     * ⓘ এগারোটা নাম লেখা, কিন্তু তার আটটা একই তালিকার ছাঁকনি।
     * ⛔ প্রতিটার জন্য আলাদা রিপোর্ট লিখলে আটটা প্রায়-একই ফাইল
     * হত, আর একদিন একটায় কলাম যোগ হত অন্যটায় নয়।
     */
    /*
     * নকল-পাহারা — দুইটা মাস্টারের নাম দুইবার বসতে পারে না।
     *
     * ⓘ দুইটা *নিরাপত্তা সতর্কতা* ধরন থাকলে লেখক প্রতিবার ভাবতেন
     * কোনটা বাছবেন, আর দুইটার ডিফল্ট অগ্রাধিকার আলাদা হলে একই
     * ধরনের নোটিশ দুই রকম আচরণ করত — নীরবে।
     */
    'duplicates' => [
        ['model' => \App\Models\NoticeCategory::class, 'name' => ['name_en', 'name_bn']],
        ['model' => \App\Models\NoticeTemplate::class, 'name' => ['name_en', 'name_bn']],
    ],

    'reports' => [
        NoticeReports::class,
    ],

    'widgets' => [
        NoticeWidgets::class,
    ],

    'doc_types' => [
        /*
         * নোটিশের নিজের নম্বর — NTC-2026-2027-0001।
         *
         * ⓘ নম্বরটা খসড়া বানানোর সময়েই বসে, প্রকাশের সময় নয়।
         * ⚠️ প্রকাশে বসালে অনুমোদনের আলোচনায় নোটিশটাকে নাম ধরে
         * ডাকা যেত না, অথচ আলোচনাটা হয় ঠিক তখনই।
         */
        'NTC' => 'core.notice.doc_type',
    ],

    'drill_sources' => [],

    'settings' => [
        /*
         * ⭐ প্রতিটা কাগজের নিজের সুইচ — মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬।
         *
         * *"ইনভয়েজে কি লোগো দেবে পস প্রিন্টারে কি লোগো দেবে, কোনটা সব আলাদা
         * আলাদা ম্যানেজ করা যায় … কি কি কলাম দিবে কোনটার পর কোনটা সব কিছুই
         * নিয়ন্ত্রণ হবে সুইচে।"*
         *
         * ── ⚠️ কেন হাতে লেখা নয়, লুপে ─────────────────────────────────
         * পাঁচটা কাগজ × তিনটা সুইচ = পনেরোটা সারি। ⓘ হাতে লিখলে ষষ্ঠ কাগজ
         * যোগ করার দিনে তিনটার দুইটা মনে থাকত, আর তৃতীয়টা নীরবে ডিফল্টে
         * পড়ে থাকত — ⛔ কোনো ভুল দেখা যেত না, কেবল একটা কাগজ সুইচ মানত না।
         *
         * ⓘ `'group' => 'print_paper'` মানে সারিগুলো সাধারণ সেটিংস পর্দায়
         * ওঠে না — ওগুলোর নিজের পর্দা আছে, কারণ **ক্রম** বদলানোর জন্য
         * সুইচ বা লেখার ঘর কোনোটাই যথেষ্ট নয়।
         */
        ...array_merge(...array_map(fn (string $target) => [
            [
                'key' => "print.{$target}.format",
                'label' => 'system_admin::settings.print_format',
                'type' => 'choice',
                'options' => PrintFormat::all(),
                'option_label' => 'core.print.format.',
                'default' => $target === 'pos' ? 'compact' : 'standard',
                'group' => 'print_paper',
            ],
            /*
             * ⚠️ ডিফল্ট `null`, খালি তালিকা নয় — আর তফাতটা দামি।
             *
             * ⓘ `null` মানে *"বসানো রূপ যা বলে তাই"*, আর `[]` মানে *"কিছুই
             * দেখাবে না"*। ⛔ দুইটাকে এক ধরলে কেউ সব সুইচ বন্ধ করে সেভ করার
             * পরেও কাগজটা আগের মতোই ছাপত, আর কারণটা কেউ খুঁজে পেত না।
             */
            [
                'key' => "print.{$target}.parts",
                'label' => 'system_admin::settings.print_parts',
                'type' => 'json',
                'default' => null,
                'group' => 'print_paper',
            ],
            [
                'key' => "print.{$target}.columns",
                'label' => 'system_admin::settings.print_columns',
                'type' => 'json',
                'default' => null,
                'group' => 'print_paper',
            ],
        ], PrintProfile::TARGETS)),

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
        /*
         * নিজের জরুরি নোটিশ নিজে অনুমোদন নয় — স্পেক, ধারা ১৭।
         *
         * ⚠️ এক-মানুষের অফিসে এটা বন্ধ রাখতেই হবে — ⓘ সেখানে
         * লেখক আর অনুমোদক একই মানুষ, আর নিয়মটা চালু রাখলে
         * কোনো জরুরি নোটিশই বেরোত না।
         */
        [
            'key' => 'notice.creator_cannot_approve',
            'label' => 'system_admin::settings.notice_creator_cannot_approve',
            'type' => 'boolean',
            'default' => true,
            'group' => 'general',
        ],

        /*
         * তাগাদার তিন ধাপ — মালিকের স্পেক, ধারা ১৯।
         *
         * ⓘ ২৪ ঘণ্টা → তাগাদা, ৪৮ → দ্বিতীয়, ৭২ → উপরে জানানো।
         * ⚠️ তিনটাই সুইচে, কারণ একটা কারখানার ২৪ ঘণ্টা আর একটা
         * ডিপোর ২৪ ঘণ্টা এক জিনিস নয় — ডিপো শুক্রবার বন্ধ।
         */
        [
            'key' => 'notice.remind_after_hours',
            'label' => 'system_admin::settings.notice_remind_after',
            'type' => 'integer',
            'default' => 24,
            'group' => 'general',
        ],
        [
            'key' => 'notice.remind_again_hours',
            'label' => 'system_admin::settings.notice_remind_again',
            'type' => 'integer',
            'default' => 48,
            'group' => 'general',
        ],
        [
            'key' => 'notice.escalate_after_hours',
            'label' => 'system_admin::settings.notice_escalate_after',
            'type' => 'integer',
            'default' => 72,
            'group' => 'general',
        ],

        /*
         * বারে একসাথে কয়টা নোটিশ ধরবে — ২৩ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ একটা অফিসে যেকোনো দিন পাঁচ-ছয়টা নোটিশ সক্রিয় থাকে।
         * ⛔ সবগুলো একসাথে দিলে জরুরি কথাটা ভিড়ে হারায়, আর তখন
         * বারটা মানুষ পড়াই বন্ধ করে দেয় — আর ঠিক সেদিনই আগুন
         * লাগার নোটিশটা ওখানে থাকে।
         */
        [
            'key' => 'notice.bar_max',
            'label' => 'system_admin::settings.notice_bar_max',
            'type' => 'integer',
            'default' => 3,
            'group' => 'general',
        ],

        /*
         * ⓘ `system.notice` এই তালিকায় আর নেই — ২৩ সেপ্টেম্বর ২০২৬।
         *
         * ⭐ মালিক বললেন *"etar jonno alada menu koro"*, আর ঘরটা
         * [[TickerNoticeController]]-এ গেছে — নিজের পর্দা, নিজের মেনু।
         *
         * ⚠️ কী-টা বদলায়নি, তাই লাইভে সেভ করা লেখাটা যেমন
         * ছিল তেমনই থাকবে আর নতুন পর্দায় দেখাবে।
         *
         * ⛔ দুই জায়গায় রাখলে একই মান দুই পর্দায় সম্পাদনা করা
         * যেত, আর কেউ একটা বদলে অন্যটা খুঁজত।
         */
    ],
];
