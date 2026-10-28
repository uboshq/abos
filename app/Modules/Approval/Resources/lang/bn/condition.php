<?php

declare(strict_types=1);

/*
 * ⭐ শর্তের ঘরের নাম — নিয়মের পাতার তালিকায় (অনুমোদন পর্দা ভাগ ৩, দফা ১৭)।
 *
 * ⓘ ঘরগুলো কাগজের নিজের কলাম ([[ApprovalEngine::fieldsOf()]]); এখানে নাম না থাকলে তালিকায় কলামের নামই দেখায়
 * ([[ApprovalFlowController::conditionFields()]]), তাই একটা নাম ভুলে গেলেও ঘরটা হারায় না।
 */
return [
    'branch_id' => 'শাখা',
    'method' => 'পদ্ধতি',
    'payment_method' => 'পদ্ধতি',
    'type' => 'ধরন',
    'amount' => 'অঙ্ক',
    'total' => 'মোট',
    'total_amount' => 'মোট অঙ্ক',
    'net_total' => 'নিট মোট',
    'grand_total' => 'সর্বমোট',
    'discount_amount' => 'ছাড়ের অঙ্ক',
    'discount_percent' => 'ছাড়ের শতাংশ',
    'is_backdated' => 'আগের তারিখের',
    'customer_id' => 'গ্রাহক',
    'supplier_id' => 'সরবরাহকারী',
    'employee_id' => 'কর্মী',
    'warehouse_id' => 'গুদাম',
    'account_id' => 'খাত',
    'credit_limit' => 'বাকির সীমা',
    'credit_days' => 'বাকির দিন',
];
