<?php

declare(strict_types=1);

/*
 * ⭐ ভাড়ার চুক্তি ও জামানতের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ৫ (৬ অক্টোবর ২০২৬, [[RentalReports]])।
 */
return [
    'reports' => 'ভাড়ার রিপোর্ট',
    'schedule_short' => 'ভাড়ার সময়সূচি',

    'month' => 'মাস',
    'contract' => 'চুক্তি',
    'counterparty' => 'বাড়িওয়ালা',
    'subject' => 'জায়গা',

    // ক — ভাড়ার সময়সূচি
    'schedule_title' => 'ভাড়ার সময়সূচি',
    'rent_due' => 'দেয় ভাড়া',
    'paid_cash' => 'নগদে দেওয়া',
    'from_deposit' => 'জামানত থেকে',
    'waiting' => 'সইয়ের অপেক্ষায়',
    'outstanding' => 'বাকি',
    'schedule_summary' => 'বাকি ভাড়া',
    'schedule_text' => 'দেয় ৳:due, বাকি ৳:outstanding (সইয়ের অপেক্ষায় ৳:waiting)',

    // খ — অগ্রিম সমন্বয়
    'advance_short' => 'অগ্রিম সমন্বয়',
    'advance_title' => 'অগ্রিম ও জামানতের সমন্বয়',
    'opening_balance' => 'শুরুর জের',
    'given' => 'দেওয়া',
    'deducted' => 'ভাড়ায় কাটা',
    'refunded' => 'ফেরত',
    'closing_balance' => 'শেষের জের',
    'monthly_adjustment' => 'মাসে কাটে',
    'months_left' => 'আর কত মাস',
    'advance_summary' => 'জামানতে মোট বাকি',

    // গ — জামানতের খাতা
    'book_short' => 'জামানতের খাতা',
    'book_title' => 'জামানতের খাতা',
    'opening_row' => 'খোলা জের',
    'legacy' => 'শুরুর জামানত (ভাউচার ছাড়া)',
    'taken_back' => 'কাটা / ফেরত',

    // ঘ — সতর্কতা (ঘণ্টির খবর আর ড্যাশবোর্ড)
    'notice_ending' => ':who — ভাড়ার চুক্তি শেষ হয়ে আসছে (:place)',
    'notice_ending_body' => ':date-এ শেষ, আর :days দিন। নবায়ন বা ছেড়ে দেওয়ার নোটিশ সময়মতো দিন।',
    'notice_ended_body' => ':date-এ মেয়াদ শেষ, :days দিন আগে — চুক্তিটা এখনো চালু। নবায়ন করুন বা শেষ করুন।',
    'notice_overdue' => ':who — ভাড়া বকেয়া (:place)',
    'notice_overdue_body' => ':count মাসের ভাড়া বকেয়া (:months) — ৳:amount',
    'dash_label' => 'বকেয়া ভাড়া',
    'dash_hint' => ':overdue চুক্তিতে ভাড়া বকেয়া · :ending চুক্তি ৬০ দিনের মধ্যে শেষ',

    // ঙ — চুক্তির তালিকা
    'contracts_short' => 'চুক্তির তালিকা',
    'contracts_title' => 'ভাড়ার চুক্তির তালিকা',
    'monthly_rent' => 'মাসিক ভাড়া',
    'deposit_left' => 'জামানতে বাকি',
    'starts_on' => 'শুরু',
    'ends_on' => 'শেষ',
    'days_left' => 'আর কত দিন',
    'state' => 'অবস্থা',
    'state_running' => 'চলছে',
    'state_lapsed' => 'মেয়াদ পেরিয়েছে',
    'state_closed' => 'শেষ',

    // ⭐ শর্তের ইতিহাস আর বৃদ্ধি (মালিক, প্র১)
    'increase_percent' => 'বছরে বৃদ্ধি % (চুক্তিতে থাকলে)',
    'effective_from' => 'কোন মাস থেকে',
    'term_line' => ':from থেকে মাসে ৳:rent',
    'term_outside_contract' => 'এই মাস চুক্তির মেয়াদের বাইরে।',
    'notice_anniversary' => ':who — চুক্তির বর্ষপূর্তি আসছে (:place)',
    'notice_anniversary_body' => ':date-এ বর্ষপূর্তি। চুক্তিতে :percent% বৃদ্ধির কথা আছে — এখনকার ৳:rent থেকে হয় ৳:next। বাড়লে শর্ত বদলে লিখুন।',
];
