<?php

declare(strict_types=1);

use App\Models\Approval;
use App\Modules\Approval\Dashboard\ApprovalDashboard;
use App\Modules\Approval\Dashboard\ApprovalWidgets;
use App\Modules\Approval\Reports\ApprovalReports;

/**
 * Approval Centre — প্ল্যানের Workflow, Sales-এর সাথে।
 *
 * ── কেন মডিউলটা এতদিন ছিল না ────────────────────────────────────────
 * অনুমোদনের ইঞ্জিনটা Phase 1-এই লেখা হয়েছিল, ইচ্ছাকৃতভাবে — যাতে
 * Sales ও Purchase লেখার সময়েই hook বসানো যায়। কিন্তু ইঞ্জিন কাউকে
 * কিছু দেখায় না। ফলে দাঁড়িয়েছিল এমন একটা ব্যবস্থা যা অনুরোধ তৈরি
 * করতে পারত, সিদ্ধান্ত রাখতে পারত, স্তর গুনতে পারত — অথচ কোনো
 * ব্যবহারকারী কোনোদিন একটা অনুরোধও দেখতে পেতেন না, আর কোনো মডিউল
 * কোনোদিন একটাও তৈরি করেনি।
 *
 * এই মডিউলটা সেই ফাঁকটা বন্ধ করে: ছক সাজানোর পর্দা, সিদ্ধান্তের পর্দা,
 * আর প্রথম আসল ব্যবহার (বিক্রয়ে ছাড়ের সীমা)।
 *
 * ── কেন ছকটা কোম্পানির নিজের ─────────────────────────────────────────
 * কোথায় অনুমোদন লাগবে সেটা ব্যবসার সিদ্ধান্ত, কোডের নয়। এক ডিপোতে
 * ৫০০ টাকার ছাড়ে মালিকের সম্মতি লাগে, আরেকটায় ম্যানেজারই যথেষ্ট।
 * হার্ডকোড করলে দ্বিতীয় গ্রাহকের দিনেই কোড বদলাতে হত।
 */
