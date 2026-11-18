<?php

declare(strict_types=1);

/*
 * ⭐ দৈনিক খতিয়ান — মালিকের অনুমোদিত নমুনা (১৬ নভেম্বর ২০২৬)। [[DayBookService]], [[DayBookPaper]]।
 */
return [
    'title' => 'দৈনিক খতিয়ান',
    'caption' => 'DAY BOOK — JOURNAL ENTRIES',
    'subtitle' => 'একটা দিনের প্রতিটা খাতা-এন্ট্রি, জার্নাল ধরে — প্রতিটা এন্ট্রির সব লাইন একসাথে',
    'date' => 'তারিখ',
    'journals' => 'জার্নাল',
    'journals_value' => 'সব (:count টা)',
    'status' => 'অবস্থা',
    'status_value' => 'কেবল পোস্ট হওয়া',
    'branch' => 'শাখা',
    'entries' => 'এন্ট্রি',
    'entries_value' => ':count টা',
    'printed_by' => 'ছাপলেন',
    'show' => 'দেখান',
    'print' => 'ছাপুন',
    'pdf' => 'PDF',
    'col' => [
        'time' => 'সময়',
        'number' => 'এন্ট্রি নং',
        'reference' => 'রেফারেন্স',
        'party' => 'পার্টি / বিবরণ',
        'code' => 'কোড',
        'account' => 'হিসাব',
        'debit' => 'ডেবিট',
        'credit' => 'ক্রেডিট',
        'who' => 'কে',
    ],
    'journal' => [
        'sales' => 'বিক্রয় জার্নাল · Sales',
        'purchase' => 'ক্রয় জার্নাল · Purchase',
        'cash' => 'নগদ জার্নাল · Cash',
        'bank' => 'ব্যাংক জার্নাল · Bank',
        'journal' => 'সাধারণ জার্নাল · Journal',
    ],
    'journal_short' => [
        'sales' => 'বিক্রয় জার্নাল',
        'purchase' => 'ক্রয় জার্নাল',
        'cash' => 'নগদ জার্নাল',
        'bank' => 'ব্যাংক জার্নাল',
        'journal' => 'সাধারণ জার্নাল',
    ],
    'journal_total' => ':journal — মোট (:count টা এন্ট্রি)',
    'day_total' => 'দিনের মোট — :count টা এন্ট্রি',
    'carry_out' => 'পরের পৃষ্ঠায় জের →',
    'carry_in' => '← পূর্ব পৃষ্ঠার জের',
    'reversal' => 'উল্টো এন্ট্রি',
    'empty' => 'এই দিনে খাতায় কিছু লেখা হয়নি।',
    'not_equal' => 'ডেবিট আর ক্রেডিট মেলেনি — পার্থক্য :gap',
];
