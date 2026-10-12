<?php

declare(strict_types=1);

use App\Modules\CreditControl\Dashboard\CreditDashboard;
use App\Modules\CreditControl\Models\CreditProfile;
use App\Modules\CreditControl\Reports\CreditPositionReports;

/**
 * ⭐ বাকি নিয়ন্ত্রণ — মালিকের "Credit Control & Credit Risk Management v2.0", তাঁর নিজের চূড়ান্ত নিয়মে বাঁকানো
 * (docs/cloud-tasks/credit-control.md, ১১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ পাতলা মডিউল ─────────────────────────────────────────────────────
 * এখানে কোনো হিসাব নেই। বাকির প্রতিটা অঙ্ক আসে দেয়ালের নিজের হিসাব থেকে ([[CreditExposure]], বিক্রয় মডিউল) — ড্যাশবোর্ড,
 * গ্রাহকের পাতা আর রিপোর্ট কেবল জিজ্ঞেস করে। ⛔ "বকেয়া + আটকে − জমা" এখানে আবার লিখলে একদিন পর্দা এক কথা আর দেয়াল আরেক
 * কথা বলত।
 *
 * ⓘ দেয়ালের সাক্ষ্য (বিক্রয়ে) আর সীমা বদলের ইতিহাস (গ্রাহকে) যার কাজ তার মডিউলেই লেখা হয় — নির্ভরতার দিক
 * `credit_control → sales → customer`, উল্টো নয়।
 *
 * ── ⛔ যা নেই, ইচ্ছা করে ──────────────────────────────────────────────
 * সীমা পার হওয়ার কোনো ছাড়, ব্যতিক্রমের অনুরোধ বা "ঝুঁকি মেনে নিলাম" বোতাম নেই — মালিক, ১ অক্টোবর ২০২৬: *"limit is 100%
 * a limit — nobody can cross it, not even the owner"*। একমাত্র পথ আগে সীমা বাড়ানো।
 */
return [
    'code' => 'credit_control',

    'name' => [
        'en' => 'Credit Control',
        'bn' => 'বাকি নিয়ন্ত্রণ',
    ],

    'version' => '1.0.0',

    /*
     * ⓘ গ্রাহক (৪৫) আর বিক্রয়ের (৫০) মাঝে — বাকি দুইজনেরই কথা: গ্রাহকের সীমা, বিক্রয়ের দেয়াল।
     */
    'nav' => ['section' => 'business', 'order' => 47],

    'depends_on' => ['accounts', 'customer', 'sales'],

    'dashboard' => CreditDashboard::class,

    'menu' => [
        'dashboard' => [
            ['label' => 'credit_control::menu.dashboard', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'credit_control'], 'permission' => 'credit_control.view'],
        ],

        // ⭐ ড্যাশবোর্ডের প্রতিটা সংখ্যার নিচের তালিকা ([[CreditPositionReports]])
        'reports' => [
            ['label' => 'credit_control::report.positions_title', 'icon' => 'reports', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'positions'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::report.over_limit_title', 'icon' => 'alert-triangle', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'over-limit'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::report.overdue_stopped_title', 'icon' => 'clock', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'overdue-stopped'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::report.overdue_bills_title', 'icon' => 'wallet', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'overdue-bills'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::report.pending_requests_title', 'icon' => 'inbox', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'pending-requests'], 'permission' => 'credit_control.view'],
        ],
    ],

    'permissions' => [
        /*
         * দেখার চাবি — ড্যাশবোর্ড, গ্রাহকের বাকির পাতা আর তালিকা।
         *
         * ⓘ বিক্রয়কর্মী এই চাবি পেলেও কেবল নিজের বাঁধা ডিলার দেখেন (গ্রাহকের ডিলার-দেয়াল, ⛔১৬), আর হেডারে বাছা শাখা
         * সবকিছু ছাঁকে।
         */
        'credit_control.view',

        /*
         * ⭐ সীমা বাড়ানোর অনুরোধ লেখা — মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬ (ধাপ ২.৫)। ⓘ কেবল অনুরোধ; বসে মালিকের সইয়ে
         * ([[CreditLimitRequests]]), আর যিনি চাইলেন তিনি সই দিতে পারেন না।
         */
        'credit_control.request_limit',
    ],

    /*
     * নতুন ইনস্টলে কোন রোলে (§৫) — হিসাবরক্ষক আর ম্যানেজার, যাঁরা বাকি দেখেন ও আদায় তাড়া করেন। ⓘ সুপার অ্যাডমিন
     * এমনিতেই সব চাবি পান।
     */
    'role_templates' => [
        'Accountant' => ['credit_control.view', 'credit_control.request_limit'],
        'Manager' => ['credit_control.view', 'credit_control.request_limit'],
    ],

    'moves_money' => [],

    // ⓘ গ্রাহকের নামে চাপলে তাঁর বাকির পাতা ([[CreditProfile]])
    'drill_sources' => [
        CreditPositionReports::PROFILE => CreditProfile::class,
    ],

    'reports' => [
        CreditPositionReports::class,
    ],
];
