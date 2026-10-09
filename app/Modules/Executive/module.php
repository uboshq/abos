<?php

declare(strict_types=1);

use App\Modules\Executive\Reports\ExecutiveReports;

/**
 * মালিকের কেন্দ্র — গোটা গ্রুপ এক পর্দায় (দলের নকশা, ৬ অক্টোবর ২০২৬)।
 *
 * ── ⭐ কেন আলাদা মডিউল ───────────────────────────────────────────────
 * প্রতিটা মডিউলের নিজের ড্যাশবোর্ড আছে, কিন্তু সবগুলোই **চলতি কোম্পানির**।
 * মালিকের প্রথম প্রশ্ন "আজ গোটা গ্রুপ কেমন চলল" — তার উত্তর পেতে আজ
 * প্রতিটা কোম্পানিতে ঢুকে চারটা ড্যাশবোর্ড খুলতে হয়।
 *
 * ── ⛔ এখানে কোনো সংখ্যা নতুন করে গোনা হয় না ─────────────────────────
 * প্রতিটা ঘর মডিউলের **নিজের** সংজ্ঞা ডাকে ([[Figures]]), ঐ কোম্পানির
 * প্রসঙ্গে বসে ([[CompanyLens]])। ⓘ তাই ঘরের সংখ্যা আর ঐ কোম্পানির নিজের
 * ড্যাশবোর্ডের সংখ্যা কখনো আলাদা হয় না — আর হলে টেস্ট লাল হয়।
 *
 * ⓘ কোনো AI নেই, কোনো অনুমান নেই — প্রতিটা সংখ্যা খাতা, মজুদ বা কাগজ থেকে,
 * আর প্রতিটা সংখ্যায় চাপ দিলে তার উৎসে নামা যায়।
 */
return [
    'code' => 'executive',

    'name' => [
        'en' => "Owner's centre",
        'bn' => 'মালিকের কেন্দ্র',
    ],

    'version' => '1.0.0',

    /*
     * ⭐ সবার উপরে — মালিকের সিদ্ধান্ত: আলাদা ভাঁজ, হোম পাতা যেমন আছে তেমনই।
     */
    'nav' => ['section' => 'top', 'order' => 5],

    /*
     * ⓘ যাদের সংখ্যা পড়া হয় — প্রতিটা মডিউলের নিজের সংজ্ঞা, নিজের ক্লাস।
     * ⚠️ চক্র হয় না: এদের কেউ মালিকের কেন্দ্রের উপর দাঁড়ায় না।
     */
    'depends_on' => ['accounts', 'sales', 'customer', 'inventory', 'approval', 'governance'],

    'menu' => [
        'dashboard' => [
            ['label' => 'executive::menu.today', 'icon' => 'dashboard', 'route' => 'executive.today', 'permission' => 'executive.view'],
        ],

        'reports' => [
            ['label' => 'executive::menu.compare', 'icon' => 'scale', 'route' => 'executive.compare', 'permission' => 'executive.view'],
            ['label' => 'executive::menu.analysis', 'icon' => 'reports', 'route' => 'executive.analysis', 'permission' => 'executive.view'],
            ['label' => 'executive::menu.alerts', 'icon' => 'alert-triangle', 'route' => 'executive.alerts', 'permission' => 'executive.view'],
            ['label' => 'executive::menu.history', 'icon' => 'clock', 'route' => 'executive.history', 'permission' => 'executive.view'],
            ['label' => 'executive::analysis.profit_by_customer', 'icon' => 'customer', 'route' => 'executive.report.show',
                'route_params' => ['slug' => 'profit-by-customer'], 'permission' => 'executive.view'],
        ],

    ],

    'permissions' => [
        /*
         * ⭐ একটাই চাবি — মালিকের উত্তর, প্রশ্ন ৬।
         *
         * ⓘ মালিক প্রতিটা কোম্পানিতে super_admin, তাই নিজের সব কোম্পানি দেখেন।
         * বাকিরা চাবি পেলে কেবল যে কোম্পানিগুলোর সদস্য (`company_user`), আর
         * প্রতিটার ভিতরে কেবল যে শাখাগুলো তাঁদের সীমায়। ⛔ অন্যের কোম্পানি
         * পর্দায় আসে না — নামটাও না।
         */
        'executive.view',
    ],

    'reports' => [
        ExecutiveReports::class,
    ],
];
