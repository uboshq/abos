<?php

declare(strict_types=1);

/* ⓘ পয়েন্টের খাতা — [[LoyaltyLedger]], [[LoyaltyKind]] */
return [
    'kind' => [
        'earn' => 'অর্জিত',
        'redeem' => 'খরচ',
        'expire' => 'মেয়াদ শেষ',
        'reverse' => 'ফেরত',
    ],

    'points' => 'পয়েন্ট',
    'balance' => 'পয়েন্টের ব্যালান্স',
    'expires_on' => 'যতদিন খরচ করা যাবে',

    'not_positive' => 'খরচের পয়েন্ট শূন্যের বেশি হতে হবে।',
    'not_enough' => 'হাতে আছে মাত্র :balance পয়েন্ট; চাওয়া হয়েছে :asked।',
    'already_redeemed' => 'এই কাগজে পয়েন্ট আগেই খরচ হয়েছে।',
    'customer_unknown' => 'এই প্রতিষ্ঠানে এই ক্রেতাকে পাওয়া যায়নি।',

    'setting_valid_days' => 'পয়েন্ট কত দিন খরচ করা যাবে (দিন, ০ = কখনো ফুরোবে না)',
    'setting_point_value' => 'এক পয়েন্টের মূল্য (টাকা)',
];
