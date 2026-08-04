<?php

declare(strict_types=1);

/**
 * Customer মডিউলের পরিচয়পত্র — প্ল্যান সেকশন ১৯.২।
 *
 * Phase 2-এ এই মডিউল দিয়েই ভিত্তি প্রমাণ করা হবে (সেকশন ২.৩)। তাই এটাই
 * বাকি দশটা মডিউলের নমুনা — এখানে যা লেখা আছে, বাকিগুলোতেও ঠিক তাই থাকবে।
 */

use App\Modules\Customer\Imports\CustomerImporter;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Reports\PartyReports;

return [
    'code' => 'customer',

    'name' => [
        'en' => 'Customer',
        'bn' => 'গ্রাহক',
    ],

    'version' => '1.0.0',

    // গ্রাহকের পাওনা হিসাবের খাতায় বসে, তাই accounts আগে তৈরি হতে হবে।
    'depends_on' => ['accounts'],

    /*
     * এখানে শুধু সেই স্ক্রিনগুলো, যেগুলো সত্যিই আছে।
     *
     * প্রথমে ড্যাশবোর্ড, বিবরণী, বকেয়া তালিকা, বয়সভিত্তিক ও সেটিংস — পাঁচটাই
     * লেখা ছিল, অথচ একটারও রুট তৈরি হয়নি। MenuBuilder অনুপস্থিত রুটে url
     * null দেয়, তাই মেনুতে পাঁচটা মৃত সারি বসত: ব্যবহারকারী ক্লিক করত,
     * কিছুই হত না। ওটাই সবচেয়ে খারাপ ধরনের স্টাব — কাজটা আছে বলে দেখায়।
     *
     * স্ক্রিনটা যেদিন তৈরি হবে, সেদিনই সারিটা এখানে ফিরবে। ModuleMenuTest
     * পাহারা দেয় — ঘোষিত রুট না থাকলে টেস্ট ভাঙে।
     */
    'menu' => [
        'master' => [
            ['label' => 'customer::menu.customers', 'route' => 'customer.index', 'permission' => 'customer.view'],
        ],
        'reports' => [
            ['label' => 'customer::menu.due_list', 'route' => 'customer.report.show',
                'route_params' => ['slug' => 'due-list'], 'permission' => 'customer.report'],
            ['label' => 'customer::menu.ageing', 'route' => 'customer.report.show',
                'route_params' => ['slug' => 'ageing'], 'permission' => 'customer.report'],
        ],
    ],

    'permissions' => [
        'customer.view',
        'customer.create',
        'customer.update',
        'customer.delete',
        'customer.report',
        'customer.manage',
        'customer.credit_limit.override',
    ],

    // Number Series engine এগুলো থেকে prefix/counter সেটআপ তৈরি করবে।
    'doc_types' => [
        'CUS' => 'customer::doc.customer_code',
    ],

    // Drill-down engine এই মানচিত্র দিয়েই "কোন হিসাব কোথা থেকে এল" দেখায় — নিয়ম ১।
    'drill_sources' => [
        'customer' => Customer::class,
    ],

    'imports' => [
        'customer' => CustomerImporter::class,
    ],

    // Report engine এগুলো boot-এ নিবন্ধন করে, তাই রিপোর্ট যোগ করতে
    // কোনো কোর ফাইলে নাম লিখতে হয় না (সেকশন ১৯.৭)।
    'reports' => [
        PartyReports::class,
    ],

    // Control Panel-এ যে সুইচগুলো দেখাবে — নিয়ম ৭।
    'settings' => [
        [
            'key' => 'customer.require_bn_name',
            'label' => 'customer::settings.require_bn_name',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            'key' => 'customer.credit_limit_enabled',
            'label' => 'customer::settings.credit_limit_enabled',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'customer.block_over_limit',
            'label' => 'customer::settings.block_over_limit',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'customer.show_photo_on_print',
            'label' => 'customer::settings.show_photo_on_print',
            'type' => 'boolean',
            'default' => false,
            'group' => 'print',
        ],
    ],
];
