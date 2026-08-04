<?php

declare(strict_types=1);

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

    // দেনা হিসাবের খাতায় বসে, আর ধরন ও শর্ত মাস্টার ডাটা থেকে
    'depends_on' => ['accounts', 'master_data'],

    'menu' => [
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
