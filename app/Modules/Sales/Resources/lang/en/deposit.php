<?php

declare(strict_types=1);

/*
 * ⭐ Deposit report, step C — bank statement match, approval, money receipt, daily deposit register (18 Nov 2026).
 */
return [
    'title' => 'Deposit match — bank statement',
    'subtitle' => 'The bank has the final word: a reported deposit reaches the books only when the same money is found on the bank statement. A match is suggested on the same amount, the same bank account and within :days days of the reported date.',

    'stage' => [
        'reported' => 'Reported, not yet in the bank',
        'matched' => 'Matched in the bank',
        'awaiting' => 'Awaiting approval',
        'confirmed' => 'In the books',
        'rejected' => 'Returned',
    ],

    'confirmed_today' => 'In the books today',
    'upload_title' => 'Load a bank statement',
    'upload_hint' => 'The bank CSV or XLSX — date, narration, reference, withdrawal and deposit columns are found on their own. Loading the same file again stores no line twice.',
    'upload' => 'Load',
    'hand_title' => 'One deposit line by hand (seen in the bank app)',
    'hand_add' => 'Add line',
    'bank_line' => 'Bank line',
    'bank_date' => 'Bank date',
    'came_in' => 'Came into the bank',
    'description' => 'Bank narration',
    'match' => 'Match',
    'unmatch' => 'Unmatch',
    'approve' => 'Approve',
    'go_match' => 'Match in the bank',
    'suggested_title' => 'Suggested matches — one click to match',
    'suggested_empty' => 'No suggestions now — they come when a new statement is loaded.',
    'matched_title' => 'Matched in the bank — approve',
    'matched_empty' => 'No matched deposit is waiting for approval.',
    'waiting_title' => 'Reported, not yet found in the bank',
    'waiting_empty' => 'Every reported deposit is matched in the bank.',
    'lines_title' => 'Money came into the bank, no report matches it',
    'lines_empty' => 'No unmatched deposit line.',
    'no_free_line' => 'No bank line to match — load a statement.',
    'awaiting_hint' => 'Receipt :no is awaiting approval — it reaches the books on its own once approved (or once another person posts it in Accounts).',

    'loaded' => 'Statement loaded — new lines :added, already there :known, unreadable :bad.',
    'bad_line' => 'Line :line — could not read the date ":value"',
    'future_line' => 'Line :line — the date ":value" is in the future, which a bank statement never is; not stored',
    'line_added' => 'Bank line added.',
    'line_known' => 'This line is already there — not stored twice.',
    'matched' => 'Deposit :no matched to the bank line.',
    'unmatched' => 'Match removed — the deposit is "not yet in the bank" again.',
    'sent_for_approval' => 'Sent to the approver — it reaches the books on its own once approved.',

    'claim_already_matched' => 'This deposit is already matched to a bank line — unmatch it first.',
    'line_not_a_deposit' => 'This bank line is not a deposit (no money came in).',
    'line_in_the_books' => 'This bank line is already matched to a booked entry in the reconciliation — the money is already in the books.',
    'line_already_used' => 'This bank line is already matched to another deposit — one line, one deposit.',
    'other_bank_account' => 'The deposit and the bank line are on different bank accounts.',
    'not_matched' => 'This deposit is not matched to any bank line.',
    'not_a_bank_account' => 'This is not a bank or MFS account.',
    'account_needed' => 'Choose the account the money came into.',
    'not_in_the_bank_yet' => 'This deposit is not yet matched on the bank statement — the bank has the final word. Match it on the deposit match page first.',
    'draft_gone' => 'The receipt :no of this deposit is no longer a draft — it cannot be booked.',

    'receipt_title' => 'Money receipt',
    'receipt_no' => 'Receipt no',
    'claim_no' => 'Report no',
    'receipt_line' => 'Deposited in the bank (reported on :date)',

    'register_title' => 'Daily deposit register',
    'given_by' => 'Given by',
    'reported_amount' => 'Reported',
    'confirmed_amount' => 'In the books',
    'waiting_amount' => 'Waiting (not final)',
    'register_summary' => 'In the books :final — waiting',
    'register_pending' => 'Waiting (not final, not in the total): :amount',
];
