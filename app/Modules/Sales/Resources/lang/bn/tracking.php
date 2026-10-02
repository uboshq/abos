<?php

declare(strict_types=1);

// ডেলিভারি ট্র্যাকিং — ২ অক্টোবর ২০২৬ ([[SaleTracking]])
return [
    'title' => 'ডেলিভারি ট্র্যাকিং',
    'search' => 'বিক্রয় নম্বর, ডিও বা দোকান',
    'find' => 'খুঁজুন',
    'all' => 'সব',
    'history' => 'কে কখন কী করলেন',
    'notice' => [
        'title' => ':no — :step',
        'body' => ':customer',
    ],
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
    // ⭐ টিকচিহ্নের দাগ — মালিকের আদেশ, ২ অক্টোবর ২০২৬
    'milestone' => [
        'order_created' => 'অর্ডার তৈরি',
        'approval_level' => 'অনুমোদন — স্তর :level',
        'challan_draft' => 'চালানের খসড়া',
        'challan_confirmed' => 'চালান নিশ্চিত',
        'stock_allocated' => 'মাল বরাদ্দ',
        'transport_assigned' => 'পরিবহন ঠিক',
        'loading_started' => 'লোডিং শুরু',
        'loading_completed' => 'লোডিং শেষ',
        'invoice_generated' => 'বিল তৈরি',
        'gate_pass_generated' => 'গেট পাস',
        'dispatched' => 'রওনা',
        'delivered' => 'পৌঁছেছে',
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
