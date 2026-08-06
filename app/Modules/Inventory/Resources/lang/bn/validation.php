<?php

declare(strict_types=1);

return [
    'code_taken' => 'এই কোডে আরেকটা পণ্য আছে।',
    'barcode_taken' => 'এই বারকোডে (:barcode) আরেকটা পণ্য আছে — স্ক্যানার তখন কোনটা বেছে নেবে বলা যায় না।',
    'not_negative' => ':field ঋণাত্মক হতে পারে না।',
    'nothing_moves' => 'কোনো পরিমাণ দেওয়া হয়নি — শূন্য সারি খতিয়ানে শুধু ভিড় বাড়ায়।',
    'hold_needs_quantity' => 'কত আটকাবেন সেটা দিতে হবে।',
    'wrong_reason_context' => 'এই কারণটা মাল আটকানোর জন্য নয়।',
    'not_enough_available' => 'এত মাল বিক্রয়যোগ্য নেই — আছে :available।',
    'not_that_much_held' => 'এত মাল আটকানো নেই — আছে :held।',
    'not_enough_on_floor' => ':warehouse-এ :product এত নেই — আছে :have।',
    'warehouse_code_taken' => 'এই কোডে আরেকটা গুদাম আছে।',
    'not_enough_free' => ':warehouse-এ :product-এর ফ্রি স্টক আছে :have — তার বেশি ফ্রি দেওয়া যাবে না।',

    // স্থানান্তর
    'no_lines' => 'অন্তত একটা লাইন দরকার — লাইন ছাড়া স্থানান্তর কিছুই সরায় না।',
    'unknown_product' => 'পণ্যটা এই কোম্পানির তালিকায় নেই।',
    'unknown_warehouse' => 'গুদামটা এই কোম্পানির তালিকায় নেই।',
    'same_warehouse' => 'একই গুদামে স্থানান্তর হয় না — উৎস আর গন্তব্য আলাদা হতে হবে।',
    'not_enough_to_transfer' => ':warehouse-এ :product আছে :available — তার বেশি পাঠানো যাবে না।',
    'only_draft_dispatches' => ':no খসড়া নয়, তাই আবার রওনা দেওয়া যাবে না।',
    'only_dispatched_receives' => ':no এখনো রওনাই দেয়নি — বুঝে নেওয়ার কিছু নেই।',
    'received_cannot_cancel' => ':no পৌঁছে গেছে — বাতিল নয়, ফেরাতে হলে উল্টো দিকে আরেকটা স্থানান্তর করুন।',
    'only_draft_edits' => ':no খসড়া নয় — রওনা দেওয়ার পর আর বদলানো যায় না।',
    'already_cancelled' => ':no আগেই বাতিল হয়েছে।',
    'no_financial_year' => ':date তারিখটা কোনো খোলা অর্থবছরে পড়ে না।',
];
