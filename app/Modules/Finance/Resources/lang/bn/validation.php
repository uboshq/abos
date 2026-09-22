<?php

declare(strict_types=1);

return [
    'more_than_declared' => 'ঘোষিত মুনাফার চেয়ে বেশি তোলা যাবে না — বাকি আছে :left। আগে বণ্টন ঘোষণা করুন, নয়তো ধরনটা "নিজের খরচ" করুন।',
    'nothing_left_to_capitalise' => 'ঘোষিত লাভের কারও কিছু বাকি নেই — সবাই তুলে নিয়েছেন বা আগেই মূলধনে যোগ হয়েছে।',
    'unknown_capital_kind' => 'মূলধনে নাকি বিনিয়োগে — এই দুইটার একটা বাছতে হবে।',
    'profit_must_be_positive' => 'মুনাফা শূন্য বা ঋণাত্মক হলে ভাগ করা যায় না — লোকসান আলাদা সিদ্ধান্ত।',
    'nobody_has_a_share' => 'এখনো কারও বাকি মূলধন নেই, তাই ভাগ বসানোর কেউ নেই।',
    'chart_account_missing' => 'খাত :code এই কোম্পানিতে বসেনি। চালান: php artisan abos:sync-chart',
    'charge_eats_the_whole_thing' => 'চার্জ মোট অঙ্কের চেয়ে কম হতে হবে — নাহলে খাতে কিছুই ঢুকত না।',
    'capital_already_posted' => ':no আগেই খাতায় বসেছে',
    'not_a_postable_account' => 'এটা একটা মাথা, খাত নয়',
    'unknown_contributor_type' => 'অচেনা পরিচয়',
    'unknown_capital_kind' => 'অচেনা ধরন',
    'capital_must_be_positive' => 'অঙ্কটা শূন্যের বেশি হতে হবে',
    'deposit_must_be_positive' => 'মূলধনটা শূন্যের বেশি হতে হবে',
    'unknown_holder' => 'কার নামে — ব্যবসা না মালিক, একটা বেছে নিন',
    'kind_is_personal_only' => ':kind ব্যবসার নামে কেনা যায় না — ব্যক্তির নামেই হয়',
    'payout_account_needed' => 'নিয়মিত মুনাফা কোথায় আসবে সেটা বলতে হবে',
    'deposit_takes_no_instalment' => 'এই জমায় কিস্তি হয় না',
    'deposit_already_closed' => ':no আগেই শেষ হয়েছে',
    'chart_head_missing' => 'হিসাবের ছকে :code খাতটা নেই',
    'deposit_already_cancelled' => ':no আগেই বাতিল হয়েছে',
    'cancel_reason_needed' => 'কেন বাতিল করছেন সেটা লিখতে হবে',
    'hand_loan_needs_a_name' => 'কার সাথে, সেটা লিখতে হবে',
    'hand_loan_which_way' => 'টাকাটা গেল না এল, সেটা বলতে হবে',
    'hand_loan_amount_positive' => 'অঙ্কটা শূন্যের বেশি হতে হবে — উল্টো দিকে গেলে সেটাকে উল্টো দিক হিসেবেই লিখুন',
    'hand_loan_not_clear' => 'এখনো :amount বাকি — শূন্য না হলে চুকে গেছে বলা যায় না',
    'hand_loan_already_settled' => ':who-এর হিসাব আগেই চুকে গেছে',
    'withdrawal_needs_a_name' => 'কে তুলছেন, সেটা লিখতে হবে',
    'withdrawal_must_be_positive' => 'অঙ্কটা শূন্যের বেশি হতে হবে',
    'withdrawal_already_posted' => ':no আগেই খাতায় বসেছে',
    'withdrawal_awaits_approval' => ':no এখনো অনুমোদনের অপেক্ষায় — অনুমোদনের আগে টাকা যাবে না',
    'withdrawal_over_cap' => 'মাসিক সীমা :cap — এই মাসে আর :left তোলা যাবে। বেশি লাগলে সীমাটা বদলান',
    'rental_closed' => 'চুক্তিটা শেষ হয়ে গেছে — শেষ হওয়া চুক্তিতে আর কিছু বসানো যায় না।',
    'rental_term_needed' => 'মেয়াদ কত মাস, সেটা লিখতে হবে।',
    'rental_adjustment_over_rent' => 'জামানত থেকে ভাড়ার চেয়ে বেশি কাটা যায় না।',
    'rental_adjustment_exceeds_deposit' => 'পুরো মেয়াদে কাটা পড়বে :whole, অথচ জামানতে আছে :deposit। মাঝপথেই ফুরিয়ে যেত।',
    'rental_no_deposit_left' => 'জামানতে আছে মাত্র :left — এর বেশি কাটা যায় না।',
    'rental_needs_money_account' => 'নগদের অংশটা কোন খাত থেকে যাবে, সেটা বাছুন।',
    'rental_amount_positive' => 'অঙ্কটা শূন্যের বেশি হতে হবে।',
    'rental_head_missing' => 'ছকে :code খাতটা নেই — হিসাবের ছক থেকে বসিয়ে নিন।',
    'rental_month_done_already' => ':month মাসের ভাড়া আগেই বসানো হয়েছে — দুইবার বসালে ঐ মাসের খরচ দ্বিগুণ হত।',

    // দুইটা ভিন্ন উত্তর পেলে চুপচাপ একটা বেছে নেওয়া হয় না — জিজ্ঞেস করা হয়,
    // নাহলে মানুষ ভাবতেন নতুন নামটা বসেছে অথচ টাকা বসত পুরনো কারো নামে
    'person_pick_or_type' => 'হয় তালিকা থেকে বাছুন, নয় নতুন নাম লিখুন — দুইটা একসাথে নয়।',
    'capital_needs_a_name' => 'কে দিচ্ছেন, সেটা বাছুন — অথবা নতুন নাম লিখুন।',
    'facility_needs' => ':kind সুবিধায় এই ঘরটা ছাড়া হিসাব করা যায় না।',

    // ⭐ ব্যবহৃত ধরন মোছা যায় না — ২০ সেপ্টেম্বর ২০২৬
    'kind_in_use' => ':name ধরনে জমা খোলা হয়ে গেছে, তাই মোছা যাবে না — নিষ্ক্রিয় করুন, তাতে পুরনো কাগজগুলো অটুট থাকবে।',

    'opening_needs_a_liability_account' => 'আগে থেকে চলতে থাকা ঋণ তুলতে দায়ের খাত লাগে। CC-র বকেয়া ব্যাংক হিসাবের জেরেই থাকে — খাতের খোলা ব্যালেন্সে বসানোই ঠিক পথ।',
    'opening_needs_the_chart' => 'হিসাবের মানদণ্ড ছকটা আগে বসাতে হবে — সঞ্চিত মুনাফার খাত নেই।',
];
