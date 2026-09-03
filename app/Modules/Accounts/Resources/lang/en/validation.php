<?php

declare(strict_types=1);

return [
    'year_confirm_name' => 'Type ":name" exactly to confirm.',
    'year_already_closed' => 'This financial year is already closed.',
    'year_has_drafts' => ':count draft vouchers are still unposted. Once the year closes they can never be posted — post or cancel them first.',
    'year_overlaps' => 'These dates overlap another financial year. With the same date in two years there is no way to say which one an entry belongs to.',
    'code_taken' => 'Another account already uses code :code.',
    'import_group_no_opening' => 'A group account (:code) cannot hold an opening balance — put it on the accounts beneath it.',
    'unknown_type' => 'That is not a valid account type.',
    'parent_not_found' => 'That parent account was not found.',
    'parent_must_be_group' => 'An account that takes entries cannot hold other accounts. '
        .'Make the parent a group first.',
    'parent_cannot_be_own_descendant' => 'An account cannot sit under itself.',
    'has_entries_cannot_group' => 'This account has transactions, so it cannot become a group — '
        .'groups take no entries of their own, and the existing ones would stop being counted.',
    'has_children_must_stay_group' => 'This account holds other accounts, so it must stay a group.',
    'has_entries_cannot_retype' => 'This account has transactions, so its type cannot change — '
        .'the existing entries would move to a different report.',
    'system_account_locked' => '":name" is a system account. Sales, purchases and other modules '
        .'look it up by code, so it cannot be changed or removed.',
    'group_cannot_take_entries' => 'A group account takes no entries. Pick one of the accounts under it.',
    'cash_or_bank_not_both' => 'An account cannot be both cash and bank.',
    'group_is_not_money' => 'A group holds no money, so it cannot be marked as cash or bank.',
    'till_code_taken' => 'Another cash counter already uses code :code.',
    'till_has_money' => 'This counter still holds :amount. Deposit or transfer it first, then close.',
    'primary_till_cannot_close' => 'The main cash counter cannot be closed — end-of-day deposits need somewhere '
        .'defined to go. Make another one primary first.',
    'no_transit_account' => 'The chart has no ":code Cash in Transit" account. Install the standard chart first — without it there is nowhere for handed-over money to sit.',
    'cash_group_missing' => 'The chart has no ":code Cash in Hand" account. Install the standard chart first.',
    'unknown_voucher_type' => 'That is not a valid voucher type.',
    'no_lines' => 'A voucher needs at least one line.',
    'not_balanced' => 'Debit and credit do not match — debit :debit, credit :credit.',
    'amount_must_be_positive' => 'The amount must be more than zero.',
    'same_account_both_sides' => 'The same account cannot be on both sides — the money would go nowhere.',
    'account_missing' => 'One of the lines has no account.',
    'inactive_account' => '":name" is inactive, so it takes no new transactions.',
    // Bank money cannot be reconciled without a reference, and the same
    // reference twice means the same money twice
    'bank_reference_required' => 'Money moving through :account needs its bank or MFS transaction number — without it there is no way to reconcile later.',
    'bank_reference_used' => 'Transaction number :reference is already on voucher :no. The same money cannot be booked twice.',

    'already_posted' => 'Voucher :no has already been posted.',
    'already_cancelled' => 'This voucher is already cancelled.',
    'cancelled_cannot_post' => 'A cancelled voucher cannot be posted.',
    'posted_cannot_edit' => ':no is posted and cannot be changed. '
        .'To correct it, cancel and issue a new voucher — that is the rule on paper too.',
    'cancel_reason_required' => 'A reason for cancelling is required.',
    'no_financial_year' => 'No financial year covers :date.',
    'year_closed' => 'Financial year :year is closed, so nothing new can be posted into it.',
    'line_needs_account' => 'This line has an amount but no account.',
    'line_both_sides' => 'A line cannot carry both a debit and a credit — split it into two.',
    'journal_needs_two_lines' => 'A journal needs amounts on at least two lines.',
    'till_not_found' => 'That cash counter was not found.',
    'transfer_no_destination' => 'Choose where the money goes — a counter or the bank.',
    'same_till_both_sides' => 'The same counter cannot be on both sides.',
    'not_enough_in_hand' => 'Only :have is in hand — more than that cannot be handed over.',
    'transfer_already_confirmed' => 'This transfer has already been received.',
    'transfer_cancelled' => 'A cancelled transfer cannot be received.',
    'count_already_approved' => 'This count has already been approved.',
    'no_adjustment_account' => 'There is no :type account to post the difference to. Add one to the chart.',
    'loan_amount_positive' => 'The amount must be more than zero.',
    'loan_over_limit' => 'Only :available is left on the limit — no more than that can be drawn.',
    'instalment_already_paid' => 'This instalment has already been paid.',

    // জাবেদার সারিতে পক্ষ — তিন কোণা সমন্বয়ের জন্য
    'party_half_written' => 'A party needs both its kind and its name — otherwise there is no telling later whose money it was.',
    'party_unknown' => 'That party could not be found.',

    // মাস বন্ধ ও খোলা
    'cannot_close_this_month' => 'This month cannot be closed — today’s sales would stop.',

    // চেকের খাতা
    'cheque_direction' => 'Say whether the cheque was received or issued.',
    'cheque_needs_amount' => 'A cheque needs an amount above zero.',
    'cheque_needs_bank' => 'Say which bank account the money lands in.',
    'cheque_already_decided' => 'Cheque :no has already been decided.',
    'bounce_needs_reason' => 'Say why it bounced — "no funds" and "signature mismatch" are not the same thing.',
    'chart_not_installed' => 'The chart of accounts has not been installed yet.',
    'opening_head_missing' => 'The chart has no account :code (Opening Balance Equity)',
];