return [
    'code' => 'approval',

    'name' => [
        'en' => 'Approvals',
        'bn' => 'অনুমোদন',
    ],

    'version' => '1.0.0',

    /*
     * সাইডবারে কোথায় — নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     *
     * অনুমোদন নিয়ন্ত্রণেরই একটা রূপ, তাই নিয়ন্ত্রণের পাশে।
     *
     * দলগুলোর তালিকা আর কেন এটা `depends_on`-এর থেকে আলাদা:
     * [[ModuleDefinition::NAV_SECTIONS]].
     */
    'nav' => ['section' => 'people', 'order' => 30],

    // ইঞ্জিনটা কোরের, আর ছকে যে মডিউলের নাম বসবে সেটা রেজিস্ট্রি থেকে
    // আসে — তাই কারও উপর নির্ভরতা নেই
    'depends_on' => [],

    'menu' => [
        'dashboard' => [
            ['label' => 'approval::dashboard.title', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'approval'], 'permission' => 'approval.view'],
        ],

        'approval' => [
            ['label' => 'approval::menu.inbox', 'route' => 'approval.inbox.index', 'permission' => 'approval.decide'],
            ['label' => 'approval::menu.mine', 'route' => 'approval.inbox.mine', 'permission' => 'approval.view'],
        ],
        /*
         * চারটা রিপোর্ট — §২.৮।
         *
         * ⚠️ slug-গুলো `ApprovalReportController::SLUGS`-এর সাথে হুবহু
         * মিলতে হবে। ভুল লিখলে লিংকটা তৈরি হয়, পর্দায় দেখা যায়, আর
         * চাপলে ৪০৪ — `route()` slug দেখেই না।
         * ⓘ সেটা এখন `ALinkThatLooksAliveAndIsNotTest`-এ বাঁধা।
         */
        'reports' => [
            ['label' => 'approval::menu.report_pending', 'route' => 'approval.report.show',
                'route_params' => ['slug' => 'pending'], 'permission' => 'approval.report'],
            ['label' => 'approval::menu.report_approved', 'route' => 'approval.report.show',
                'route_params' => ['slug' => 'approved'], 'permission' => 'approval.report'],
            ['label' => 'approval::menu.report_rejected', 'route' => 'approval.report.show',
                'route_params' => ['slug' => 'rejected'], 'permission' => 'approval.report'],
            ['label' => 'approval::menu.report_by_user', 'route' => 'approval.report.show',
                'route_params' => ['slug' => 'by-user'], 'permission' => 'approval.report'],
        ],

        'settings' => [
            ['label' => 'approval::menu.flows', 'route' => 'approval.flow.index', 'permission' => 'approval.flow.manage'],
        ],
    ],

    'permissions' => [
        /*
         * তিনটা আলাদা চাবি, আর আলাদা হওয়ার কারণ আছে।
         *
         * নিজের অনুরোধ দেখা (view) প্রায় সবার লাগে — যিনি ছাড় চেয়েছেন
         * তিনি জানতে চান কী হলো। সিদ্ধান্ত (decide) কেবল যাঁরা ছকে
         * আছেন। আর ছক বদলানো (flow.manage) মালিকের কাজ: ছক বদলাতে
         * পারা মানে নিজের অনুমোদন নিজে বসিয়ে নেওয়া যায়।
         */
        'approval.view',
        'approval.decide',
        'approval.flow.manage',

        /*
         * রিপোর্ট দেখা — `decide` থেকে আলাদা, আর ইচ্ছাকৃতভাবে।
         *
         * সিদ্ধান্ত দেন যাঁরা ছকে আছেন, হাতেগোনা কয়েকজন। কিন্তু "কী কী
         * ঝুলে আছে" আর "গত মাসে কে কয়টা সই দিলেন" — এই প্রশ্নগুলো
         * নিরীক্ষক, হিসাবরক্ষক ও মালিকের, যাঁরা নিজে কিছু অনুমোদন করেন না।
         *
         * ⚠️ উল্টোটাও সত্যি: যিনি একটা ছকের একটা স্তরে আছেন, তাঁর
         * **গোটা প্রতিষ্ঠানের** অনুমোদনের ইতিহাস দেখার কথা নয়।
         */
        'approval.report',
    ],

    // Report engine এগুলো boot-এ নিবন্ধন করে, তাই রিপোর্ট যোগ করতে
    // কোনো কোর ফাইলে নাম লিখতে হয় না (সেকশন ১৯.৭)।
    'reports' => [
        ApprovalReports::class,
    ],

    /*
     * অনুরোধটাই একটা গন্তব্য — নিয়ম ১।
     *
     * রিপোর্টের সারি থেকে ক্লিক করলে অনুরোধের পর্দা খোলে, যেখানে কাগজ ·
     * স্তর · সিদ্ধান্তের ইতিহাস সবই আছে। ⓘ নিচের কাগজটায় সরাসরি নেওয়া
     * হয় না: পাঠকের প্রশ্নটা তখন অনুমোদন নিয়ে, আর ওখান থেকে কাগজেও
     * যাওয়া যায় — উল্টোটা নয় ([[Approval::drillRoute]])।
     */
    'drill_sources' => [
        'approval' => Approval::class,
    ],

    'dashboard' => ApprovalDashboard::class,

    'widgets' => [
        ApprovalWidgets::class,
    ],

    'settings' => [
        [
            /*
             * নিজের কাগজ নিজে অনুমোদন — কত টাকা পর্যন্ত।
             *
             * ── আজকের নিয়ম, আর কেন সেটা সবসময় ঠিক নয় ───────────────
             * আজ ABOS-এ নিয়মটা কঠোর: **নিজের অনুরোধ নিজে কোনোদিনই
             * অনুমোদন করা যায় না** (`ApprovalEngine::canDecide`)। এটা
             * বড় অঙ্কে ঠিক — যে ছাড় চায় সে-ই যদি দেয়, তবে পুরো
             * ব্যবস্থাটাই সাজানো।
             *
             * কিন্তু ছোট অঙ্কে এটা কাজ থামায়: এক টাকার চায়ের বিল
             * দ্বিতীয় একজনের সইয়ের অপেক্ষায় বসে থাকলে বাস্তবে চা-টা
             * কেউ নিজের পকেট থেকে কিনে ফেলেন, আর খাতা নীরবে দিনের
             * সাথে মেলা বন্ধ করে দেয়।
             *
             * তাই সীমা: এর নিচে নিজে সই করা যায়, এতে বা এর উপরে অন্য
             * কাউকে লাগে।
             *
             * ── শূন্য কেন ডিফল্ট ────────────────────────────────────
             * শূন্য মানে "কখনো নয়" — অর্থাৎ আজকের আচরণ অবিকল। মালিক
             * সংখ্যাটা না বসানো পর্যন্ত কিছুই বদলায় না।
             */
            'key' => 'approval.self_limit',
            'label' => 'approval::settings.self_limit',
            'type' => 'number',
            'default' => 0,
            'group' => 'limits',
        ],
    ],
];
