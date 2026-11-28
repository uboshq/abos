<?php

declare(strict_types=1);

/*
 * ⭐ সেলস ইন্টেলিজেন্স — FMCG কোম্পানির সারা দেশের বিক্রয়-দলের রিপোর্টিং (মালিক, ২৮ নভেম্বর ২০২৬; পরিকল্পনা:
 * "FMCG_Sales_Intelligence_Plan" + "Developer_Blueprint")। "এক কোড, দুই পণ্য": ABOS-এর ভিতরে মডিউল, পরে লাইসেন্স দিয়ে
 * একা বা ABOS-এর সাথে বিক্রি। প্রথমে পাইলট।
 *
 * ⓘ এখন কেবল কাঠামো — প্রতিটা মেনু একটা "শীঘ্রই আসছে" পাতায় খোলে (মালিক: "প্রত্যেকটা মেনু দিয়ে দিবা এবং শীঘ্রই আসছে
 * লেখকে দেবা")। আসল কাজ কাউন্টার শেষ হওয়ার পরে, ধাপে ধাপে; প্রতিটা পর্দা নমুনা → মালিকের ok → কোড।
 *
 * ⓘ পদের গাছ (সব কোম্পানিতে একই, মালিক): SR → Area Manager → RSM → DSM/DDSM → National Sales Manager → GM → CEO।
 * ⓘ IMS = In-Market Sales (ডিস্ট্রিবিউটর → দোকান); পণ্য ধরে বা কেবল টাকায় — দুটোই চলবে।
 */
$soon = fn (string $page, string $label): array => [
    'label' => 'sales_intel::menu.'.$label,
    'icon' => 'list',
    'route' => 'sales_intel.soon',
    'route_params' => ['page' => $page],
    'permission' => 'sales_intel.view',
];

return [
    'code' => 'sales_intel',

    'name' => [
        'en' => 'Sales Intelligence',
        'bn' => 'সেলস ইন্টেলিজেন্স',
    ],

    'version' => '0.1.0',

    // ⓘ ব্যবসার ভাগে, বিক্রয়ের ঠিক পরে (বিক্রয় ৫০, অফার ৫২ বিক্রয়ের ভিতরে, রেস্তোরাঁ ৬০)
    'nav' => ['section' => 'business', 'order' => 55],

    'depends_on' => ['master_data', 'customer', 'sales'],

    'menu' => [
        'dashboard' => [
            $soon('dashboard', 'dashboard'),
            $soon('today', 'today'),
        ],
        'master' => [
            $soon('geography', 'geography'),
            $soon('distributors', 'distributors'),
            $soon('outlets', 'outlets'),
            $soon('routes', 'routes'),
            $soon('team', 'team'),
            $soon('products', 'products'),
        ],
        'transactions' => [
            $soon('ims', 'ims'),
            $soon('delivery', 'delivery'),
            $soon('returns', 'returns'),
            $soon('collection', 'collection'),
            $soon('primary', 'primary'),
            $soon('distributor-summary', 'distributor_summary'),
            $soon('reconcile', 'reconcile'),
            $soon('incentive-schemes', 'incentive_schemes'),
            $soon('incentive-calc', 'incentive_calc'),
            $soon('budget', 'budget'),
            $soon('targets', 'targets'),
            $soon('forecast', 'forecast'),
        ],
        'approval' => [
            $soon('waiting', 'waiting'),
            $soon('submitted', 'submitted'),
        ],
        'reports' => [
            $soon('r-daily-ims', 'r_daily_ims'),
            $soon('r-delivery', 'r_delivery'),
            $soon('r-lifting', 'r_lifting'),
            $soon('r-distributor', 'r_distributor'),
            $soon('r-incentive', 'r_incentive'),
            $soon('r-collection', 'r_collection'),
            $soon('r-am-daily', 'r_am_daily'),
            $soon('r-rsm', 'r_rsm'),
            $soon('r-dsm', 'r_dsm'),
            $soon('r-national', 'r_national'),
            $soon('r-budget', 'r_budget'),
            $soon('r-annual', 'r_annual'),
        ],
        'settings' => [
            $soon('settings', 'settings'),
            $soon('api', 'api'),
            $soon('abos-link', 'abos_link'),
            $soon('import', 'import'),
        ],
    ],

    'permissions' => [
        'sales_intel.view',
    ],

    'role_templates' => [
        'Manager' => ['sales_intel.view'],
    ],

    'moves_money' => [],
];
