<?php

declare(strict_types=1);

use App\Modules\Supplier\Dashboard\SupplierDashboard;
use App\Modules\Supplier\Dashboard\SupplierWidgets;
use App\Modules\Supplier\Imports\SupplierImporter;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PartyReports;

/**
 * Supplier — প্ল্যান Phase 5।
 *
 * গ্রাহকের আয়না, কিন্তু হুবহু নয়। তিনটা আসল পার্থক্য:
 *
 *   গ্রাহকের কাছে আমাদের পাওনা (প্রাপ্য), সরবরাহকারীর কাছে আমাদের দেনা
 *   (প্রদেয়) — চিহ্ন উল্টো, আর খাতও আলাদা।
 *
 *   গ্রাহকের ক্রেডিট সীমা আমরা ঠিক করি; সরবরাহকারীর সীমা তারা ঠিক করে,
 *   তাই ওটা নিয়ম নয়, তথ্য — ছাড়িয়ে গেলে বিল আটকানো হয় না।
 *
 *   সরবরাহকারীর BIN/TIN লাগে, কারণ ক্রয়ে উৎসে ভ্যাট কাটতে হয়।
 */
return [
    'code' => 'supplier',

    'name' => [
        'en' => 'Supplier',
        'bn' => 'সরবরাহকারী',
    ],

    'version' => '1.0.0',

    /*
     * সাইডবারে কোথায় — নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     *
     * গ্রাহকের উল্টো পিঠ, তাই তার ঠিক পরে।
     *
     * দলগুলোর তালিকা আর কেন এটা `depends_on`-এর থেকে আলাদা:
     * [[ModuleDefinition::NAV_SECTIONS]].
     */
    /*
     * ── ক্রয়ের ভেতরে, ৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
     * গ্রাহকের জোড়া সিদ্ধান্ত: *"same vabe Supplier purches e dukaw"*।
     * রেলে নিজের টাইল নেই; সারিগুলো ক্রয়ের টাইলে, তার সারির পরে।
     *
     * `section`/`order` রেখে দেওয়ার কারণ গ্রাহকেরটার মতোই — আশ্রয়দাতা
     * না থাকলে ফিরে দাঁড়ানোর জায়গা।
     */
    'nav' => ['section' => 'business', 'order' => 20, 'under' => 'purchase'],

    // দেনা হিসাবের খাতায় বসে, আর ধরন ও শর্ত মাস্টার ডাটা থেকে
    'depends_on' => ['accounts', 'master_data'],

    'menu' => [
        'dashboard' => [
            ['label' => 'supplier::dashboard.title', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'supplier'], 'permission' => 'supplier.view'],
        ],

        'master' => [
            ['label' => 'supplier::menu.suppliers', 'route' => 'supplier.index', 'permission' => 'supplier.view'],
        ],
        'reports' => [
            ['label' => 'supplier::menu.payable_list', 'route' => 'supplier.report.show',
                'route_params' => ['slug' => 'payable-list'], 'permission' => 'supplier.report'],
            ['label' => 'supplier::menu.ageing', 'route' => 'supplier.report.show',
                'route_params' => ['slug' => 'ageing'], 'permission' => 'supplier.report'],
        ],
    ],

    'permissions' => [
        'supplier.view',
        'supplier.create',
        'supplier.update',
        'supplier.delete',
        'supplier.report',
        'supplier.manage',
    ],

    'doc_types' => [
        'SUP' => 'supplier::doc.supplier_code',
    ],

    'drill_sources' => [
        'supplier' => Supplier::class,
    ],

    // খতিয়ানের সারিতে সরবরাহকারীর নামও বসতে পারে
    'parties' => [
        'supplier' => 'supplier::menu.party',
    ],

    'custom_fields' => ['supplier'],

    // পুরনো খাতা থেকে আনা — ইমপোর্টের পর্দা এই ঘোষণা থেকেই সারিটা
    // দেখায়, তাই কোর কোডে কোনো মডিউলের নাম লিখতে হয় না।
    'imports' => [
        'supplier' => SupplierImporter::class,
    ],

    // Report engine এগুলো boot-এ নিবন্ধন করে, তাই রিপোর্ট যোগ করতে
    // কোনো কোর ফাইলে নাম লিখতে হয় না (সেকশন ১৯.৭)।
    'reports' => [
        PartyReports::class,
    ],

    /*
     * হোম পর্দার দুইটা সংখ্যা — কোম্পানিকে কত দিতে হবে, আর এই মাসে
     * মার্জিন কত। দুইটাই পরিবেশক ডিপোর রোজকার প্রশ্ন।
     */
    'dashboard' => SupplierDashboard::class,

    'widgets' => [
        SupplierWidgets::class,
    ],

    'settings' => [
        [
            'key' => 'supplier.require_bn_name',
            'label' => 'supplier::settings.require_bn_name',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            'key' => 'supplier.require_bin',
            'label' => 'supplier::settings.require_bin',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
    ],
];
