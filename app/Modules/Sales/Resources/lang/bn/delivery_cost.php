<?php

declare(strict_types=1);

/*
 * ডেলিভারি খরচ — চালান অনুযায়ী ([[DeliveryCostReport]], নমুনা v6 খ, ২১ নভেম্বর ২০২৬)।
 */
return [
    'title' => 'ডেলিভারি খরচ — চালান অনুযায়ী',
    'menu' => 'ডেলিভারি খরচ',
    'noun' => 'চালান',
    'search' => 'চালান নং, গাড়ি বা পার্টি খুঁজুন',
    'empty' => 'এই সময়ে কোনো পোস্ট হওয়া চালান নেই।',

    'group_label' => 'দেখাও',
    'by_challan' => 'চালান',
    'by_vehicle' => 'গাড়ি',
    'by_point' => 'পয়েন্ট',
    'by_month' => 'মাস',

    'date' => 'তারিখ',
    'vehicle' => 'গাড়ি',
    'point' => 'পয়েন্ট',
    'party' => 'পার্টি',
    'threshold' => 'সীমা %',
    'threshold_chip' => 'সীমা: :n%',

    'col_date' => 'তারিখ',
    'col_challan' => 'চালান নং',
    'col_place' => 'পয়েন্ট / পার্টি',
    'col_vehicle' => 'গাড়ি',
    'col_count' => 'চালান',
    'col_value' => 'চালানের মূল্য',
    'col_fare' => 'গাড়ি ভাড়া',
    'col_loading' => 'লোডিং খরচ',
    'col_other' => 'অন্যান্য',
    'col_total' => 'মোট খরচ',
    'col_percent' => 'খরচ %',
    'total_row' => 'মোট (:nটা চালান)',
    'no_vehicle' => 'গাড়ি নেই',
    'over_hint' => 'সীমার উপরে',

    'n_challans' => ':nটা চালান',
    'card_value' => 'মোট চালানের মূল্য',
    'card_cost' => 'মোট ডেলিভারি খরচ',
    'card_cost_sub' => 'ভাড়া :fare · লোডিং :loading · অন্যান্য :other',
    'card_average' => 'গড় খরচ %',
    'card_average_sub' => 'মোট খরচ ÷ মোট মূল্য',
    'card_top' => 'সবচেয়ে বেশি %',

    'chart_vehicle' => 'গাড়ি অনুযায়ী খরচ %',
    'chart_point' => 'পয়েন্ট অনুযায়ী খরচ %',
    'chart_month' => 'মাস অনুযায়ী খরচ %',
    'chart_legend' => 'দাগ = :n% সীমা · কমলা = সীমার উপরে',
    'chart_line' => ':label: ৳:value-এ খরচ ৳:cost',

    'setting_threshold' => 'ডেলিভারি খরচের সীমা (%)',
];
