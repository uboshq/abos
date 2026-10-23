<?php

declare(strict_types=1);

return [
    'subtitle' => 'Legal duties — withheld tax and VAT to the treasury, and Mushak-6.6 to the supplier; with due dates',
    'tab' => [
        'open' => 'Open',
        'overdue' => 'Overdue',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ],
    'col' => [
        'kind' => 'What to do',
        'source' => 'From paper',
        'amount' => 'Amount',
        'due_on' => 'Due',
    ],
    'kind' => [
        'kosagar_deposit_ait' => 'Deposit withheld income tax (AIT) to the treasury',
        'kosagar_deposit_vds' => 'Deposit withheld VAT (VDS) to the treasury',
        'mushak_6_6_issue_vds' => 'Give the supplier a Mushak-6.6',
    ],
    'overdue' => 'overdue',
    'ref_no' => 'Challan or certificate no.',
    'mark_done' => 'Done',
    'done_line' => ':who · :when · no. :ref',
    'cancelled_line' => 'Cancelled — :reason',
    'marked_done' => 'The obligation from :no is recorded as done.',
    'empty' => [
        'open' => 'No open obligations.',
        'overdue' => 'Nothing is overdue.',
        'done' => 'Nothing is recorded as done yet.',
        'cancelled' => 'No cancelled obligations.',
    ],
    'notice_overdue' => '{1} :count obligation is overdue — a treasury deposit or Mushak-6.6 is pending|[2,*] :count obligations are overdue — treasury deposits or Mushak-6.6 are pending',
    'notice_soon' => '{1} :count obligation falls due within three days|[2,*] :count obligations fall due within three days',
    'search' => 'Paper no. or challan/certificate no.',
];
