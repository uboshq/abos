<?php

declare(strict_types=1);

return [
    'charge_theirs_out' => 'The receiver bore the charge, so exactly the stated amount left our account and it costs us nothing.',
    'charge_theirs_in' => 'The sender paid the charge on top, so we received the full amount and it costs us nothing.',
    'charge_ours' => 'The charge is our expense: the account receives the amount less the charge, and the party\'s ledger still shows the full amount.',
    'pdc_issued' => 'A post-dated cheque. The money does not leave until that date; the liability sits in 2115 Cheques Issued.',
    'pdc_received' => 'A post-dated cheque. Cash does not rise until that date; the cheque sits in 1104 Cheques in Hand.',
    'count_differs' => 'The counted notes do not match the amount, and it will not post until they do.',
    'count_agrees' => 'The counted notes match the amount.',
    'expense_on_credit' => '⚠️ No money leaves now — this expense becomes a payable, to be settled later.',

    'chart_empty' => 'The chart of accounts is empty.',
    'chart_empty_note' => 'Install the standard chart to get going — it is built for Bangladeshi '
        .'distribution and retail. Deactivate whatever you do not need afterwards.',
    'chart_installed' => 'Standard chart installed — :count accounts.',
    'created' => 'Account added.',
    'updated' => 'Account updated.',
    'deactivated' => 'Account deactivated.',
    'activated' => 'Account activated.',
    'deactivate_confirm' => 'This account and everything under it will be deactivated. Past transactions stay. Continue?',
    'group_hint' => 'A group is only a heading — it takes no entries of its own and shows the total of what sits under it.',
    'parent_sets_type' => 'Pick a parent and the type comes from it.',

    /*
     * এই তিনটা বাক্য একটা প্রশ্নের জায়গায় বসেছে।
     *
     * আগে ফর্ম জিজ্ঞেস করত "এটা কি নগদ খাত?" আর "এটা কি ব্যাংক বা
     * MFS খাত?" — দুইটা টিক, যা বাবার খাতের সাথে অসঙ্গত রাখা যেত।
     * এখন উত্তরটা গাছ থেকেই আসে, তাই পর্দা কেবল জানিয়ে দেয় — আর
     * বাক্যটা লেখা হয়েছে যাতে কেউ **ভুল মাথা বেছেছেন কি না** সেটা
     * পড়েই বুঝতে পারেন।
     */
    'charge_hint' => 'What the bank or the wallet kept. Leave it empty if nothing was deducted. The contribution stays whole — the charge is booked as a business expense.',

    'they_owe_us' => 'they owe us',
    'we_owe_them' => 'we owe them',

    'bank_reference_placeholder' => 'Cheque no. or TrxID',
    'reference_hint_bank' => 'The cheque number, or the reference the bank gave the transfer.',
    'reference_hint_mfs' => 'The TrxID from the confirmation message.',
    'holds_cash' => 'Real cash sits in this account — it shows in the cash book, and a counter has to hold it.',
    'holds_bank' => 'This is a bank account. Money moving through it needs a cheque or transaction number, and it gets reconciled against the bank statement.',
    'holds_mfs' => 'This is a mobile money account (bKash, Nagad, Rocket, Upay). Money moving through it needs its TrxID, and cash-out charges belong in their own head.',
    'routing_no_hint' => 'Nine digits, printed on your cheque book. Needed for EFT and RTGS.',
    'mfs_provider_hint' => 'bKash, Nagad, Rocket, Upay.',
    'opening_note' => 'What the balance was before this system. Can only be set now — to change it later, post a journal voucher.',
    'opening_balance' => 'Opening balance',

    'year_closing' => 'Year-end closing',
    'year_opening' => 'Opening balance for the year',

    'year_end_note' => 'Closing the year zeroes the income and expense accounts and moves the result to retained earnings. It cannot be undone.',
    'no_current_year' => 'No financial year is current.',
    'goes_to_retained' => 'moves to retained earnings',
    'income_expense_zeroed' => 'income and expense accounts',
    'carry_forward_note' => 'No separate entry carries assets, liabilities and equity forward — the ledger here is continuous, so those balances stay as they were. Posting one would double every figure.',
    'confirm_hint' => 'Guards against a mis-click — the name must match exactly.',
    'year_closed' => ':closed is closed, :opened is open.',

    'no_entries' => 'No transactions on this account yet.',
    'system_account' => 'System account',
    'too_many_to_tree' => 'The chart has :count accounts — more than the :limit that render as a tree at once. Search by code or name above.',
    'no_tills' => 'No cash counters yet.',
    'till_total' => ':amount in hand across the company',
    'till_created' => 'Cash counter added.',
    'till_updated' => 'Counter updated.',
    'till_closed' => 'Counter closed.',
    'till_is_primary' => '":name" is now the main counter.',
    'till_code_hint' => 'For example CASH, CASH01, RIDER-A. The chart account takes this code.',
    'holder_note' => 'Whose hands the money sits in. Leave blank and the counter belongs to the company, not a person.',
    'limit_hint' => '0 means no limit. Going over is flagged, never blocked.',
    'primary_hint' => 'End-of-day deposits land here. Only one counter can be the main one.',
    'till_opening_note' => 'What is in this counter right now. Can only be set when creating it.',
    'over_limit_deposit' => 'Over the limit — deposit it.',
    'close_till_confirm' => 'This counter will be closed. Past transactions stay. Continue?',
    'no_vouchers' => 'No vouchers of this kind.',
    'voucher_count' => '{0} No vouchers|{1} 1 voucher|[2,*] :count vouchers',
    'number_on_save' => 'Numbered when saved',
    'contra_subtitle' => 'From one of our own accounts into another',
    'journal_subtitle' => 'No money moves - the books do',
    'journal_balanced' => 'Debit and credit agree - this can be posted.',
    'why_contra_this_way' => 'A contra neither raises nor lowers the business money - it only changes where it sits: cash into the bank, cash out of the bank, one bank to another, or one cashier to the next.',
    'contra_lands_today' => 'Both accounts move today - one down, the other up.',
    'contra_on_the_way' => 'The money left today but lands on the arrival date - in between, it is in transit.',
    'awaiting_approval' => '{1} 1 awaiting approval|[2,*] :count awaiting approval',
    'awaiting_not_in_books' => 'These are not in the books yet, so no total includes them.',
    'awaiting_only' => 'Showing only what is awaiting approval.',
    'expense_subtitle' => 'Money going out · into an expense head',
    'why_expense_this_way' => 'Say which head the expense belongs to first, then who was paid and against which bill. Tag a purchase bill and the money lands in that stock cost; tag none and it goes straight to the expense head. Last, say which account the money came out of.',
    'instrument_opens_own_fields' => 'Same as a receipt — each method opens its own fields.',
    'voucher_saved' => ':no saved.',
    'voucher_posted' => ':no posted to the ledger.',

    // Says what has NOT happened first: the expense is not in the books
    // yet, and somebody who does not know that writes it a second time.
    'voucher_approval_pending' => ':no is held as a draft awaiting approval — it is not in the books yet. Once approved, press Post again and it will go in.',

    // The reason travels with the refusal, so the next step is obvious:
    // change the paper, then ask again.
    'voucher_approval_rejected' => ':no was not approved — :reason. Correct the voucher and post again to raise a fresh request.',
    'voucher_cancelled' => ':no cancelled — a reversing entry was posted.',
    'cancel_reason_prompt' => 'Why is this being cancelled?',
    'this_is_cancelled' => 'This voucher has been cancelled.',
    'difference' => 'Difference',
    'must_balance' => 'Debit and credit must match before posting.',
    'negative_cash' => 'Cash in hand cannot be negative — an entry is missing or went to the wrong counter.',
    'count_adjustment' => 'Cash count :no — difference on :till',
    'no_transfers' => 'No transfers yet.',
    'awaiting_you' => 'Waiting for you to receive',
    'transfer_started' => ':no — handed over. The money moves when the receiver confirms.',
    'transfer_received' => ':no — received.',
    'transfer_cancelled' => ':no — transfer cancelled.',
    'transfer_is_cancelled' => 'This transfer has been cancelled.',
    'transfer_note' => 'The money stays with the giver until the receiver confirms.',
    'still_with_giver' => 'Not received yet, so this is still counted in ":name".',
    'received_at' => 'Confirmed by :name — :at',
    'no_counts' => 'No counts yet.',
    'count_note' => 'Count what is in hand, note by note. The book figure appears after you save.',
    'zero_count_confirm' => 'Is the drawer really empty? Saving a zero count will post the whole book balance as a shortage.',
    'count_recorded' => ':no — count saved.',
    'count_approved' => ':no — count approved.',
    'count_matches' => 'Matches',
    'shortage_note' => 'Short in hand. Approving posts the difference as an expense.',
    'surplus_note' => 'Over in hand. Approving posts the difference as other income.',
    'no_notes_recorded' => 'No note breakdown was recorded.',
    'row_count' => '{0} No rows|{1} 1 row|[2,*] :count rows',
    'nothing_in_range' => 'No transactions in this range.',
    'page_of' => 'Page :page of :pages — totals cover everything',
    'settings_saved' => 'Settings saved.',
    'settings_note' => 'For this company — other companies keep their own.',
    'needs_attention' => 'Needs attention',
    'draft_vouchers' => '{1} 1 draft voucher is not posted|[2,*] :count draft vouchers are not posted',
    'pending_transfers' => '{1} 1 transfer is waiting to be received|[2,*] :count transfers are waiting to be received',
    'count' => '{0} No accounts|{1} 1 account|[2,*] :count accounts',

    // Loans
    'loan_created' => 'The loan is on the books. A term loan also has its instalment schedule.',
    'loan_drawn' => 'Drawn — the liability rose by the same amount.',
    'loan_repaid' => 'Paid in — the balance came down by the same amount.',
    'instalment_paid' => 'Instalment paid — principal came off the liability, interest went to expense.',
    'interest_charged' => 'Interest charged — no money moved, the balance went up.',
    'no_loans' => 'No loans yet.',
    'loan_note' => 'A term loan has a fixed instalment schedule; a CC has a limit you draw against and repay as you like. The two are accounted for differently.',
    'loan_total' => 'Total outstanding',
    'loan_outstanding' => 'Outstanding',
    'loan_available' => 'Left on the limit',
    'loan_settled' => 'Settled',
    'schedule_note' => 'Check this against the bank\'s paper. If it disagrees the loan has to be entered again — the wrong interest method (reducing or flat) changes the split in every instalment.',
    'cc_interest_note' => 'Take the figure from the bank statement rather than working it out here. The bank counts days its own way and the two will never agree to the paisa.',
    'books_all_clear' => ':count checks ran, all of them clear',
    'books_broken' => ':count checks found something',
    'no_checks_for_you' => 'None of the checks are yours to run',
    'check_passed' => 'Clear',
    'check_failed' => '{1} 1 row does not match|[2,*] :count rows do not match',
    'what_does_not_match' => 'What does not match',

    // মাস বন্ধ ও খোলা
    'period_note' => 'Once the month’s reports have gone out, close it — then its figures can no longer change.',
    'period_closed' => ':month has been closed.',
    'period_reopened' => ':month has been reopened.',

    // চেকের খাতা
    'cheque_note' => 'A cheque in hand is not money yet — nothing reaches the bank until it clears.',
    'cheque_saved' => 'Cheque :no is on the books.',
    'cheque_deposited' => 'Marked as deposited.',
    'cheque_cleared' => 'Cleared — the money is in the bank now.',
    'cheque_bounced' => 'The bounce has been recorded.',
    'no_cheques' => 'No cheques yet.',
    'opening_narration' => 'Opening balance — :account',
    'balance_sheet_as_of' => 'As of :date — where the business stands',
    'sheet_agrees' => 'It balances — assets = liabilities + equity',
    'sheet_does_not_agree' => 'It does not balance — the gap is :gap',
    'branch_sheet_may_not_agree' => 'A single branch rarely balances: capital, loans and bank accounts belong to the company, not to one branch',
    'instrument_no_when_bank' => 'Needed when a bank or MFS account is involved — without it there is no way to reconcile later.',

    /* The cash-count activity line — AccountsActivity asked for both of
       these, and neither existed in either language, so the raw key
       showed on screen (found 3 September 2026) */
    'count_matched' => 'The cash count matched',
    'count_off_by' => 'The cash count was off by :amount',
    'net_profit' => 'Net profit',
    'net_loss' => 'Net loss',
    'bill_tag_hint' => 'If one truck brought several bills, tick them all. Tick none and this is an indirect cost; tick one and it is direct - the money rides on that stock.',
    'no_bill_to_tag' => 'There is no bill to tag right now - either nothing has been purchased yet, or every bill already carries its costs.',
    'direct_effect' => 'Direct: the money rides on the stock of the bills you ticked, so the profit on those goods reads true.',
    'indirect_effect' => 'Indirect: the money lands straight in the expense head, this month. It rides on no product.',
    'reverse_on_hint' => 'If this entry is provisional, the day it undoes itself - optional.',
    'attachment_hint' => 'A photo or scan of the bill - six months later this is what you need.',
    'over_allocated' => 'You have split more than the amount received - the two must match.',
    'no_open_bill' => 'This party has no open bill - the money stays on their account and comes off the next bill.',
    'received_on_account_hint' => 'Capital, a loan, income, a deposit — what the money came in for. "Deposited to" below is where it was put (cash or which bank). Choose capital and it also appears on the Capital & investment page by itself.',

    // What each list's search box looks in — the toolbar shows it as the placeholder.
    'cheque_search' => 'Cheque no, bank, document no or note',
    'asset_search' => 'Asset name, document no or tag no',
    'loan_search' => 'Document no, lender or account no',
    'recon_search' => 'Bank account name, code or number, or note',
];
