<?php

declare(strict_types=1);

/*
 * ডকুমেন্টের স্থির তালিকার নাম — [[DocumentCatalog]] (৮ অক্টোবর ২০২৬, প্রথম ধাপ)।
 *
 * ⓘ ফোল্ডারগুলো মালিকের ন'টা (§৪), তাঁর ক্রমে; গোপনীয়তার পাঁচ ধাপ §১৪ থেকে।
 */
return [
    'folder' => [
        'company' => 'কোম্পানির কাগজ',
        'contracts' => 'চুক্তি',
        'hr' => 'এইচআর',
        'finance' => 'অর্থ',
        'sales' => 'বিক্রয়',
        'purchase' => 'ক্রয়',
        'inventory' => 'মজুদ',
        'legal' => 'আইনি',
        'compliance' => 'কমপ্লায়েন্স',
    ],

    'type' => [
        'contract' => 'চুক্তিপত্র',
        'agreement' => 'সমঝোতা',
        'license' => 'লাইসেন্স',
        'certificate' => 'সনদ',
        'invoice' => 'বিল',
        'letter' => 'চিঠি',
        'policy' => 'নীতিমালা',
        'report' => 'প্রতিবেদন',
        'identity' => 'পরিচয়পত্র',
        'form' => 'ফর্ম',
        'other' => 'অন্যান্য',
    ],

    'level' => [
        'public' => 'সবার জন্য',
        'internal' => 'অভ্যন্তরীণ',
        'confidential' => 'গোপন',
        'highly_confidential' => 'অতি গোপন',
        'restricted' => 'সংরক্ষিত',
    ],

    'status' => [
        'draft' => 'খসড়া',
        'approved' => 'অনুমোদিত',
        'archived' => 'আর্কাইভে',
    ],

    'expiry' => [
        'expired' => 'মেয়াদ শেষ',
        '7' => '৭ দিনের মধ্যে শেষ',
        '30' => '৩০ দিনের মধ্যে শেষ',
        '90' => '৯০ দিনের মধ্যে শেষ',
    ],
];
