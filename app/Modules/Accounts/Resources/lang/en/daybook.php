<?php

declare(strict_types=1);

/*
 * Day Book — the owner-approved sample (16 Nov 2026). [[DayBookService]], [[DayBookPaper]].
 */
return [
    'title' => 'Day Book',
    'caption' => 'DAY BOOK — JOURNAL ENTRIES',
    'subtitle' => 'Every entry of one day, by journal, with all the lines of each entry together',
    'date' => 'Date',
    'journals' => 'Journals',
    'journals_value' => 'All (:count)',
    'status' => 'Status',
    'status_value' => 'Posted only',
    'branch' => 'Branch',
    'entries' => 'Entries',
    'entries_value' => ':count',
    'printed_by' => 'Printed by',
    'show' => 'Show',
    'print' => 'Print',
    'pdf' => 'PDF',
    'col' => [
        'time' => 'Time',
        'number' => 'Entry no',
        'reference' => 'Reference',
        'party' => 'Party / narration',
        'code' => 'Code',
        'account' => 'Account',
        'debit' => 'Debit',
        'credit' => 'Credit',
        'who' => 'By',
    ],
    'journal' => [
        'sales' => 'Sales journal',
        'purchase' => 'Purchase journal',
        'cash' => 'Cash journal',
        'bank' => 'Bank journal',
        'journal' => 'General journal',
    ],
    'journal_short' => [
        'sales' => 'Sales journal',
        'purchase' => 'Purchase journal',
        'cash' => 'Cash journal',
        'bank' => 'Bank journal',
        'journal' => 'General journal',
    ],
    'journal_total' => ':journal — total (:count entries)',
    'day_total' => 'Day total — :count entries',
    'carry_out' => 'Carried forward →',
    'carry_in' => '← Brought forward',
    'reversal' => 'Reversal',
    'empty' => 'Nothing was written in the books on this day.',
    'not_equal' => 'Debit and credit do not agree — difference :gap',
];
