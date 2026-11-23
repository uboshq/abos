<?php

declare(strict_types=1);

/*
 * ⭐ নোট আর স্টক ছাড়া ভাউচারের তালিকা — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ ([[NoteListController]], [[TradeVoucherListController]])।
 * ⛔ মোট কেবল পাকা কাগজের; অপেক্ষমাণ আর খসড়া আলাদা লাইনে। অঙ্ক ইংরেজিতে (মালিক, ১৬ নভেম্বর)।
 */

return [
    'noun' => 'নোট',
    'noun_voucher' => 'ভাউচার',
    'search' => 'নম্বর, বিল, পক্ষ, SR/PR নম্বর…',
    'search_voucher' => 'নম্বর, রেফ, মূসক নম্বর, পক্ষ…',
    'none_voucher' => 'এখনো কোনো ভাউচার নেই',

    'stage_posted' => 'পোস্ট হয়েছে',
    'stage_waiting' => 'অনুমোদনের অপেক্ষায়',
    'stage_draft' => 'খসড়া',
    'stage_cancelled' => 'বাতিল',

    'filter_stage' => 'অবস্থা',
    'filter_date' => 'তারিখ',
    'filter_party_cn' => 'পক্ষ',
    'filter_party_cdn' => 'গ্রাহক',
    'filter_party_dn' => 'সরবরাহকারী',
    'filter_party_sale' => 'গ্রাহক',
    'filter_party_purchase' => 'সরবরাহকারী',
    'filter_reason' => 'কারণ',
    'filter_due' => 'শোধের শেষ তারিখ',
    'due_overdue' => 'তারিখ পার হয়েছে',
    'due_upcoming' => 'আজ বা পরে',
    'sort_due' => 'শেষ তারিখ আগে',

    'col_bill_cn' => 'মূল বিক্রয় বিল (মূসক-6.3)',
    'col_bill_cdn' => 'মূল বিক্রয় বিল (মূসক-6.3)',
    'col_bill_dn' => 'মূল ক্রয় বিল',
    'col_supplier_note' => 'সরবরাহকারীর মূসক-6.7',
    'col_amount' => 'অঙ্ক',
    'col_returns' => 'যুক্ত ফেরত',

    'party_sale' => 'গ্রাহক',
    'party_purchase' => 'সরবরাহকারী',
    'col_ref_sale' => 'রেফ / পিও নম্বর',
    'col_ref_purchase' => 'সরবরাহকারীর বিল নম্বর',
    'col_mushak_63' => 'মূসক-6.3 নম্বর',
    'col_due' => 'শোধের শেষ তারিখ',
    'col_total' => 'মোট',
    'col_tds' => 'উৎসে কর',
    'col_party_total_sale' => 'গ্রাহকের পাওনা',
    'col_party_total_purchase' => 'সরবরাহকারীর দেনা',

    'total_posted' => 'সর্বমোট — পোস্ট হওয়া',
    'total_waiting' => 'অপেক্ষমাণ — অনুমোদনের অপেক্ষায়',
    'total_drafts' => 'খসড়া',
    'count_money' => ':countটা · ৳:money',
];
