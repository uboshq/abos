<?php

declare(strict_types=1);

/**
 * ব্যাংক মিলকরণের ভাষা।
 */
return [
    'title' => 'Bank reconciliation',
    'subtitle' => 'The statement and the ledger never agree — this is where the gap gets explained.',

    'open_title' => 'Start a reconciliation',
    'bank_account' => 'Bank account',
    'statement_date' => 'Statement date',
    'statement_balance' => 'Closing balance on the statement',
    'narration' => 'Note',
    'open_action' => 'Start',

    'empty' => 'No reconciliation yet.',
    'empty_lines' => 'Nothing went through this account up to that date.',

    'status' => 'Status',
    'draft' => 'In progress',
    'confirmed' => 'Agreed and closed',
    'confirmed_by' => 'Closed by',

    'date' => 'Date',
    'document' => 'Document',
    'narration_col' => 'Details',
    'paid_in' => 'Paid in',
    'paid_out' => 'Paid out',
    'seen_by_bank' => 'On the statement',

    'ledger' => 'Our books say',
    'statement' => 'The statement says',
    'deposits_pending' => 'Paid in, not yet on the statement',
    'cheques_pending' => 'Cheques written, not yet cashed',
    'expected' => 'Statement adjusted',
    'difference' => 'Still unexplained',
    'agrees' => 'The two agree.',
    'does_not_agree_hint' => 'Tick every line that appears on the statement. What is left over is the real difference.',

    'save_ticks' => 'Save ticks',
    'confirm' => 'Agree and close',
    'reopen' => 'Reopen',

    'opened' => 'Reconciliation started.',
    'marked' => 'Ticks saved.',
    'confirmed_message' => 'Reconciliation closed.',
    'reopened' => 'Reconciliation reopened.',

    'not_a_bank_account' => 'That account is not a bank account.',
    'does_not_agree' => 'Still :difference unexplained — a reconciliation cannot be closed until the difference is zero.',
    'already_confirmed' => 'This reconciliation is closed. Reopen it first.',
    'not_confirmed' => 'This reconciliation is not closed.',
    'needs_another_hand' => 'Whoever opened a reconciliation cannot close it. Someone else checks it and closes it.',
    'statement_file' => 'Bank statement (CSV)',
    'load_statement' => 'Load the statement',
    'statement_sample' => 'Sample file',
    'statement_loaded' => ':rows lines loaded, :matched matched on their own, :bad skipped',
    'only_at_the_bank' => 'The bank knows, our books do not',
    'only_at_the_bank_hint' => 'On the statement but not yet in our books — a charge, interest, or a bounced cheque. Enter the voucher and the line matches itself.',

    // ⭐ One bank line, several papers (owner, 21 Nov 2026)
    'match_papers' => 'Match papers',
    'match_selected' => 'Match',
    'unmatch' => 'Unmatch',
    'ticked_total' => 'Ticked',
    'line_amount' => 'Bank line',
    'no_candidates' => 'No final paper on this account within 7 days of the line.',
    'matched_with' => 'Matched with',
    'matched_in_books' => 'Bank lines matched to papers',
    'books_matched' => 'Bank line matched to :count paper(s).',
    'books_unmatched' => 'Match removed — every paper of the line is free again.',
    'pick_a_paper' => 'Tick at least one paper.',
    'line_already_matched' => 'This bank line is already matched — unmatch it first.',
    'line_not_matched' => 'This bank line is not matched.',
    'paper_not_found' => 'A ticked paper was not found.',
    'paper_not_final' => 'Paper :no is not final (posted) — only final papers count.',
    'paper_other_bank' => 'Paper :no is on another bank account.',
    'paper_other_way' => 'Paper :no moves money the other way.',
    'paper_already_matched' => 'A ticked paper is already matched to a bank line — each paper is matched once.',
    'total_must_equal' => 'The ticked papers total :total; the bank line is :line. They must be equal.',
    'line_in_a_confirmed_recon' => 'A paper of this line is ticked in a closed reconciliation — reopen it first.',
];
