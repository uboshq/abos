<?php

declare(strict_types=1);

/*
 * ⭐ ক্রয়ের খরচ — ক্রয় ইনভয়েস অনুযায়ী (মালিকের অনুমোদিত নমুনা v6, ট্যাব গ; ২১ নভেম্বর ২০২৬)।
 * ⓘ লেখাগুলো নমুনার হুবহু; সংখ্যা ইংরেজি অঙ্কে।
 */
return [
    'menu' => 'ক্রয়ের খরচ',
    'title' => 'ক্রয়ের খরচ — ক্রয় ইনভয়েস অনুযায়ী',
    'title_en' => 'Purchase cost — by purchase invoice',
    'setting_threshold' => 'ক্রয়ের খরচ রিপোর্ট: সীমা %',

    'group_label' => 'দেখাও',
    'by' => [
        'invoice' => 'ইনভয়েস',
        'supplier' => 'সরবরাহকারী',
        'month' => 'মাস',
        'product' => 'পণ্য',
    ],

    'filter_date' => 'তারিখ',
    'supplier' => 'সরবরাহকারী',
    'product' => 'পণ্য',
    'threshold' => 'সীমা',
    'threshold_percent' => 'সীমা %',
    'all' => 'সব',
    'all_branches' => 'সব শাখা',
    'chip_date' => 'তারিখ: :range',
    'chip_branch' => 'শাখা: :name',
    'chip_supplier' => 'সরবরাহকারী: :name',
    'chip_product' => 'পণ্য: :name',
    'chip_threshold' => 'সীমা: :n%',

    'print' => 'প্রিন্ট',
    'pdf' => 'PDF',
    'excel' => 'Excel',

    'card_value' => 'মোট ক্রয় মূল্য',
    'card_value_sub' => ':nটা ক্রয় · :cartons কার্টন',
    'card_cost' => 'মোট আনা-নামানোর খরচ',
    'card_cost_sub' => 'লেবার :labour · ভাড়া :fare · অন্যান্য :other',
    'card_pct' => 'গড় খরচ %',
    'card_pct_sub' => 'মোট খরচ ÷ মোট ক্রয় মূল্য',
    'card_per_carton' => 'প্রতি কার্টনে খরচ',
    'card_per_carton_sub' => 'সবচেয়ে বেশি: :no · ৳:amount',

    'col' => [
        'date' => 'তারিখ',
        'no' => 'বিল / GRN নং',
        'supplier' => 'সরবরাহকারী',
        'month' => 'মাস',
        'product' => 'পণ্য',
        'purchases' => 'ক্রয়',
        'lines' => 'সারি',
        'cartons' => 'কার্টন',
        'value' => 'ক্রয় মূল্য',
        'labour' => 'মাল নামানো (লেবার)',
        'fare' => 'আনার ভাড়া',
        'other' => 'অন্যান্য',
        'total' => 'মোট খরচ',
        'pct' => 'খরচ %',
        'per_carton' => 'প্রতি কার্টন',
    ],
    'direct' => '(সরাসরি ক্রয়)',
    'over' => 'সীমার উপরে',
    'total_label' => 'মোট (:nটা ক্রয়)',
    'empty' => 'এই সময়ে কোনো পাকা ক্রয় নেই।',

    'chart_supplier' => 'সরবরাহকারী অনুযায়ী খরচ %',
    'chart_product' => 'পণ্য অনুযায়ী খরচ %',
    'chart_note' => 'দাগ = :n% সীমা · কমলা = সীমার উপরে',
    'chart_bar' => ':name — খরচ :pct% (৳:value-এ ৳:cost)',
];
