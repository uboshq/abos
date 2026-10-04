<?php

declare(strict_types=1);

return [
    'credit_needs_a_party' => 'An expense on credit must say who it is owed to — otherwise money sits in payables with no owner.',

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
    'cash_needs_a_keeper' => 'Pick who holds this cash. Cash is always in a pair of hands, and without a name there is no answer at the end of the day to who has how much.',

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

    'charge_eats_the_whole_amount' => 'The charge cannot be equal to or more than the amount. Enter the amount that was sent, not the amount that arrived.',
    'charge_needs_a_bank_or_mfs' => 'A charge only applies to a bank or mobile money account. Nothing is deducted from cash, so leave the field empty.',
    'charge_account_missing' => 'The charge account :code is not in the chart. Add it under Accounts ▸ Chart of accounts first, or the charge has nowhere to go.',
    'same_account_both_sides' => 'The same account cannot be on both sides — the money would go nowhere.',
    'account_missing' => 'One of the lines has no account.',
    'inactive_account' => '":name" is inactive, so it takes no new transactions.',
    // Bank money cannot be reconciled without a reference, and the same
    // reference twice means the same money twice
    'bank_reference_required' => 'Money moving through :account needs its bank or MFS transaction number — without it there is no way to reconcile later.',
    'bank_reference_used' => 'Transaction number :reference is already on voucher :no. The same money cannot be booked twice.',

    'inter_company_their_books' => 'In the books of :company — :problem',
    'inter_company_needs_receiving_account' => 'On their side choose a money, expense or liability account (not income or equity).',
    'inter_company_same' => 'A company cannot send money to itself.',
    'inter_company_not_mine' => 'You must belong to both companies.',
    'inter_company_no_key_there' => 'You do not have the inter-company key in that company.',
    'inter_company_needs_money_account' => 'Choose a money account (cash, bank or MFS).',
    'inter_company_no_control' => 'The inter-company current account (:code) is not in this company\'s chart.',
    'inter_company_amount' => 'The amount must be more than zero.',
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
    // ⭐ গ৫ — গ্রহণ কেবল বাক্সের মালিক, গ্রহণের পরে বাতিল কেবল যাঁর হাতে টাকা (৪ অক্টোবর ২০২৬)
    'transfer_not_your_box' => 'You do not hold this cash box — its holder receives the money.',
    'transfer_sender_cannot_receive' => 'The sender cannot receive their own transfer — someone else must receive it.',
    'transfer_cancel_only_receiver' => 'Already received — only the person now holding the money can cancel it.',
    'count_already_approved' => 'This count has already been approved.',
    'no_adjustment_account' => 'There is no :type account to post the difference to. Add one to the chart.',
    'loan_amount_positive' => 'The amount must be more than zero.',
    'loan_over_limit' => 'Only :available is left on the limit — no more than that can be drawn.',
    'instalment_below_interest' => 'An instalment cannot be less than this month\'s interest (:interest).',
    'instalment_already_paid' => 'This instalment has already been paid.',

    // জাবেদার সারিতে পক্ষ — তিন কোণা সমন্বয়ের জন্য
    'party_half_written' => 'A party needs both its kind and its name — otherwise there is no telling later whose money it was.',
    'party_unknown' => 'That party could not be found.',
    // ⭐ গ৪ — দুই পাশে কোন ধরনের খাত, সার্ভারেও (৪ অক্টোবর ২০২৬)
    'account_must_be_money' => 'Only a cash, bank or MFS account goes here — not ":account".',
    'account_must_be_expense' => 'Only an expense account goes here — not ":account".',
    'account_must_be_money_or_owed' => 'An expense is paid from a money account or a payable — not from ":account".',
    'transfer_bank_only' => 'A transfer goes only to a counter or a bank — not to ":account".',
    // ⭐ গ২ — "কোন কাগজের বিপরীতে" যাচাই (৪ অক্টোবর ২০২৬)
    'against_unknown' => 'The paper this voucher is written against was not found — or it is not one a voucher settles.',
    'against_closed' => 'The paper this voucher is written against is no longer open — already settled, cancelled, or awaiting its signature.',
    'against_wrong_type' => 'This paper is not settled by this kind of voucher — money coming in takes a receipt, money going out a payment.',
    'against_wrong_amount' => 'The amount does not match — :amount on the voucher, :expected on the paper.',
    'against_wrong_party' => 'The party does not match — the money must belong to the party on the paper.',

    // মাস বন্ধ ও খোলা
    'cannot_close_this_month' => 'This month cannot be closed — today’s sales would stop.',

    // চেকের খাতা
    'cheque_direction' => 'Say whether the cheque was received or issued.',
    'cheque_needs_amount' => 'A cheque needs an amount above zero.',
    'cheque_needs_party' => 'Say whose cheque this is — otherwise nobody\'s due falls when it clears.',
    'cheque_only_through_register' => 'Cheques are not taken here — enter it in the cheque register. It reaches the books when it clears.',
    'cheque_needs_a_bank_account' => ':account is not a bank account — a cheque is deposited or cleared only into a bank account, never a cash till or bKash.',
    'cheque_not_due_yet' => 'Cheque :no is dated :date — it cannot be deposited or cleared before that day.',
    'bank_reference_meaningless' => '":reference" is not a transaction number — enter the real bank or bKash number (at least four letters or digits, not just zeros).',
    'way_does_not_fit_account' => '":way" was chosen, but the money goes to :account — the way and the account do not match.',
    'not_enough_money_in' => ':account holds :held — :amount cannot go out of it. A cash or bKash balance never goes below zero.',
    'cheque_not_from_collection' => 'Cheque :no was not taken on a receipt — return it with the Bounce button on the cheque register.',
    'cleared_cheque_not_cancelled' => 'Cheque :no has cleared and cannot be cancelled. If the bank took the money back, mark it bounced.',
    'cheque_needs_bank' => 'Say which bank account the money lands in.',
    'cheque_already_decided' => 'Cheque :no has already been decided.',
    'cheque_needs_no' => 'A cheque needs its number and date.',
    'cheque_duplicate' => 'Cheque :no from this bank is already in the register.',
    'cheque_bounce_via_receipt' => 'Cheque :no was taken on a receipt — use the Bounce button on the cheque register to return it.',
    'bounce_needs_reason' => 'Say why it bounced — "no funds" and "signature mismatch" are not the same thing.',
    'chart_not_installed' => 'The chart of accounts has not been installed yet.',
    'opening_head_missing' => 'The chart has no account :code (Opening Balance Equity)',
    'year_reopen_super_admin' => 'Only a super admin can reopen a closed year.',
    'year_not_closed' => 'That year is not closed.',
    'year_reopen_latest_only' => 'Only the year closed most recently can be reopened — right now that is ":name".',
    /* Cash only into your own till - 21 September 2026. */
    'cash_not_your_till' => ':account is not your till - cash can only go into your own.',
    'no_till_of_your_own' => 'No till is held in your name, so cash cannot be taken - choose bank or MFS.',
    /* The money-account rule - MoneyAccountRule, 27 September 2026. */
    'unknown_account' => 'That account is not in this company chart.',
    'group_takes_no_money' => '":name" is a head, not an account — money posted there shows up in no balance. Pick one of the accounts under it.',
    'not_a_money_account' => ':name is not a cash or bank account — money does not land there.',
    'cheque_already_registered' => 'Cheque :no is already registered (:doc) — the same cheque cannot be entered twice.',
];
