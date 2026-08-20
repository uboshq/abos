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
];
