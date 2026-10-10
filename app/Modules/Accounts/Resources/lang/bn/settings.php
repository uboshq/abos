<?php

declare(strict_types=1);

/** Control Panel-এ যে লেখাগুলো দেখাবে — নিয়ম ৭। */
return [
    'opening_to_capital' => 'খোলা জের (গ্রাহকের বাকি, খোলা মজুদ, খাতের জের) মালিকের মূলধনে যাবে — বন্ধ করলে সংরক্ষিত মুনাফায়',
    'backdate_days' => 'কত দিন পেছনের তারিখে এন্ট্রি নেওয়া যাবে',
    'cash_ceiling_enabled' => 'ব্যক্তিপ্রতি নগদ সীমা চালু',
    'cash_ceiling_blocks' => 'সীমা ছাড়ালে টাকা নেওয়া আটকে দাও (নাহলে শুধু সতর্ক করবে)',
    'require_narration' => 'ভাউচারে বিবরণ বাধ্যতামূলক',
    'asset_capitalisation_threshold' => 'স্থায়ী সম্পদের সর্বনিম্ন দাম — এর কম দামের জিনিস খরচে যাবে (০ মানে কোনো সীমা নেই)',
    'asset_prorata' => 'প্রথম মাসের অবচয়',
    'asset_prorata_full_month' => 'পুরো মাস',
    'asset_prorata_daily' => 'দিন ধরে ভাগ',
    'asset_idle_stops_depreciation' => 'অলস পড়ে থাকা সম্পদে অবচয় থামবে',
    'asset_auto_run' => 'মাসের অবচয় নিজে নিজে বসবে (মাসের প্রথম দিনে, গত মাসের)',
    'print_signature_lines' => 'প্রিন্টে স্বাক্ষরের ঘর রাখো',
    'paper_voucher' => 'ভাউচার কোন কাগজে',
    'design_voucher' => 'ভাউচারের নকশা',
    'design' => [
        'standard' => 'সাধারণ',
        'aurora' => 'অরোরা গ্রেডিয়েন্ট',
        'bento' => 'বেন্টো কার্ড',
        'neo_brutal' => 'নিও-ব্রুটাল',
        'soft_minimal' => 'নরম মিনিমাল',
        'dark_mode' => 'গাঢ় মাথা',
        'quick_green' => 'অ্যাকাউন্টিং সবুজ',
        'cloud_blue' => 'ক্লাউড নীল',
        'tally_classic' => 'ট্যালি ক্লাসিক',
        'sheet_grid' => 'শিট ছক',
        'bank_form' => 'ব্যাংক ফর্ম',
        'modern_green' => 'আধুনিক সবুজ',
        'corporate_navy' => 'কর্পোরেট নীল',
        'modern_card' => 'আধুনিক কার্ড',
        'swiss_grid' => 'সুইস',
        'sidebar_band' => 'পাশের পট্টি',
        'bangla_heritage' => 'পুরো বাংলা',
        'premium_gold' => 'প্রিমিয়াম সোনালি',
        'editorial_serif' => 'সম্পাদকীয় সেরিফ',
        'ink_saver' => 'কালি বাঁচানো',
        'seal_boxes' => 'সিলমোহরের ঘর',
    ],
    'paper_transfer' => 'টাকা হস্তান্তরের স্লিপ কোন কাগজে',
    'paper_note' => 'ডেবিট/ক্রেডিট নোট কোন কাগজে',
    'note_footnote' => 'ডেবিট/ক্রেডিট নোটের নিচের লেখা',
    // ⭐ অংশ ৩গ, ৭ অক্টোবর ২০২৬
    'voucher_maker_checker' => 'ভাউচার যিনি লেখেন তিনি নিজে পোস্ট করেন না — অন্য কেউ করেন',

    // ⭐ স্থায়ী সম্পদ ধাপ ৩
    'asset_revaluation' => 'স্থায়ী সম্পদের পুনর্মূল্যায়ন চালু (বন্ধ থাকলে খরচের মডেল)',
];
