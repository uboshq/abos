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
        'submitted' => 'জমা হয়েছে',
        'under_review' => 'পর্যালোচনায়',
        'changes_requested' => 'বদল চাওয়া হয়েছে',
        'approved' => 'অনুমোদিত',
        'rejected' => 'বাতিল',
        'published' => 'প্রকাশিত',
        'expired' => 'মেয়াদোত্তীর্ণ',
        'archived' => 'আর্কাইভে',
        'deleted' => 'মোছা',
    ],

    'expiry' => [
        'expired' => 'মেয়াদ শেষ',
        '7' => '৭ দিনের মধ্যে শেষ',
        '30' => '৩০ দিনের মধ্যে শেষ',
        '90' => '৯০ দিনের মধ্যে শেষ',
    ],

    'ability' => [
        'view' => 'দেখা',
        'download' => 'নামানো',
        'print' => 'ছাপা',
        'share' => 'শেয়ার',
        'edit' => 'বদল',
    ],

    'grantee' => [
        'user' => 'মানুষ',
        'role' => 'ভূমিকা',
    ],

    'field_kind' => [
        'text' => 'লেখা',
        'number' => 'সংখ্যা',
        'date' => 'তারিখ',
    ],

    'decision' => [
        'approved' => 'অনুমোদন',
        'rejected' => 'না / ফেরত',
        'forwarded' => 'অন্যের কাছে পাঠানো',
    ],

    'link_type' => [
        'customer' => 'গ্রাহক',
        'supplier' => 'সরবরাহকারী',
        'purchase_order' => 'ক্রয়াদেশ',
        'purchase_receipt' => 'মাল গ্রহণ',
        'purchase_bill' => 'ক্রয়ের বিল',
        'employee' => 'কর্মী',
    ],
];
