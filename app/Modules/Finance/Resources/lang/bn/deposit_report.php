<?php

declare(strict_types=1);

/*
 * ⭐ আমানতের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ৪ (৬ অক্টোবর ২০২৬, [[DepositReports]])।
 */
return [
    'reports' => 'আমানতের রিপোর্ট',
    'accrued_short' => 'জমা সুদ',

    'deposit' => 'জমা',
    'institution' => 'প্রতিষ্ঠান',
    'holder' => 'কার নামে',
    'holder_business' => 'ব্যবসা',
    'holder_owner' => 'মালিক',
    'principal' => 'আসল',
    'profit_rate' => 'হার',
    'matures_on' => 'মেয়াদপূর্তি',

    // ক — জমা সুদ
    'accrued_title' => 'জমা সুদ (অর্জিত, এখনো না-পাওয়া)',
    'accrued' => 'অর্জিত',
    'source_tax' => 'উৎসে কর',
    'net_accrued' => 'নিট',
    'accrued_summary' => 'মোট জমা সুদ, নিট',
    'accrued_text' => 'অর্জিত ৳:gross − উৎসে কর ৳:tax = ৳:net',

    // খ — DPS কিস্তি
    'instalments_short' => 'DPS কিস্তি',
    'instalments_title' => 'DPS কিস্তির সময়সূচি',
    'month' => 'মাস',
    'due_on' => 'কিস্তির দিন',
    'due' => 'দেয়',
    'paid' => 'দেওয়া',
    'waiting' => 'সইয়ের অপেক্ষায়',
    'outstanding' => 'বাকি',
    'overdue' => 'বকেয়া',
    'instalments_summary' => 'বকেয়া কিস্তি',
    'instalments_text' => 'বকেয়া ৳:overdue (সইয়ের অপেক্ষায় ৳:waiting)',

    // গ — ঘণ্টির খবর
    'notice_soon' => ':institution-এর :document — মেয়াদ এই সপ্তাহে',
    'notice_matured_body' => 'মেয়াদপূর্তি :date, :days দিন আগে — জমাটা এখনো খোলা। ভাঙানো বা নবায়ন লিখুন।',
    'notice_dps' => ':institution-এর :document — DPS কিস্তি বকেয়া',
    'notice_dps_body' => ':count মাসের কিস্তি বকেয়া — ৳:amount',

    // ঘ — ঋণের বিপরীতে জামানত
    'liens_short' => 'ঋণের জামানত',
    'liens_title' => 'ঋণের বিপরীতে জামানত (লিয়েন)',
    'loan' => 'কোন ঋণে',
    'sanctioned' => 'ঋণের সীমা',
    'owed' => 'ঋণের বাকি',
    'lien_state' => 'অবস্থা',
    'lien_locked' => 'আটকানো',
    'lien_free' => 'ছাড়ার যোগ্য',

    'pledge_needs_live_facility' => 'বন্ধকের ঋণটা এই কোম্পানির চালু ব্যাংক ঋণ হতে হবে।',

    // ⭐ মাসিক অর্জিত মুনাফা (পরিকল্পনা ৪.২)
    'accrual_month' => 'কোন মাস',
    'accrual_run' => 'মাসের অর্জিত মুনাফা খাতায় বসান',
    'accrual_note' => 'মাসের শেষ দিনে বসে, পরের মাসের প্রথম দিনে নিজে উল্টায়; এক মাস একবারই।',
    'accrual_done' => ':accrued জমায় মুনাফা বসল, :reversed আগের জমা উল্টাল, :held সইয়ের অপেক্ষায়।',
    'accrual_month_not_over' => 'মাসটা এখনো শেষ হয়নি — শেষ হওয়া মাসই কেবল বসানো যায়।',
    'accrual_narration' => ':month-এর অর্জিত মুনাফা — :deposit (:institution)',
    'accrual_reversal_narration' => ':month-এর অর্জিত মুনাফা উল্টানো — :deposit',

    // ⭐ ব্যাংক যা কেটে রাখে (প্র২)
    'source_tax_cut' => 'উৎসে কর (ব্যাংক কেটেছে)',
    'excise_duty' => 'আবগারি শুল্ক',
    'penalty' => 'আগে ভাঙানোর জরিমানা',
    'deduction_negative' => 'কাটা টাকা ঋণাত্মক হয় না।',
    'deduction_owner' => 'মালিকের নামের জমার কর বা কাটা ব্যবসার খাতায় বসে না।',
    'penalty_only_on_close' => 'জরিমানা কেবল জমা ভাঙানোর সময়।',

    // ⭐ ব্যাংক জামানত ভাঙিয়ে ঋণ শোধ (প্র৫)
    'lien_title' => 'ব্যাংক জামানত ভাঙিয়ে ঋণ শোধ করেছে',
    'lien_hint' => 'ব্যাংকের চিঠি দেখে লিখুন: কত টাকা ঋণে গেল, বাড়তি কিছু আমাদের দিলে কোন খাতে, আর কী কেটেছে।',
    'lien_applied' => 'ঋণে গেল',
    'lien_remainder' => 'বাড়তি — আমাদের হিসাবে এল',
    'lien_run' => 'জামানত ভাঙিয়ে ঋণ শোধ লিখুন',
    'lien_done' => ':no — জামানত ভাঙিয়ে ঋণ শোধ লেখা হল।',
    'lien_needs_live_facility' => 'এই জমা কোনো চালু ব্যাংক ঋণে বাঁধা নয় — সাধারণ ভাঙানো ব্যবহার করুন।',
    'lien_applied_over_owed' => 'ঋণে যাওয়া টাকা সেই দিনের বাকির (৳:owed) বেশি — বাড়তিটা "আমাদের হিসাবে এল" ঘরে লিখুন।',
    'lien_narration' => ':no — জামানত ভাঙিয়ে :loan শোধ',
];
