<?php

declare(strict_types=1);

/*
 * হোম পর্দার "কমান্ড সেন্টার" মাথার লেখা — মালিকের নকশা, ১ অক্টোবর ২০২৬।
 *
 * ⓘ আলাদা ফাইলে কারণ core.php-তে তখন অন্য সেশনের অসমাপ্ত কাজ চলছিল।
 */
return [
    'command_center' => 'ব্যবসার কমান্ড সেন্টার',
    'command_center_flow' => 'সূচক → লক্ষ্য → পার্থক্য → ব্যতিক্রম → দায়িত্ব → পদক্ষেপ',
    'business_pictures' => 'ব্যবসার চিত্র',
    'details' => 'বিস্তারিত',
    'exceptions_title' => 'ব্যতিক্রম কেন্দ্র — এখন যা করা দরকার',
    // ⓘ মডিউল ড্যাশবোর্ডে উপরের চারটার পরে বাকি সূচক (ড্যাশবোর্ডের পরিকল্পনা §৪.৫, ৩১ অক্টোবর ২০২৬)
    'more_figures' => 'আরও অঙ্ক',
    // ⓘ মালিকের রঙিন নমুনার তালিকা — মডিউল ড্যাশবোর্ডে চার কার্ডের নিচে (৩১ অক্টোবর ২০২৬)
    'crore' => 'কোটি',
    'lakh' => 'লাখ',
    'money_as_booked' => 'খাতা অনুযায়ী, এই মুহূর্তে',
    'today_work' => 'আজ যা করতে হবে',
    'today_work_count' => ':countটা অপেক্ষায়',
    'watch_title' => 'নজরে রাখুন',
    'watch_hint' => 'চাপলে কাগজ',
    'watch_all_clear' => 'সব ঠিক — এখন কিছু করার নেই',
    'severity_warn' => 'সতর্কতা',
    'severity_attention' => 'মনোযোগ',
    'open' => 'দেখুন',
    'status_good' => 'ভালো',
    'status_bad' => 'জরুরি',
    'status_info' => 'তথ্য',
    'period' => 'সময়',
    'layout' => 'লেআউট সাজান',
    'layout_hint' => 'টিক = দেখা যাবে; সংখ্যা = কোনটা আগে (১ সবার উপরে)। কেবল আপনার জন্য।',
    'layout_part_period' => 'সময়ের কার্ড (আজ/মাস/বছর)',
    'layout_part_overall' => 'পুরো ব্যবসা এক নজরে',
    'layout_part_pictures' => 'ব্যবসার চিত্র (চার্ট)',
    'layout_part_exceptions' => 'যা করা বাকি',
    'layout_part_happenings' => 'এইমাত্র যা হলো',
    'layout_position' => 'ক্রম',
    'layout_reset' => 'আগের মতো',
    'layout_save' => 'রাখুন',
    'filter' => 'ফিল্টার',
    'filter_all' => 'সব',
    'filter_warehouse' => 'গুদাম',
    'filter_area' => 'এলাকা',
    'filter_seller' => 'SR (বিল যিনি কেটেছেন)',
    'filter_apply' => 'দেখাও',
    'filter_clear' => 'মুছুন',
    'filter_on' => 'ফিল্টার চালু',
    'filter_scope' => 'বদলেছে কেবল বিক্রি আর বকেয়া; বকেয়া গ্রাহকের, তাই তাতে খাটে কেবল এলাকা।',
    // ⓘ হোমের নতুন রূপ (পরিকল্পনা ২, ৫ অক্টোবর ২০২৬)
    'kpis_title' => 'মূল সূচক',
    'pictures_title' => 'ব্যবসার চিত্র',
    'layout_part_kpis' => 'মূল সূচক',
    'nothing_today' => 'এখনো কিছু নেই',
    // ⭐ Copilot-এর এক লাইন আর অবস্থার চিপ — নীল প্যানেলের নমুনা (মালিক, ১৮ নভেম্বর ২০২৬)
    'copilot_collected_up' => 'আজ আদায় গত :dayের চেয়ে :pct% বেশি।',
    'copilot_collected_down' => 'আজ আদায় গত :dayের চেয়ে :pct% কম।',
    'copilot_collected_same' => 'আজ আদায় গত :dayের সমান।',
    'copilot_drafts' => ':countটা খসড়া ভাউচার এখনো পোস্ট হয়নি — দিন বন্ধের আগে দেখে নিন।',
    'copilot_quiet' => 'আজ নজরে আনার মতো নতুন কিছু নেই — যা জানতে চান জিজ্ঞেস করুন।',
    'copilot_q_owed' => 'বাকি কার কাছে সবচেয়ে বেশি?',
    'copilot_q_spent' => 'আজ খরচ কোথায় গেল?',
    'copilot_ask' => ':name-কে জিজ্ঞেস করুন: :question',
    'level_urgent' => 'জরুরি',
    'level_today' => 'আজই',
    'level_week' => 'এ সপ্তাহে',
    'level_ok' => 'ঠিক আছে',
    'money_total' => 'মোট',
];
