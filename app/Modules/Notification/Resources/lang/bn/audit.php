<?php

declare(strict_types=1);

return [
    'title' => 'বিজ্ঞপ্তির নিরীক্ষা',
    'note' => 'বিজ্ঞপ্তির ওপর কে কখন কী করলেন, আর তার ফল। খাতাটা কেউ বদলাতে পারেন না।',
    'empty' => 'এখনো কিছু লেখা নেই।',
    'action' => 'কাজ',
    'outcome' => 'ফল',
    'actor' => 'কে',
    'target' => 'কোনটায়',
    'detail' => 'বিস্তারিত',
    'when' => 'কখন',
    'from' => 'থেকে',
    'to' => 'পর্যন্ত',
    'system' => 'ব্যবস্থা নিজে',
    'actions' => [
        'delivery_retry' => 'হাতে আবার চেষ্টা',
        'delivery_cancel' => 'ডেলিভারি বাতিল',
        'channel_update' => 'মাধ্যমের সেটিং বদল',
        'channel_vapid' => 'Web Push-এর চাবি তৈরি',
        'channel_test' => 'মাধ্যমের সংযোগ পরীক্ষা',
        'push_subscribe' => 'ব্রাউজারে পুশ চালু',
        'push_unsubscribe' => 'ব্রাউজারে পুশ বন্ধ',
        'read_all' => 'সব পড়া',
        'bulk_read' => 'বাছাগুলো পড়া',
        'bulk_unread' => 'বাছাগুলো না-পড়া',
        'bulk_archive' => 'বাছাগুলো আর্কাইভ',
        'bulk_restore' => 'বাছাগুলো ফেরত',
        'archive' => 'আর্কাইভ',
        'restore' => 'ফেরত',
        'open_denied' => 'অনুমতি ছাড়া খোলার চেষ্টা',
        'center_archive' => 'কেন্দ্র থেকে আর্কাইভ',
        'center_restore' => 'কেন্দ্রে ফেরত',
    ],
    'outcomes' => [
        'done' => 'হয়েছে',
        'denied' => 'আটকানো',
        'failed' => 'ব্যর্থ',
    ],
];
