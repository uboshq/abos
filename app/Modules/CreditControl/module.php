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
    // ⭐ এক করা মডিউল (মালিক, ২০ নভেম্বর ২০২৬) — কেবল মেনুতে কোথায় বসে; রুট, চাবি, ঠিকানা, ডাটা আগের মতো ([[MenuBuilder::settleGuestsIntoTheirHosts()]])
    'nav' => ['section' => 'business', 'order' => 47, 'under' => 'sales'],

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
            // ⭐ ধাপ ৩.২ — মালিকের রিপোর্টের তালিকার বাকিগুলো
            ['label' => 'credit_control::report.blocked_title', 'icon' => 'alert-triangle', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'blocked'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.ageing_title', 'icon' => 'clock', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'ageing'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.high_risk_title', 'icon' => 'alert-triangle', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'high-risk'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.behaviour_title', 'icon' => 'reports', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'payment-behaviour'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.breaches_title', 'icon' => 'alert-triangle', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'limit-breaches'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.evidence_title', 'icon' => 'book', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'wall-evidence'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.changes_title', 'icon' => 'clock', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'limit-changes'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.requests_title', 'icon' => 'inbox', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'limit-requests'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.temporary_title', 'icon' => 'clock', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'temporary-limits'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.by_branch_title', 'icon' => 'building', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'exposure-by-branch'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.by_salesperson_title', 'icon' => 'customer', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'exposure-by-salesperson'], 'permission' => 'credit_control.view'],
            ['label' => 'credit_control::lists.trend_title', 'icon' => 'reports', 'route' => 'credit_control.report.show',
                'route_params' => ['slug' => 'risk-trend'], 'permission' => 'credit_control.view'],
            // ⭐ IFRS 9 সম্ভাব্য ক্ষতি আর অনাদায়ী বাদ (হিসাবের ফাঁক #১৮)
            ['label' => 'credit_control::loss.title', 'icon' => 'alert-triangle', 'route' => 'credit_control.loss.show',
                'permission' => 'credit_control.credit_loss'],
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

        /*
         * ⭐ সম্ভাব্য ক্ষতির সংরক্ষণ আর অনাদায়ী বাদের খসড়া ভাউচার লেখা — IFRS 9 (হিসাবের ফাঁক #১৮)। ⓘ কেবল খসড়া; পাকা হয়
         * ভাউচারের নিজের পথে (লেখক ≠ পাকাকারী, সই) — তাই লেখার চাবিটাই যথেষ্ট।
         */
        'credit_control.credit_loss',
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

    /*
     * ⭐ ঝুঁকির মাত্রার নিয়ম — প্রতিটা লক্ষণের নম্বর আর তিন মাত্রার সীমা (ধাপ ৩.১, [[CreditRisk]])।
     * ⓘ ০ = ঐ লক্ষণ বন্ধ। মাত্রা কেবল জানায়, কোনো সীমা বা দেয়াল বদলায় না — তাই সুপার অ্যাডমিনের তালা নেই।
     * ⓘ নতুন চাবি, ডিফল্টে — কোনো কোম্পানির পুরনো সেটিং ছোঁয়া হয় না।
     */
    'settings' => [
        ['key' => 'credit_control.risk_points_overdue_age', 'label' => 'credit_control::settings.risk_points_overdue_age', 'type' => 'integer', 'default' => 10, 'group' => 'risk'],
        ['key' => 'credit_control.risk_points_overdue_share', 'label' => 'credit_control::settings.risk_points_overdue_share', 'type' => 'integer', 'default' => 20, 'group' => 'risk'],
        ['key' => 'credit_control.risk_points_late_beyond_terms', 'label' => 'credit_control::settings.risk_points_late_beyond_terms', 'type' => 'integer', 'default' => 15, 'group' => 'risk'],
        ['key' => 'credit_control.risk_points_wall_stop', 'label' => 'credit_control::settings.risk_points_wall_stop', 'type' => 'integer', 'default' => 5, 'group' => 'risk'],
        ['key' => 'credit_control.risk_points_bounced_cheque', 'label' => 'credit_control::settings.risk_points_bounced_cheque', 'type' => 'integer', 'default' => 25, 'group' => 'risk'],
        ['key' => 'credit_control.risk_points_block', 'label' => 'credit_control::settings.risk_points_block', 'type' => 'integer', 'default' => 10, 'group' => 'risk'],
        ['key' => 'credit_control.risk_medium_at', 'label' => 'credit_control::settings.risk_medium_at', 'type' => 'integer', 'default' => 20, 'group' => 'risk'],
        ['key' => 'credit_control.risk_high_at', 'label' => 'credit_control::settings.risk_high_at', 'type' => 'integer', 'default' => 45, 'group' => 'risk'],
        ['key' => 'credit_control.risk_critical_at', 'label' => 'credit_control::settings.risk_critical_at', 'type' => 'integer', 'default' => 75, 'group' => 'risk'],

        /*
         * ⭐ IFRS 9 সম্ভাব্য ক্ষতির হার — বয়সের প্রতিটা ভাগে বকেয়ার কত শতাংশ ([[ReceivableCreditLoss]]; হিসাবের ফাঁক #১৮)।
         * ⛔ কেবল মালিক — টাকার হিসাব বদলায়। ⭐ ডিফল্ট মালিকের অনুমোদিত 1/3/10/50 (২১ অক্টোবর ২০২৬, "plan onujayi koro");
         * প্রতিটা কোম্পানি নিজের হার বসাতে পারে ([[ReceivableCreditLoss::DEFAULTS]] একই অঙ্ক)।
         */
        ['key' => 'credit_control.ecl_rate_current', 'label' => 'credit_control::settings.ecl_rate_current', 'type' => 'number', 'default' => 1, 'group' => 'loss', 'super_admin_only' => true],
        ['key' => 'credit_control.ecl_rate_30', 'label' => 'credit_control::settings.ecl_rate_30', 'type' => 'number', 'default' => 3, 'group' => 'loss', 'super_admin_only' => true],
        ['key' => 'credit_control.ecl_rate_60', 'label' => 'credit_control::settings.ecl_rate_60', 'type' => 'number', 'default' => 10, 'group' => 'loss', 'super_admin_only' => true],
        ['key' => 'credit_control.ecl_rate_90', 'label' => 'credit_control::settings.ecl_rate_90', 'type' => 'number', 'default' => 50, 'group' => 'loss', 'super_admin_only' => true],
    ],

    // ⓘ গ্রাহকের নামে চাপলে তাঁর বাকির পাতা ([[CreditProfile]])
    'drill_sources' => [
        CreditPositionReports::PROFILE => CreditProfile::class,
    ],

    'reports' => [
        CreditPositionReports::class,
        \App\Modules\CreditControl\Reports\CreditControlLists::class,
    ],

    // ⭐ ফোনের হোম-স্ক্রিন উইজেট — বাকির সীমার ঝুঁকি, চেকের দিন (cb, ২০ অক্টোবর ২০২৬; গ্রাহকের নাম লাগে বলে এখানে)
    // ⭐ ফোনের হোম-স্ক্রিন উইজেট — "বড় বকেয়া" (মালিক, ২০ অক্টোবর ২০২৬; [[WidgetSummaries]])
    'phone_widgets' => [
        \App\Modules\CreditControl\Widgets\CreditLimitsSummary::class,
        \App\Modules\CreditControl\Widgets\ChequesSummary::class,
        \App\Modules\CreditControl\Widgets\BigDuesSummary::class,
    ],
    // ⭐ কোপাইলটের দরজা — কেবল পড়ার, নিজের চাবির পেছনে ([[ToolRegistry]]; ১১ নভেম্বর ২০২৬)
    'assistant_tools' => [
        \App\Modules\CreditControl\Assistant\OverdueTool::class,
    ],
];
