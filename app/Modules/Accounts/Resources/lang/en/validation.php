<?php

declare(strict_types=1);

return [
    'code_taken' => 'Another account already uses code :code.',
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
    'cash_group_missing' => 'The chart has no ":code Cash in Hand" account. Install the standard chart first.',
    'unknown_voucher_type' => 'That is not a valid voucher type.',
    'no_lines' => 'A voucher needs at least one line.',
    'not_balanced' => 'Debit and credit do not match — debit :debit, credit :credit.',
    'amount_must_be_positive' => 'The amount must be more than zero.',
    'same_account_both_sides' => 'The same account cannot be on both sides — the money would go nowhere.',
    'account_missing' => 'One of the lines has no account.',
    'inactive_account' => '":name" is inactive, so it takes no new transactions.',
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
];
