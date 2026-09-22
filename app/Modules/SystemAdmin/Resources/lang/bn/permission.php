<?php

declare(strict_types=1);

/**
 * অনুমতির মানুষের-পড়ার-মতো নাম — রোলের পর্দার জন্য।
 *
 * ── ⛔ কেন এই ফাইলটা লাগল, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────
 * রোলের পর্দা এতদিন অনুমতির **কাঁচা নাম** দেখাত — `accounts.voucher.update`,
 * `inventory.stock.opening`। ⓘ মালিক দুইটা নকশা পাঠিয়ে বললেন পর্দাটা
 * এমন হোক যেখানে প্রতিটা জিনিসের পাশে দেখা · তৈরি · সম্পাদনা · মোছা।
 *
 * ⚠️ কাঁচা নামে টিক দেওয়া মানে না বুঝে অধিকার দেওয়া — আর রোলের পর্দায়
 * ভুল টিক মানে ভুল মানুষের হাতে টাকার দরজা।
 *
 * ── ⓘ চাবির গঠন ──────────────────────────────────────────────────────
 * `subjects` — অনুমতির নামের শেষ অংশ বাদে বাকিটা (`accounts.voucher`)।
 * `verbs`    — শেষ অংশ (`update`)।
 * ⚠️ এখানে নেই এমন চাবি পর্দায় কাঁচা নাম থেকে বানানো লেখায় দেখায় — নতুন
 * অনুমতি যোগ হলে পর্দা ভাঙে না, কেবল নামটা এখানে বসাতে হয়।
 */
