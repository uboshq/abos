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

    // ⓘ সিস্টেম অ্যাডমিন ১০-এর নিচে নতুন চার মডিউল: মালিকের কেন্দ্র ১১, ডকুমেন্টস ১২, নোটিফিকেশন ১৩, স্থায়ী সম্পদ ১৪ (fe, ১১ অক্টোবর ২০২৬)
    'nav' => ['section' => 'system', 'order' => 14],

    // ⓘ প্রতিটা সারি হিসাবের ঘর ধার করে (`'from' => 'accounts'`) — রুট আর চাবি হিসাবের নামে ([[ModuleDefinition]])
    'depends_on' => ['accounts'],

    'menu' => [
        'dashboard' => [
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_dashboard', 'icon' => 'dashboard', 'route' => 'accounts.asset.dashboard', 'permission' => 'accounts.asset.view'],
        ],
        'master' => [
            // ⭐ সম্পদের শ্রেণি — পাঁচ খাত আর ডিফল্ট আয়ু (স্থায়ী সম্পদ ধাপ ১)
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_categories', 'icon' => 'building', 'route' => 'accounts.asset.category.index', 'permission' => 'accounts.asset.manage'],
        ],
        'transactions' => [
            ['from' => 'accounts', 'label' => 'accounts::menu.assets', 'icon' => 'building', 'route' => 'accounts.asset.index', 'permission' => 'accounts.asset.view'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_verifications', 'icon' => 'building', 'route' => 'accounts.asset.verify.index', 'permission' => 'accounts.asset.view'],
        ],
        'reports' => [
            // ⭐ স্থায়ী সম্পদের কাগজ — নিবন্ধন, অবচয়, চলাচল, খাতা বনাম কর (স্থায়ী সম্পদ ধাপ ৫)
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_register', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-register'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_schedule', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-depreciation'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_movement', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-movement'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_nbv', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-nbv'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_disposals', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-disposals'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_fully_depreciated', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-fully-depreciated'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_expiring', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-expiring'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_variance', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-variance'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_book_vs_tax', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-book-vs-tax'], 'permission' => 'accounts.report'],
            ['from' => 'accounts', 'label' => 'accounts::menu.asset_maintenance', 'icon' => 'building', 'route' => 'accounts.report.show',
                'route_params' => ['slug' => 'asset-maintenance'], 'permission' => 'accounts.report'],
        ],
    ],

    'moves_money' => [],
];
