<?php

declare(strict_types=1);

/**
 * স্থায়ী সম্পদ — মডিউল রেলে নিজের জায়গা, সিস্টেম অ্যাডমিনের ঠিক নিচে।
 *
 * ⭐ মালিক, ১০ অক্টোবর ২০২৬: *"notun modiule gulo … live e syestem er niche bosabe"*।
 *
 * ⓘ কেবল রেল আর মেনু — কোড, টেবিল, চাবি, সই আর রিপোর্ট সবই হিসাব মডিউলের (`accounts.asset.*`; IAS 16-এর খাতা হিসাবেরই),
 * তাই এখানে কোনো ক্লাস নেই আর `depends_on`-এ হিসাব। পর্দার ঠিকানা আর চাবি আগের মতোই, কেবল মেনুর সারি হিসাব থেকে এখানে এল
 * (cloud/fixed-assets মেশানো, ১১ অক্টোবর ২০২৬)।
 */
return [
    'code' => 'fixed_assets',

    'name' => [
        'en' => 'Fixed Assets',
        'bn' => 'স্থায়ী সম্পদ',
    ],

    'version' => '0.1.0',

    // ⓘ সিস্টেম অ্যাডমিন ১০, ব্যাকআপ ২০ — তার মাঝে, অ্যাডমিনের ঠিক নিচে
    'nav' => ['section' => 'system', 'order' => 11],

    'depends_on' => ['accounts'],

    'menu' => [
        'dashboard' => [
            ['label' => 'accounts::menu.asset_dashboard', 'icon' => 'dashboard', 'route' => 'accounts.asset.dashboard', 'permission' => 'accounts.asset.view'],
        ],
        'master' => [
            // ⭐ সম্পদের শ্রেণি — পাঁচ খাত আর ডিফল্ট আয়ু (স্থায়ী সম্পদ ধাপ ১)
            ['label' => 'accounts::menu.asset_categories', 'icon' => 'building', 'route' => 'accounts.asset.category.index', 'permission' => 'accounts.asset.manage'],
        ],
        'transactions' => [
            ['label' => 'accounts::menu.assets', 'icon' => 'building', 'route' => 'accounts.asset.index', 'permission' => 'accounts.asset.view'],
            ['label' => 'accounts::menu.asset_verifications', 'icon' => 'building', 'route' => 'accounts.asset.verify.index', 'permission' => 'accounts.asset.view'],
        ],
        'reports' => [
            // ⭐ স্থায়ী সম্পদের কাগজ — নিবন্ধন, অবচয়, চলাচল, খাতা বনাম কর (স্থায়ী সম্পদ ধাপ ৫)
            ['label' => 'accounts::menu.asset_register', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-register'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_schedule', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-depreciation'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_movement', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-movement'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_nbv', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-nbv'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_disposals', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-disposals'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_fully_depreciated', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-fully-depreciated'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_expiring', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-expiring'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_variance', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-variance'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_book_vs_tax', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-book-vs-tax'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.asset_maintenance', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-maintenance'], 'permission' => 'accounts.report'],
        ],
    ],

    'moves_money' => [],
];
