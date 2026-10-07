<?php

declare(strict_types=1);

/*
 * ⭐ মূলধন ও বিনিয়োগের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ২ (৫ অক্টোবর ২০২৬, [[CapitalReports]])।
 */
return [
    'reports' => 'মূলধনের রিপোর্ট',
    'changes_short' => 'মালিকানার পরিবর্তন',
    'ledger_short' => 'একজনের খাতা',
    'return_short' => 'মূলধনের আয়',
    'reconcile_short' => 'রেজিস্টার বনাম খাতা',

    'branch' => 'শাখা',
    'person' => 'কার মূলধন',
    'nameless' => 'খাতায়, কারও নামে নয়',
    'retained' => 'অবণ্টিত মুনাফা (কোম্পানির)',

    // ক — মালিকানার পরিবর্তনের বিবরণী
    'changes_title' => 'মালিকানার পরিবর্তনের বিবরণী',
    'changes_summary' => 'শেষে মোট মালিকানা',
    'opening_balance' => 'শুরুর জের',
    'new_capital' => 'নতুন মূলধন',
    'opening_capital' => 'শুরুর মূলধন',
    'from_profit' => 'লাভ থেকে',
    'drawings' => 'উত্তোলন',
    'other_moves' => 'বণ্টন ও অন্যান্য',
    'closing_balance' => 'শেষ জের',

    // খ — একজনের মূলধনের খাতা
    'ledger_title' => 'মূলধনের খাতা',
    'opening_row' => 'খোলা জের',
    'kind_capital' => 'মূলধন',
    'kind_drawing' => 'উত্তোলন',

    // ঘ — মূলধনের আয়
    'return_title' => 'মূলধনের আয় (ROI)',
    'capital_start' => 'শুরুর মূলধন',
    'capital_end' => 'শেষের মূলধন',
    'capital_average' => 'গড় মূলধন',
    'profit' => 'লাভ',
    'return_pct' => 'আয়ের হার',
    'return_summary' => 'কোম্পানির আয়ের হার',
    'return_text' => ':rate% — লাভ ৳:profit, গড় মূলধন ৳:average',
    'return_none' => 'গড় মূলধন শূন্য বা কম — হার হয় না',

    // ঙ — রেজিস্টার বনাম খাতা
    'reconcile_title' => 'মূলধন: রেজিস্টার বনাম খাতা',
    'register_capital' => 'রেজিস্টারে মূলধন',
    'books_capital' => 'খাতায় মূলধন (৩১০০)',
    'register_drawings' => 'রেজিস্টারে উত্তোলন',
    'books_drawings' => 'খাতায় উত্তোলন (৩২০০)',
    'gap' => 'ফাঁক',
    'reconcile_summary' => 'রেজিস্টার আর খাতার ফাঁক',
    'reconcile_text' => 'মূলধনে ফাঁক ৳:capital, উত্তোলনে ফাঁক ৳:drawings',
];
