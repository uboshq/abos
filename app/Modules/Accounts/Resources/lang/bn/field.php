<?php

declare(strict_types=1);

return [
    'card_commission' => 'ব্যাংকের কমিশন',
    'card_bank' => 'কার্ডের ব্যাংক',
    'card_reference' => 'টার্মিনাল রেফারেন্স',
    'lands_on' => 'কবে পৌঁছাবে',
    'deposit_slip' => 'জমা স্লিপ নম্বর',
    'account_no' => 'হিসাব নম্বর',
    'account_holder' => 'হিসাবধারীর নাম',
    'branch' => 'ব্রাঞ্চের নাম',
    'our_bank' => 'আমাদের যে ব্যাংক থেকে',
    'transfer_mode' => 'ট্রান্সফার মোড',
    'charge_borne_by' => 'চার্জটা কে দিয়েছে',
    'bank_charge' => 'ব্যাংক চার্জ',
    'charge' => 'চার্জ',
    'transaction_id' => 'ট্রানজেকশন আইডি',
    'receiver_phone' => 'প্রাপকের মোবাইল নম্বর',
    'sender_phone' => 'প্রেরকের মোবাইল নম্বর',
    'wallet_medium' => 'মাধ্যম',
    'wallet' => 'ওয়ালেট',
    'counted_total' => 'গোনা মোট',
    'note_of' => ':note টাকার নোট',
    'note_breakdown' => 'নোটের হিসাব',
    'how_it_moved' => 'টাকাটা কীভাবে এল',
    'moved_at' => 'কখন',
    'carried_by_nobody' => 'কেউ যায়নি — সরাসরি',
    'carried_by' => 'কার মাধ্যমে',
    'on_credit_option' => 'বাকিতে — :account (দেনা তৈরি হবে)',

    'closing_year' => 'যে বছর বন্ধ হচ্ছে',
    'next_year' => 'পরের বছর',
    'year_name' => 'বছরের নাম',
    'starts_on' => 'শুরু',
    'ends_on' => 'শেষ',
    'net_result' => 'বছরের নিট ফল',
    'accounts_to_close' => 'যত খাত শূন্য হবে',
    'financial_years' => 'অর্থবছরগুলো',
    'type_year_to_confirm' => 'নিশ্চিত করতে লিখুন: :name',
    'code' => 'কোড',
    'account_code' => 'হিসাব কোড',
    'name' => 'নাম',

    /*
     * ⭐ প্রতিটা ঘর বলে **কীসের** নাম, ১৩ সেপ্টেম্বর ২০২৬।
     *
     * মালিক হিসাবের ছকের ফর্মে জিজ্ঞেস করলেন: *"এখানে কার নাম লিখবে?
     * এটা স্পষ্ট করে দাও, এমন অনেক জায়গায় আছে।"*
     *
     * ⛔ ছয়টা মডিউলেই লেখা ছিল শুধু "নাম (ইংরেজি)"। পাতার শিরোনাম
     * থাকলেও ঘরটার পাশে যখন একজন মানুষের নাম বসে (যেমন "হেফাজতে"),
     * তখন পাঠক ভাবেন মানুষের নাম চাওয়া হচ্ছে।
     *
     * ⓘ পুরনো `name_en`/`name_bn` চাবি দুইটা **থেকে যাচ্ছে** — অনেক
     * জায়গায় ভুলের বার্তা ও তালিকার কলাম ওগুলো ধরে, আর নাম বদলালে
     * সেগুলো নীরবে চাবির নাম ছাপত।
     */
    'account_name_en' => 'খাতের নাম (ইংরেজি)',
    'account_name_bn' => 'খাতের নাম (বাংলা)',

    'till_name_en' => 'কাউন্টারের নাম (ইংরেজি)',
    'till_name_bn' => 'কাউন্টারের নাম (বাংলা)',
    'name_en' => 'নাম (ইংরেজি)',
    'name_bn' => 'নাম (বাংলা)',
    'parent' => 'উপরের খাত',
    'type' => 'ধরন',
    'nature' => 'প্রকৃতি',
    'is_group' => 'গ্রুপ খাত',
    'is_cash' => 'নগদ খাত',
    'is_bank' => 'ব্যাংক বা MFS খাত',
    'opening_balance' => 'খোলা ব্যালেন্স',
    'opening_date' => 'খোলার তারিখ',
    'account_number' => 'হিসাব নম্বর',
    'bank_name' => 'ব্যাংকের নাম',
    'account_title' => 'হিসাবের নাম',
    'routing_no' => 'রাউটিং নম্বর',
    'mfs_provider' => 'সেবাদাতা',
    'mfs_wallet' => 'নম্বর',
    'branch_name' => 'শাখার নাম',
    'holder' => 'হেফাজতে',
    'no_holder' => 'প্রতিষ্ঠানের (কারও ব্যক্তিগত নয়)',
    'limit' => 'সীমা',
    'primary' => 'প্রধান কাউন্টার',
    'in_hand' => 'হাতে আছে',
    'received' => 'জমা',
    'paid' => 'খরচ',
    'date' => 'তারিখ',
    'amount' => 'টাকার অঙ্ক',
    'from_account' => 'যে খাত থেকে',
    'to_account' => 'যে খাতে',
    'received_from' => 'কার কাছ থেকে',
    'party_type' => 'কী ধরনের পক্ষ',
    'collectable' => 'এখন তাঁর কাছে পাওনা',
    'ref_date' => 'কাগজের তারিখ',
    'money_category' => 'টাকার শ্রেণি',
    'money_subcategory' => 'উপ-শ্রেণি',
    'from_bank' => 'যিনি দিলেন — তাঁর ব্যাংক',
    'from_account_no' => 'যিনি দিলেন — হিসাব নম্বর',
    'received_into' => 'কোথায় জমা হল',
    'paid_from' => 'কোথা থেকে দেওয়া হল',
    'paid_to' => 'কাকে দেওয়া হল',
    'expense_head' => 'কীসের খরচ',
    'moved_from' => 'কোথা থেকে',
    'moved_to' => 'কোথায়',
    'instrument' => 'মাধ্যম',
    'instrument_no' => 'চেক/লেনদেন নম্বর',
    'instrument_date' => 'চেকের তারিখ',
    // লেজারে বসানোর মুহূর্তে চাওয়া হয়, তাই আলাদা নাম — ওখানে "চেক/"
    // অংশটা বিভ্রান্ত করত, বেশিরভাগ ক্ষেত্রে এটা বিকাশের TrxID
    /* ⓘ "চার্জ" — ব্যাংক বা বিকাশ যা কেটে রেখেছে, মালিকের দেওয়া
     অঙ্ক থেকে। ⚠️ মূলধন পুরোটাই থাকে; চার্জটা ব্যবসার খরচ। */
    'money_charge' => 'চার্জ কেটেছে',

    'bank_reference' => 'ব্যাংক/MFS লেনদেন নম্বর',
    'money_account_pick' => 'কোথায় এসেছে',
    'from_date' => 'শুরুর তারিখ',
    'to_date' => 'শেষ তারিখ',
    'given_by' => 'যিনি দিলেন',
    'received_by' => 'যিনি নিলেন',
    'note' => 'নোট',
    'pieces' => 'সংখ্যা',
    'counted' => 'গোনা হল',
    'expected' => 'খাতায় আছে',
    'difference' => 'পার্থক্য',
    'counted_by' => 'যিনি গুনলেন',
    'adjustment' => 'সমন্বয়ের জাবেদা',
    'money_in' => 'ঢুকল',
    'money_out' => 'বেরোল',
    'net_change' => 'নিট পরিবর্তন',
    'balance' => 'ব্যালেন্স',
    'state' => 'অবস্থা',
    'debit' => 'ডেবিট',
    'credit' => 'ক্রেডিট',

    // ঋণ
    'lender' => 'যিনি দিয়েছেন',
    'loan_account_no' => 'ব্যাংকের হিসাব নম্বর',
    'loan_kind' => 'ধরন',
    'loan_term' => 'টার্ম লোন',
    'loan_cc' => 'ক্যাশ ক্রেডিট (CC)',
    'loan_hand' => 'হাতধার',
    'loan_direction' => 'কোন দিকে',
    'loan_taken' => 'আমরা নিয়েছি',
    'loan_given' => 'আমরা দিয়েছি',
    'loan_due_on' => 'ফেরতের কথা',
    'sanctioned' => 'মঞ্জুরিকৃত',
    'cc_limit' => 'সীমা',
    'interest_rate' => 'সুদের হার (%)',
    'interest_method' => 'সুদের পদ্ধতি',
    'method_reducing' => 'কমতি জের',
    'method_flat' => 'ফ্ল্যাট',
    'tenure_months' => 'মেয়াদ (মাস)',
    'first_instalment_on' => 'প্রথম কিস্তির তারিখ',
    'liability_account' => 'দায়ের খাত',
    'interest_account' => 'সুদের খাত',
    'into_account' => 'টাকা কোথায় ঢুকবে',
    /*
     * ঋণের কিস্তি কোন খাত থেকে দেওয়া হবে — ভাউচারের `from_account` নয়।
     *
     * ⚠️ আগে দুইটারই নাম ছিল `from_account`, একই ফাইলে। PHP শেষেরটা রাখে,
     * তাই ভাউচারের পর্দা ও তার ভুল-বার্তা দুইটাতেই এই ঋণের লেখাটা বসত —
     * "যে খাত থেকে" জায়গায় "টাকা কোথা থেকে যাবে"। কেউ ধরেনি, কারণ
     * দুইটাই পড়তে যুক্তিসঙ্গত শোনায়।
     */
    'pay_from_account' => 'টাকা কোথা থেকে যাবে',
    'security' => 'জামানত',
    'instalment_no' => 'কিস্তি',
    'due_date' => 'তারিখ',
    'principal' => 'আসল',
    'interest' => 'সুদ',
    'instalment_total' => 'মোট',
    'as_on' => 'যে তারিখ পর্যন্ত',

    // ছাপা ভাউচারে
    'voucher_no' => 'ভাউচার নং',
    'account' => 'হিসাব খাত',
    'narration' => 'বিবরণ',
    'total_debit' => 'মোট ডেবিট',
    'total_credit' => 'মোট ক্রেডিট',

    // জাবেদার সারিতে কার নামে টাকাটা বসবে
    'party' => 'পক্ষ',

    // মাস বন্ধ ও খোলা
    'month' => 'মাস',
    'closed' => 'বন্ধ',
    'open' => 'খোলা',
    'reason' => 'কারণ',
    'reopen_reason' => 'খোলার কারণ (বাধ্যতামূলক)',

    // চেকের খাতা
    'cheque_no' => 'চেক নম্বর',
    'cheque_date' => 'চেকের তারিখ',
    'cheque_source' => 'যে আদায় থেকে →',
    'cheque_received' => 'গৃহীত',
    'cheque_issued' => 'ইস্যু করা',
    'cheque_direction' => 'দিক',
    'cheque_pending' => 'হাতে',
    'cheque_deposited' => 'জমা দেওয়া',
    'cheque_cleared' => 'পাশ',
    'cheque_bounced' => 'ফেরত',
    'cheque_cancelled' => 'বাতিল',
    'bounce_reason' => 'ফেরতের কারণ',
    'cheques_open_total' => 'এখনো ঝুলে থাকা চেক',
    'cheques_ripe' => 'তারিখ পেরিয়েছে, এখনো ঝুলছে',
    'cost_center' => 'খরচের কেন্দ্র',
    'no_cost_center' => '(কেন্দ্র বসানো হয়নি)',
    'spent' => 'খরচ',
    'earned' => 'আয়',
    'net' => 'নিট',
    'deposit_into' => 'কোন হিসাবে জমা হবে',
    'as_of' => 'কোন দিন পর্যন্ত',
    'all_branches' => 'সব শাখা',
    'assets' => 'সম্পদ',
    'liabilities_and_equity' => 'দায় ও মূলধন',
    'total_assets' => 'মোট সম্পদ',
    'total_funding' => 'মোট দায় ও মূলধন',
    'profit_this_year' => 'চলতি বছরের লাভ/ক্ষতি',
];
