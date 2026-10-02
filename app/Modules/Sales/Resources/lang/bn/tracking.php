<?php

declare(strict_types=1);

// ডেলিভারি ট্র্যাকিং — ২ অক্টোবর ২০২৬ ([[SaleTracking]])
return [
    'title' => 'ডেলিভারি ট্র্যাকিং',
    'search' => 'বিক্রয় নম্বর, ডিও বা দোকান',
    'find' => 'খুঁজুন',
    'all' => 'সব',
    'empty' => 'কোনো বিক্রি নেই।',
    'step' => [
        'ordered' => 'অর্ডার এসেছে',
        'draft' => 'খসড়া',
        'approval' => 'অনুমোদনের অপেক্ষায়',
        'warehouse' => 'গুদামে',
        'gate_out' => 'গেট পেরিয়েছে',
        'partial' => 'কিছু পৌঁছেছে',
        'delivered' => 'পৌঁছেছে',
        'cancelled' => 'বাতিল',
        'billed' => 'বিল হয়েছে',
    ],
    'ordered' => 'অর্ডার :no দেওয়া হলো',
    'do_written' => 'ডিও :no লেখা হলো',
    'sent_for_signature' => 'সইয়ের জন্য পাঠানো হলো',
    'decision' => [
        'approved' => 'অনুমোদন দিলেন',
        'rejected' => 'ফেরত দিলেন',
        'forwarded' => 'অন্যকে পাঠালেন',
        'other' => 'সিদ্ধান্ত দিলেন',
    ],
    'received_by' => 'মাল নিলেন :name',
    'gate_pass' => 'গেট পাস :no, গাড়ি :vehicle',
    'billed' => 'বিল :no, মোট :total',
];
