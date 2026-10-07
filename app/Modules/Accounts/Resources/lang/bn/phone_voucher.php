<?php

declare(strict_types=1);

// ⭐ ফোনের ভাউচার — চার অবস্থার নাম (মালিক, ৭ অক্টোবর ২০২৬; [[VoucherApiController]])
return [
    'state_draft' => 'খসড়া',
    'state_awaiting' => 'সইয়ের অপেক্ষায়',
    'state_posted' => 'পাকা',
    'state_cancelled' => 'বাতিল',
    'edit_needs_network' => 'ফোন থেকে কেবল নতুন ভাউচার লেখা যায়। বদলাতে হলে নেট চালু করে ভাউচারের পাতা থেকে করুন।',
    'unknown_type' => 'ভাউচারের ধরন চেনা যায়নি। অ্যাপ হালনাগাদ করে আবার লিখুন।',
    'posted' => ':no পাকা হয়েছে — খাতায় উঠেছে।',
    'saved_draft' => ':no খসড়া হিসেবে রাখা হয়েছে।',
];
