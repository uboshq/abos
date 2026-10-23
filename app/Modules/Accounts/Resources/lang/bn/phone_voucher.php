<?php

declare(strict_types=1);

// ⭐ ফোনের ভাউচার — চার অবস্থার নাম (মালিক, ৭ অক্টোবর ২০২৬; [[VoucherApiController]])
return [
    'state_draft' => 'খসড়া',
    'state_awaiting' => 'সইয়ের অপেক্ষায়',
    // ⓘ খসড়ার সইয়ের অবস্থা — অ্যাপের কাছে অচেনা মানের fallback (a4-এর সঙ্গে চুক্তি, ২৪ অক্টোবর ২০২৬)
    'signing_awaiting' => 'সইয়ের অপেক্ষায়',
    'signing_stale' => 'সই চাওয়ার পরে বদলেছে — পোস্ট চাপলে নতুন করে সইয়ে যাবে',
    'signing_needs' => 'পোস্টে সই লাগবে',
    'signing_signed' => 'সই হয়েছে — পোস্ট বাকি',
    'signing_rejected' => 'সই ফেরানো',
    'state_posted' => 'পাকা',
    'state_cancelled' => 'বাতিল',
    'edit_needs_network' => 'ফোন থেকে কেবল নতুন ভাউচার লেখা যায়। বদলাতে হলে নেট চালু করে ভাউচারের পাতা থেকে করুন।',
    'unknown_type' => 'ভাউচারের ধরন চেনা যায়নি। অ্যাপ হালনাগাদ করে আবার লিখুন।',
    'posted' => ':no পাকা হয়েছে — খাতায় উঠেছে।',
    'saved_draft' => ':no খসড়া হিসেবে রাখা হয়েছে।',

    // ⭐ টাকা কার — মালিক, ৭ অক্টোবর ২০২৬ ("kake dibe kar kach theke nibe")
    'party_from' => 'কার কাছ থেকে',
    'party_to' => 'কাকে',
    'party_any' => 'পক্ষ',
    'money_into' => 'কোথায় জমা হলো',
    'money_out_of' => 'কোথা থেকে গেল',
    // ⭐ কনট্রার দুই দিক — মালিক, ১৭ অক্টোবর ২০২৬ ("recived into, pay From")
    'contra_from' => 'কোথা থেকে',
    'contra_to' => 'কোথায় গেল',
];
