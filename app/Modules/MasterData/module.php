<?php

declare(strict_types=1);

use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Models\EmploymentType;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\MasterData\Models\VehicleType;
use App\Modules\MasterData\Services\MasterListService;

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
            ['label' => 'master_data::menu.payment_methods', 'route' => 'master_data.payment_method.index', 'permission' => 'master_data.view'],

            /*
             * ব্র্যান্ড ও শ্রেণি — আগে পণ্যের ফর্মে মুক্ত লেখা ছিল।
             *
             * সুইচের পেছনে নয়: `inventory.brand_enabled` অন্য মডিউলের
             * সেটিং, আর এক মডিউল অন্যের সুইচ দিয়ে নিজের মেনু আটকালে
             * সুইচটা বন্ধ থাকলে সারিটা চিরকাল অদৃশ্য থাকত। ব্র্যান্ড না
             * ব্যবহার করলে তালিকাটা খালি, আর পর্দাই সেটা বলে দেয়।
             */
            ['label' => 'master_data::menu.brands', 'route' => 'master_data.brand.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.product_categories', 'route' => 'master_data.product_category.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.payment_terms', 'route' => 'master_data.term.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.price_lists', 'route' => 'master_data.price_list.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.party_types', 'route' => 'master_data.party_type.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.reason_codes', 'route' => 'master_data.reason.index', 'permission' => 'master_data.view'],

            // প্রতিষ্ঠানের গড়ন — কর্মীর তালিকা এই তিনটার উপর দাঁড়ায়
            ['label' => 'master_data::menu.departments', 'route' => 'master_data.department.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.designations', 'route' => 'master_data.designation.index', 'permission' => 'master_data.view'],
            ['label' => 'master_data::menu.employment_types', 'route' => 'master_data.employment_type.index', 'permission' => 'master_data.view'],

            /*
             * তিনটা সারি সুইচের পেছনে।
             *
             * এক মুদ্রার প্রতিষ্ঠানে "মুদ্রা" আর "বিনিময় হার" মেনুতে
             * থাকলে প্রতিবার সেগুলো এড়িয়ে যেতে হত, আর যার নিজের গাড়ি
             * নেই তার বহরের তালিকা চিরকাল খালি থাকত — খালি তালিকা
             * দেখলে মানুষ ভাবে কিছু হারিয়ে গেছে।
             */
            ['label' => 'master_data::menu.currencies', 'route' => 'master_data.currency.index', 'permission' => 'master_data.view', 'setting' => 'master_data.multi_currency_enabled'],
            ['label' => 'master_data::menu.vehicle_types', 'route' => 'master_data.vehicle_type.index', 'permission' => 'master_data.view', 'setting' => 'master_data.vehicle_enabled'],
            ['label' => 'master_data::menu.vehicles', 'route' => 'master_data.vehicle.index', 'permission' => 'master_data.view', 'setting' => 'master_data.vehicle_enabled'],

            ['label' => 'master_data::menu.number_series', 'route' => 'master_data.series.index', 'permission' => 'master_data.manage'],
        ],
    ],

    'permissions' => [
        'master_data.view',
        'master_data.manage',

        /*
         * মোছার চাবি আলাদা, আর সেটা মালিকের (মালিকের সিদ্ধান্ত,
         * ২০২৬-০৮-০৯)।
         *
         * manage থাকলেই সবকিছু করা যায় — নতুন বানানো, সম্পাদনা,
         * নিষ্ক্রিয় করা। ওগুলো ফেরানো যায়। মোছা যায় না: কোথাও ব্যবহার
         * না হওয়া সারি সত্যিই টেবিল থেকে চলে যায়, আর ভুল হলে ফেরার
         * কোনো পথ নেই।
         *
         * তাই যে মানুষটা রোজ মাস্টার ডাটা সামলান তাঁর হাতে এটা থাকে না।
         */
        'master_data.delete',
    ],

    // এই মডিউলের নিজের কোনো ডকুমেন্ট নম্বর নেই — মাস্টার রেকর্ডের কোড
    // ব্যবহারকারী নিজেই দেয়, কারণ সেগুলো ব্যবসার ভাষা ("PCS", "CTN")

    /*
     * নতুন কোম্পানি হলে ডিফল্ট তালিকাগুলো এখান থেকে বসে — একক, কর,
     * পরিশোধের শর্ত, কারণ কোড।
     *
     * accounts-এর পরে চলে, আর সেটা এখানে লেখা নেই: depends_on-এ
     * accounts আছে, আর ModuleRegistry নির্ভরতার ক্রমেই ফেরত দেয়।
     * ক্রমটা জরুরি — করের সারি নিজের হিসাব-খাত খোঁজে, আর ছক না বসলে
     * সেই খাত থাকত না।
     */
    'provisions' => [
        MasterListService::class,
    ],

    'drill_sources' => [
        'location' => Location::class,
        'unit' => Unit::class,
        'tax' => Tax::class,
        'payment_term' => PaymentTerm::class,
        'price_list' => PriceList::class,
        'party_type' => PartyType::class,
        'reason_code' => ReasonCode::class,
        'currency' => Currency::class,
        'department' => Department::class,
        'designation' => Designation::class,
        'employment_type' => EmploymentType::class,
        'vehicle_type' => VehicleType::class,
        'vehicle' => Vehicle::class,
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

        /*
         * দুইটাই ডিফল্টে বন্ধ।
         *
         * অন্য সুইচগুলো ডিফল্টে খোলা, কারণ ওগুলো ছাড়া কাজই চলে না।
         * এই দুইটা উল্টো: বেশিরভাগ প্রতিষ্ঠান এক মুদ্রায় চলে আর
         * ভাড়ার গাড়িতে মাল পাঠায়। খোলা রাখলে সবাইকে প্রথম দিনেই
         * তিনটা অপ্রয়োজনীয় মেনু সরাতে হত।
         */
        [
            'key' => 'master_data.multi_currency_enabled',
            'label' => 'master_data::settings.multi_currency_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            'key' => 'master_data.vehicle_enabled',
            'label' => 'master_data::settings.vehicle_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
    ],
];
