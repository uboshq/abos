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
        'expense_voucher' => 'খরচ ভাউচার',
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
        'edit' => 'সম্পাদনা',
        'approve' => 'অনুমোদন',
        'print' => 'প্রিন্ট',
        'export' => 'রপ্তানি',
        'share' => 'শেয়ার',
        'duplicate' => 'অনুলিপি',
        'history' => 'ইতিহাস',
        'help' => 'সাহায্য',
        'cancel' => 'বাতিল',
        'search' => 'খুঁজুন',
        'search_anything' => 'যেকোনো কিছু খুঁজুন…',
        'switch_language' => 'ভাষা বদলান',
        'logout' => 'লগ আউট',
        'more' => 'আরও',
        'fullscreen' => 'পূর্ণ পর্দা',
        'exit_fullscreen' => 'পূর্ণ পর্দা থেকে বেরোন (Esc)',
    ],

    'dashboard' => [
        'foundation_ready' => 'ভিত্তি প্রস্তুত',
        'foundation_note' => 'কোর engine, টেন্যান্ট স্কোপ, অনুমোদন, সংযুক্তি ও দ্বিভাষিক ব্যবস্থা কাজ করছে। মডিউলগুলো এখন এই ভিত্তির উপর বসবে।',
    ],

    'accounting' => [
        'receivable' => 'বকেয়া আদায়',
        'payable' => 'পরিশোধযোগ্য',
    ],

    'brand' => [
        'developed_by' => 'Developed by Al-Amin Shuvo',
        'full_name' => 'All Business Operating System',
        'tagline' => 'Built Around Your Business.',
        'powered_by' => 'Powered by UNIVER BANGLADESH',
    ],

    'appearance' => [
        'title' => 'চেহারা',
        'subtitle' => 'আপনার নিজের পর্দার রং, থিম ও ভাষা — কোম্পানির সেটিং নয়',
        'accent' => 'রং',
        'accent_note' => 'নির্দিষ্ট কয়েকটা রং, মুক্ত পিকার নয়: প্রতিটার কনট্রাস্ট যাচাই করা, তাই কোনোটাতেই বোতামের লেখা অপঠনযোগ্য হয় না।',
        'theme' => 'থিম',
        'theme_note' => 'হালকা নাকি গাঢ় পটভূমি।',
        'light' => 'হালকা',
        'dark' => 'গাঢ়',
        'language' => 'ভাষা',
        'saved' => 'সংরক্ষিত হয়েছে।',
    ],

    'accent' => [
        'blue' => 'নীল',
        'teal' => 'সবুজাভ নীল',
        'indigo' => 'ইন্ডিগো',
        'violet' => 'বেগুনি',
        'emerald' => 'সবুজ',
        'slate' => 'ধূসর',
    ],

    'components' => [
        'title' => 'কম্পোনেন্ট',
        'subtitle' => 'সব স্ক্রিন এই কম্পোনেন্টগুলোর উপরেই দাঁড়াবে — এক টুলবার, এক ফর্ম, এক টেবিল',
        'buttons' => 'বাটন',
        'badges' => 'ব্যাজ ও অবস্থা',
    ],

    'table' => [
        'date' => 'তারিখ',
        'document' => 'ডকুমেন্ট',
        'party' => 'পক্ষ',
        'debit' => 'ডেবিট',
        'credit' => 'ক্রেডিট',
        'balance' => 'ব্যালেন্স',
        'narration' => 'বিবরণ',
    ],

    'print' => [
        'paper' => [
            'a4' => 'A4',
            '80_mm' => '৮০ মিমি (থার্মাল)',
            '58_mm' => '৫৮ মিমি (থার্মাল)',
        ],
        'document_no' => 'নম্বর',
        'date' => 'তারিখ',
        'party' => 'পক্ষ',
        'account' => 'হিসাব',
        'total' => 'সর্বমোট',
        'in_words' => 'কথায়',
        'phone' => 'ফোন',
        'printed_at' => 'ছাপা হয়েছে',
        'prepared_by' => 'প্রস্তুতকারী',
        'approved_by' => 'অনুমোদনকারী',
        'received_by' => 'গ্রহীতার স্বাক্ষর',
        'show_vendor_credit' => 'প্রিন্টের নিচে "Powered by ABOS" দেখাও',
    ],

    'role' => [
        'owner' => 'মালিক',
        'accountant' => 'হিসাবরক্ষক',
        'salesman' => 'বিক্রয়কর্মী',
    ],

    'notice' => [
        'backup_stale' => 'দুই দিনের বেশি ব্যাকআপ হয়নি — ডিস্ক নষ্ট হলে কিছুই ফেরানো যাবে না।',
        'awaiting_decision' => '{1} ১টি সিদ্ধান্তের অপেক্ষায়|[2,*] :count টি সিদ্ধান্তের অপেক্ষায়',
    ],

    'import' => [
        'title' => 'পুরনো খাতা থেকে আনা',
        'note' => 'নমুনা ফাইলটা নামিয়ে ভরে আবার দিন। আগে দেখানো হবে কোন সারিগুলো ঠিক আছে, তারপর বসবে।',
        'what' => 'কী আনবেন',
        'file' => 'CSV ফাইল',
        'template' => 'নমুনা ফাইল',
        'check' => 'আগে দেখুন',
        'commit' => 'বসিয়ে দিন',
        'line' => 'সারি',
        'problem' => 'সমস্যা',
        'ok_rows' => '{0} একটাও সারি নেওয়ার মতো নয়|{1} ১টি সারি নেওয়ার মতো|[2,*] :count টি সারি নেওয়ার মতো',
        'bad_rows' => '{0} কোনো ভুল সারি নেই|{1} ১টি সারিতে সমস্যা|[2,*] :count টি সারিতে সমস্যা',
        'imported' => '{0} কিছুই বসেনি|{1} ১টি সারি বসেছে|[2,*] :count টি সারি বসেছে',
        'truncated' => 'ফাইলে :max টির বেশি সারি — বাকিগুলো এবার নেওয়া হয়নি। ফাইলটা ভাগ করে আবার দিন।',
        'empty_file' => 'ফাইলটা খালি।',
        'missing_column' => ':column ঘরটা খালি।',
        'not_a_number' => ':column সংখ্যা নয়।',
        'not_a_date' => ':column তারিখ নয় — দিন/মাস/বছর লিখুন।',
        'unknown_value' => ':column-এ ":value" চেনা গেল না।',
        'nothing_to_import' => 'ঠিক সারি একটাও নেই, তাই কিছু বসানো হয়নি।',
    ],

    'yes' => 'হ্যাঁ',
    'no' => 'না',

    'toolbar' => [
        'filter' => 'ফিল্টার',
        'sort_by' => 'সাজাও',
        'view' => 'দেখাও',
        'view_list' => 'তালিকা হিসেবে',
        'view_grid' => 'কার্ড হিসেবে',
        'columns' => 'কলাম',
        'density' => 'ঘনত্ব',
        'refresh' => 'নতুন করে আনো',
        'group' => 'গ্রুপ',
        'freeze' => 'আটকাও',
    ],

    'empty' => [
        'nothing_here' => 'এখানে এখনো কিছু নেই',
        'no_results' => 'খোঁজার সাথে কিছু মিলল না',
    ],

    'status_bar' => [
        'operational' => 'সচল',
        'maintenance' => 'রক্ষণাবেক্ষণ',
        'incident' => 'সমস্যা',
    ],

    'a11y' => [
        'skip_to_content' => 'সরাসরি কাজের অংশে যান',
        'module_navigation' => 'মডিউল বদলান',
        'collapse_sidebar' => 'সাইডবার গুটাও',
        'expand_sidebar' => 'সাইডবার খোলো',
        'filter_menu' => 'মেনুতে খুঁজুন…',
        'main_navigation' => 'প্রধান মেনু',
    ],

    'company' => [
        'switch' => 'কোম্পানি বদলান',
        'branch' => 'শাখা',
        'financial_year' => 'অর্থবছর',
    ],

    'form' => [
        // চোখে তারকা, শোনায় শব্দ — দুইটাই একই কথা বলে
        'required' => 'আবশ্যক',
        'optional' => 'ঐচ্ছিক',
    ],

    'profile' => [
        'title' => 'আমার প্রোফাইল',
        'subtitle' => 'আপনার নাম ও ছবি',
        'identity' => 'পরিচয়',
        'name' => 'নাম',
        'email' => 'ইমেইল',
        'photo' => 'ছবি',
        'photo_note' => 'JPG, PNG বা WebP — সর্বোচ্চ :mb মেগাবাইট। ছবিটা বর্গাকারে কেটে নেওয়া হবে।',
        'upload_photo' => 'ছবি দিন',
        'change_photo' => 'ছবি বদলান',
        'remove_photo' => 'ছবি সরান',
        'remove_confirm' => 'ছবিটা সরিয়ে ফেলা হবে। ঠিক আছে?',
        'saved' => 'সংরক্ষিত হয়েছে।',
    ],

    'avatar' => [
        'not_an_image' => 'ফাইলটা ছবি হিসেবে খোলা গেল না। JPG, PNG বা WebP দিন।',
        'too_large' => 'ছবিটা অনেক বড়। ছোট একটা ছবি দিন।',
    ],
];