return [
    'subjects' => [
        'accounts' => 'পুরো হিসাব মডিউল',
        'accounts.asset' => 'স্থায়ী সম্পদ',
        'accounts.backdate' => 'পুরনো তারিখে লেখা',
        'accounts.cheque' => 'চেক',
        'accounts.coa' => 'হিসাবের ছক',
        'accounts.count' => 'নগদ গণনা',
        'accounts.loan' => 'ঋণ',
        'accounts.period' => 'মাসের তালা',
        'accounts.reconciliation' => 'ব্যাংক মিলকরণ',
        'accounts.report' => 'হিসাবের রিপোর্ট',
        'accounts.till' => 'ক্যাশ টিল',
        'accounts.transfer' => 'টাকা হস্তান্তর',
        'accounts.voucher' => 'ভাউচার',

        'approval' => 'পুরো অনুমোদন মডিউল',
        'approval.flow' => 'অনুমোদনের ছক',

        'backup' => 'ব্যাকআপ',

        'customer' => 'গ্রাহক',
        'customer.conduct' => 'গ্রাহকের আচরণ',
        'customer.credit_limit' => 'বাকির সীমা',

        'finance.bank_facility' => 'ব্যাংক ঋণ',
        'finance.capital' => 'মূলধন ও বিনিয়োগ',
        'finance.deposit' => 'আমানত',
        'finance.expense' => 'খাতভিত্তিক খরচ',
        'finance.hand_loan' => 'হাতধার',
        'finance.income' => 'আয়',
        'finance.plan' => 'ফিন্যান্স মানচিত্র',
        'finance.rental' => 'ভাড়ার চুক্তি',
        'finance.withdrawal' => 'মালিকের উত্তোলন',

        'governance.audit' => 'অডিট লগ',
        'governance.error' => 'ত্রুটির খাতা',
        'governance.export' => 'রপ্তানির খাতা',
        'governance.login' => 'লগইনের খাতা',

        'hr.attendance' => 'হাজিরা',
        'hr.employee' => 'কর্মী',
        'hr.identity' => 'পরিচয়পত্র (NID)',
        'hr.leave' => 'ছুটি',
        'hr.payroll' => 'বেতনের রান',
        'hr.salary' => 'বেতন কাঠামো',

        'inventory' => 'পুরো মজুদ মডিউল',
        'inventory.batch' => 'লট',
        'inventory.cost' => 'ক্রয়মূল্য দেখা',
        'inventory.product' => 'পণ্য',
        'inventory.stock' => 'মজুদ',
        'inventory.transfer' => 'গুদাম বদল',
        'inventory.warehouse' => 'গুদাম',

        'master_data' => 'মাস্টার ডাটা',

        'purchase' => 'পুরো ক্রয় মডিউল',
        'purchase.bill' => 'ক্রয় বিল',
        'purchase.order' => 'ক্রয় আদেশ',
        'purchase.payment' => 'সরবরাহকারীকে পরিশোধ',
        'purchase.receipt' => 'মাল বুঝে নেওয়া',
        'purchase.return' => 'ক্রয় ফেরত',
        'purchase.settlement' => 'হিসাব মেটানো',

        'restaurant' => 'পুরো রেস্টুরেন্ট মডিউল',
        'restaurant.kitchen' => 'রান্নাঘর',
        'restaurant.production' => 'রান্না',
        'restaurant.recipe' => 'রেসিপি',

        'sales' => 'পুরো বিক্রয় মডিউল',
        'sales.challan' => 'ডেলিভারি চালান',
        'sales.claim' => 'কোম্পানির কাছে দাবি',
        'sales.collection' => 'আদায়',
        'sales.commission' => 'কমিশন',
        'sales.cost' => 'ক্রয়মূল্য দেখা',
        'sales.discount' => 'ছাড়',
        'sales.invoice' => 'বিক্রয় বিল',
        'sales.order' => 'বিক্রয় আদেশ',
        'sales.reprint' => 'আবার ছাপা',
        'sales.return' => 'বিক্রয় ফেরত',
        'sales.scheme' => 'স্কিম',
        'sales.shipment' => 'চালান পাঠানো',
        'sales.target' => 'লক্ষ্যমাত্রা',

        'supplier' => 'সরবরাহকারী',

        'system_admin.audit' => 'অডিট',
        'system_admin.backup' => 'ব্যাকআপের সেটিং',
        'system_admin.company' => 'কোম্পানি',
        'system_admin.import' => 'আমদানি',
        'system_admin.look' => 'চেহারা ও থিম',
        'system_admin.ownership' => 'মালিকানা হস্তান্তর',
        'system_admin.reports' => 'রিপোর্টের সময়সূচি',
        'system_admin.role' => 'রোল',
        'system_admin.settings' => 'সেটিংস',
        'system_admin.user' => 'ব্যবহারকারী',
    ],

    'verbs' => [
        'view' => 'দেখা',
        'create' => 'তৈরি',
        'update' => 'সম্পাদনা',
        'delete' => 'মোছা',
        'cancel' => 'বাতিল',
        'manage' => 'পরিচালনা',
        'report' => 'রিপোর্ট',
        'approve' => 'অনুমোদন',
        'override' => 'নিয়ম পেরোনো',
        'close' => 'বন্ধ করা',
        'reopen' => 'আবার খোলা',
        'confirm' => 'নিশ্চিত করা',
        'decide' => 'সিদ্ধান্ত',
        'post' => 'খাতায় বসানো',
        'move' => 'টাকা সরানো',
        'cap' => 'মাসিক সীমা',
        'final' => 'চূড়ান্ত রিপোর্ট',
        'portal' => 'পোর্টাল',
        'self' => 'নিজের',
        'adjust' => 'সমন্বয়',
        'hold' => 'আটকানো',
        'opening' => 'খোলা মজুদ',
        'place' => 'গুদামে বসানো',
        'receive' => 'গ্রহণ',
        'reprice' => 'দাম বদল',
        'pos' => 'POS',
        'configure' => 'কনফিগার',
        'download' => 'ডাউনলোড',
        'failover' => 'বিকল্প সার্ভার',
        'restore' => 'পুনরুদ্ধার',
        'run' => 'চালানো',
        'schedule' => 'সময়সূচি',
        'transfer' => 'হস্তান্তর',
    ],

    // ── পর্দার লেখা ──────────────────────────────────────────────────────
    'column_subject' => 'জিনিস',
    'column_view' => 'দেখা',
    'column_create' => 'তৈরি',
    'column_update' => 'সম্পাদনা',
    'column_delete' => 'মোছা / বাতিল',
    'column_special' => 'বিশেষ',
    'manage_spans' => 'পরিচালনা — তৈরি · সম্পাদনা · মোছা একসাথে',
    'roles' => 'রোলের তালিকা',
    'search_roles' => 'রোল খুঁজুন',
    'users_in_role' => 'এই রোলে যাঁরা আছেন',
    'search_users' => 'নাম খুঁজুন',
    'no_users' => 'এই রোলে এখনো কেউ নেই।',
    'owner_locked' => 'মালিকের রোল — সব পারে, বদলানো যায় না',
    'granted' => ':on / :all',
    'select_all' => 'সব',

    /*
     * ⭐ অনুমতির পর্দার ভাগ — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ নামগুলো মেনুর ভাগের সাথে এক, ইচ্ছাকৃতভাবে: মানুষ মেনুতে
     * জিনিসটা যে ভাগে দেখেন, এখানেও সেই ভাগেই খুঁজবেন।
     */
    'sections' => [
        'master' => 'তথ্যভান্ডার',
        'transactions' => 'লেনদেন',
        'reports' => 'রিপোর্ট',
        'settings' => 'সেটিংস',
        'dashboard' => 'ড্যাশবোর্ড',
        'other' => 'অন্যান্য অধিকার',
    ],
    'select_all_module' => 'গোটা মডিউল একসাথে',
];
