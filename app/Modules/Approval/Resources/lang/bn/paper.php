<?php

/*
 * ⭐ কাজের নাম যেগুলো কোনো মডিউলের `approvals`-এ ঘোষিত নয়, অথচ ইনবক্সে আসে — দফা ৪ (মালিক, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ ভাউচারের সই চলে ভাউচারের ধরনে ([[VoucherApproval::actionFor()]]), আর পুরনো সারিতে আগের কাজের নাম থেকে যায়।
 * ⓘ মান অন্য ভাষা-চাবিও হতে পারে — তখন সেখানকার নামটাই আসে, এক নাম দুই জায়গায় লেখা হয় না।
 * ⛔ কোনো কাজের নাম এখানেও না পেলে "<মডিউল>-এর কাগজ" — কোড কখনো পর্দায় যায় না।
 */

return [
    'accounts' => [
        'receipt' => 'accounts::voucher.receipt',
        'payment' => 'accounts::voucher.payment',
        'expense' => 'accounts::voucher.expense',
        'journal' => 'accounts::voucher.journal',
        'contra' => 'accounts::voucher.contra',
    ],
    'sales' => [
        'invoice' => 'বিক্রয়ের বিল',
    ],
    'unknown' => ':module-এর কাগজ',
    'some_module' => 'অন্য মডিউল',
];
