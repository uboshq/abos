<?php

declare(strict_types=1);

use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;

/**
 * Master Data — প্ল্যান সেকশন ৪ ও Phase 3।
 *
 * এই মডিউলটার নিজের কোনো লেনদেন নেই; এর কাজ বাকি সবাইকে "কী কী বেছে
 * নেওয়া যায়" জোগানো। তাই এটা Sales ও Purchase-এর আগে দরকার — ওরা
 * ট্যাক্স, দর, শর্ত ও এলাকা রেফারেন্স করবে, আর সেগুলো না থাকলে ওই
 * মডিউলগুলোতে হার্ডকোড ঢুকে যেত।
 *
 * প্রতিটা তালিকাই সারি, enum নয়। একটা প্রতিষ্ঠান "ডিলার" নামে নতুন
 * গ্রাহকের ধরন যোগ করতে চাইলে সেটা সেটিংস থেকে করতে পারবে — কোড বদলে
 * নয়। enum লিখলে প্রতিটা নতুন ধরনের জন্য একটা রিলিজ লাগত।
 */
return [
    'code' => 'master_data',

    'name' => [
        'en' => 'Master Data',
        'bn' => 'মাস্টার ডাটা',
    ],

    'version' => '1.0.0',

    // হিসাবের খাত লাগে — দর ও ট্যাক্স শেষমেশ কোন খাতে বসবে তা জানতে
    'depends_on' => ['accounts'],

    'menu' => [
        'master' => [
            ['label' => 'master_data::menu.locations', 'route' => 'master_data.location.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.units', 'route' => 'master_data.unit.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.taxes', 'route' => 'master_data.tax.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.payment_terms', 'route' => 'master_data.term.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.price_lists', 'route' => 'master_data.price_list.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.party_types', 'route' => 'master_data.party_type.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.reason_codes', 'route' => 'master_data.reason.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.number_series', 'route' => 'master_data.series.index', 'permission' => 'master_data.manage'],
        ],
    ],

    'permissions' => [
        'master_data.view',
        'master_data.manage',
    ],

    // এই মডিউলের নিজের কোনো ডকুমেন্ট নম্বর নেই — মাস্টার রেকর্ডের কোড
    // ব্যবহারকারী নিজেই দেয়, কারণ সেগুলো ব্যবসার ভাষা ("PCS", "CTN")

    'drill_sources' => [
        'location' => Location::class,
        'unit' => Unit::class,
        'tax' => Tax::class,
        'payment_term' => PaymentTerm::class,
        'price_list' => PriceList::class,
        'party_type' => PartyType::class,
        'reason_code' => ReasonCode::class,
    ],

    'settings' => [
        [
            'key' => 'master_data.region_enabled',
            'label' => 'master_data::settings.region_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'location',
        ],
        [
            'key' => 'master_data.territory_enabled',
            'label' => 'master_data::settings.territory_enabled',
            'type' => 'boolean',
            'default' => true,
            'group' => 'location',
        ],
        [
            'key' => 'master_data.tax_enabled',
            'label' => 'master_data::settings.tax_enabled',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'master_data.multi_unit_enabled',
            'label' => 'master_data::settings.multi_unit_enabled',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
    ],
];
