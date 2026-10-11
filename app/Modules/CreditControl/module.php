<?php

declare(strict_types=1);

use App\Modules\CreditControl\Dashboard\CreditDashboard;

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
    ],

    'permissions' => [
        /*
         * দেখার চাবি — ড্যাশবোর্ড, গ্রাহকের বাকির পাতা আর তালিকা।
         *
         * ⓘ বিক্রয়কর্মী এই চাবি পেলেও কেবল নিজের বাঁধা ডিলার দেখেন (গ্রাহকের ডিলার-দেয়াল, ⛔১৬), আর হেডারে বাছা শাখা
         * সবকিছু ছাঁকে।
         */
        'credit_control.view',
    ],

    /*
     * নতুন ইনস্টলে কোন রোলে (§৫) — হিসাবরক্ষক আর ম্যানেজার, যাঁরা বাকি দেখেন ও আদায় তাড়া করেন। ⓘ সুপার অ্যাডমিন
     * এমনিতেই সব চাবি পান।
     */
    'role_templates' => [
        'Accountant' => ['credit_control.view'],
        'Manager' => ['credit_control.view'],
    ],

    'moves_money' => [],
];
