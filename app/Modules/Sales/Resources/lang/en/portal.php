<?php

declare(strict_types=1);

/**
 * গ্রাহক পোর্টাল ও জমার দাবির ভাষা।
 *
 * এখানকার লেখা গ্রাহক পড়েন, কর্মী নন। তাই কোনো পরিভাষা নেই: "বকেয়া",
 * "জমা", "বিল" — যে শব্দগুলো তিনি এমনিতেই বলেন।
 */
return [
    'title' => 'My account',
    'login_title' => 'Sign in',
    'code' => 'Your code',
    'code_hint' => 'It is printed at the top of every bill.',
    'password' => 'Password',
    'sign_in' => 'Sign in',
    'sign_out' => 'Sign out',
    'bad_login' => 'That code and password do not match.',

    'due' => 'You owe',
    'recent_bills' => 'Recent bills',
    'my_claims' => 'Deposits I have told you about',
    'no_bills' => 'No bills yet.',
    'no_claims' => 'You have not told us about any deposit yet.',

    'claim_title' => 'Tell us about a deposit',
    'claim_hint' => 'This does not change your balance on its own. We check it against the bank first.',
    'claimed_on' => 'Date you paid',
    'amount' => 'How much',
    'method' => 'How you paid',
    'bank' => 'Bank',
    'mfs' => 'bKash / Nagad',
    'cash' => 'Cash',
    'reference' => 'Slip or TrxID',
    'reference_hint' => 'Without this we cannot find it in the bank statement.',
    'bank_account' => 'Which of our accounts',
    'note' => 'Anything else',
    'send' => 'Send',

    'status' => 'Status',
    'pending' => 'We are checking',
    'accepted' => 'Accepted',
    'rejected' => 'Not found',

    'claim_raised' => 'Thank you — we will check it against the bank.',
    'accepted_message' => 'Accepted, and the collection is on the books.',
    'rejected_message' => 'Marked as not found.',
    'from_claim' => 'From customer claim :no',

    'amount_must_be_positive' => 'The amount has to be more than zero.',
    'not_in_the_future' => 'A deposit cannot be dated in the future.',
    'reason_required' => 'Say why, so the customer does not have to call.',
    'already_decided' => 'This one has already been decided.',

    // ডিপোর দিক
    'desk_title' => 'Deposit claims',
    'desk_subtitle' => 'What customers say they have paid — check it against the bank before you accept.',
    'customer' => 'Customer',
    'claimed' => 'They say',
    'received' => 'Actually received',
    'accept' => 'Accept',
    'reject' => 'Not found',
    'reason' => 'Why',
    'into_account' => 'Money went into',
    'only_pending' => 'Waiting',
    'show_all' => 'All',
    'empty' => 'Nothing waiting.',
    'closed' => 'Your portal is closed. Please contact the depot.',

    // The ledger page — the full answer to "how much do I owe".
    'ledger_title' => 'My ledger',
    'from' => 'From',
    'to' => 'To',
    'show' => 'Show',
    'date' => 'Date',
    'particulars' => 'Particulars',
    'debit' => 'Debit',
    'credit' => 'Credit',
    'balance' => 'Balance',
    'opening' => 'Opening balance',
    'no_entries' => 'No entries in this period.',
    'credit_limit' => 'Credit limit',

    // A limit of 0 does not mean "used up": it means cash or advance only.
    // Printing "0" would read as "your credit is finished" and start the
    // phone call this portal exists to prevent.
    'cash_only' => 'Cash / advance',
];
