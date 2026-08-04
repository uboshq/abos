<?php

declare(strict_types=1);

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
            ['label' => 'system_admin::menu.companies', 'route' => 'admin.companies', 'permission' => 'system_admin.company.manage'],
            ['label' => 'system_admin::menu.branches', 'route' => 'admin.branches', 'permission' => 'system_admin.company.manage'],
            ['label' => 'system_admin::menu.users', 'route' => 'admin.users', 'permission' => 'system_admin.user.manage'],
            ['label' => 'system_admin::menu.roles', 'route' => 'admin.roles', 'permission' => 'system_admin.role.manage'],
            ['label' => 'system_admin::menu.financial_years', 'route' => 'admin.financial-years', 'permission' => 'system_admin.company.manage'],
            ['label' => 'system_admin::menu.number_series', 'route' => 'admin.number-series', 'permission' => 'system_admin.company.manage'],
        ],
        'reports' => [
            ['label' => 'system_admin::menu.activity_log', 'route' => 'admin.activity', 'permission' => 'system_admin.audit.view'],
            ['label' => 'system_admin::menu.login_history', 'route' => 'admin.logins', 'permission' => 'system_admin.audit.view'],
        ],
        'settings' => [
            ['label' => 'system_admin::menu.control_panel', 'route' => 'admin.control-panel', 'permission' => 'system_admin.settings.manage'],
            ['label' => 'system_admin::menu.backup', 'route' => 'admin.backup', 'permission' => 'system_admin.backup.manage'],
        ],
    ],

    'permissions' => [
        'system_admin.company.manage',
        'system_admin.user.manage',
        'system_admin.role.manage',
        'system_admin.settings.manage',
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
        [
            'key' => 'system.auto_logout_minutes',
            'label' => 'system_admin::settings.auto_logout_minutes',
            'type' => 'integer',
            // সেকশন ৮-এর চেকলিস্ট
            'default' => 15,
            'group' => 'general',
        ],
    ],
];
