<?php

declare(strict_types=1);

return [
    'title' => 'আচরণ',
    'none' => 'এই পার্টির কোনো আচরণ লেখা নেই।',
    'add' => 'আচরণ লিখুন',
    'type_field' => 'কী হয়েছে',
    'note' => 'নোট',
    'note_hint' => 'ধরন “অন্যান্য” হলে নোট দিতে হবে।',
    'record' => 'লিখুন',
    'retire' => 'নামান',
    'retired' => 'নামানো হয়েছে',
    'active_heading' => 'চলমান',
    'retired_heading' => 'আর পতাকা নেই',
    // কে লিখল, কবে — পুরনো পতাকা তাজা পতাকার চেয়ে হালকা পড়া উচিত
    'by_line' => ':who · :date',
    'retired_by_line' => 'নামিয়েছেন :who · :date',

    // OTHER-এ নোট বাধ্যতামূলক, নাহলে মুক্ত লেখার পিছনের দরজা
    'note_required' => '“অন্যান্য” বাছলে কী হয়েছে তা নোটে লিখতে হবে।',
    'invalid_type' => 'এটা চেনা কোনো আচরণের ধরন নয়।',
    'recorded' => 'আচরণ লেখা হয়েছে।',
    'was_retired' => 'পতাকা নামানো হলো — ইতিহাস থেকে গেল।',

    'severity' => [
        'good' => 'ভালো',
        'notice' => 'জেনে রাখার মতো',
        'risk' => 'ঝুঁকি',
    ],

    'group' => [
        'money' => 'টাকা',
        'delivery' => 'ডেলিভারি',
        'relationship' => 'সম্পর্ক',
    ],

    'type' => [
        'LATE_PAYMENT' => 'দেরিতে টাকা দেয়',
        'CHEQUE_DISHONOURED' => 'চেক ফেরত গেছে',
        'DISPUTES_INVOICE' => 'বিল নিয়ে আপত্তি করে',
        'PAYS_ON_TIME' => 'সময়মতো টাকা দেয়',
        'PAYS_IN_ADVANCE' => 'আগাম টাকা দেয়',
        'SLOW_UNLOADING' => 'মাল নামাতে দেরি করে',
        'ADVANCE_NOTICE_REQUIRED' => 'আগে জানাতে হয়',
        'FIXED_DELIVERY_WINDOW' => 'নির্দিষ্ট সময়ে ডেলিভারি',
        'NO_LARGE_VEHICLE_ACCESS' => 'বড় গাড়ি ঢোকে না',
        'REFUSES_AT_GATE' => 'গেটে ফিরিয়ে দেয়',
        'QUICK_UNLOADING' => 'দ্রুত মাল নামায়',
        'KEY_ACCOUNT' => 'গুরুত্বপূর্ণ গ্রাহক',
        'DORMANT' => 'নিষ্ক্রিয়',
        'OTHER' => 'অন্যান্য',
    ],
];
