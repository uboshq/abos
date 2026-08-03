<?php

declare(strict_types=1);

/**
 * কোরের বাংলা লেখা — নিয়ম ৯।
 *
 * Blade বা কন্ট্রোলারে কোনো লেখা সরাসরি বসবে না। এই একটা নিয়ম ভাঙলে পরে
 * হাজার হাজার স্ট্রিং খুঁজে বের করতে হবে, আর কিছু সবসময় বাদ পড়ে থাকবে।
 */
return [
    'status' => [
        'draft' => 'খসড়া',
        'confirmed' => 'নিশ্চিত',
        'cancelled' => 'বাতিল',
        'closed' => 'সম্পন্ন',
    ],

    'drill' => [
        'unavailable' => ':type — উৎস ডকুমেন্টটি আর পাওয়া যাচ্ছে না',
        'view_source' => 'উৎস দেখুন',
    ],

    'source' => [
        'sales_invoice' => 'বিক্রয় চালান',
        'purchase_invoice' => 'ক্রয় চালান',
        'receipt_voucher' => 'আদায় ভাউচার',
        'payment_voucher' => 'পরিশোধ ভাউচার',
        'journal_voucher' => 'জাবেদা ভাউচার',
        'contra_voucher' => 'কন্ট্রা ভাউচার',
        'money_transfer' => 'টাকা হস্তান্তর',
        'cash_count' => 'নগদ গণনা',
        'customer' => 'গ্রাহক',
    ],

    'posting' => [
        'reversal_of' => ':document — বিপরীত এন্ট্রি',
        'unbalanced' => 'ডেবিট ও ক্রেডিট মিলছে না',
    ],

    'menu' => [
        'dashboard' => 'ড্যাশবোর্ড',
        'master' => 'মাস্টার',
        'transactions' => 'লেনদেন',
        'approval' => 'অনুমোদন',
        'reports' => 'প্রতিবেদন',
        'settings' => 'সেটিংস',
    ],

    'action' => [
        'create' => 'নতুন',
        'save' => 'সংরক্ষণ',
        'approve' => 'অনুমোদন',
        'print' => 'প্রিন্ট',
        'export' => 'রপ্তানি',
        'share' => 'শেয়ার',
        'duplicate' => 'অনুলিপি',
        'history' => 'ইতিহাস',
        'help' => 'সাহায্য',
        'cancel' => 'বাতিল',
        'search' => 'খুঁজুন',
    ],

    'company' => [
        'switch' => 'কোম্পানি বদলান',
        'branch' => 'শাখা',
        'financial_year' => 'অর্থবছর',
    ],
];
