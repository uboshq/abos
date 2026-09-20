<?php

declare(strict_types=1);

/*
 * Cash forecast and CFO dashboard — finance map §1, §8; 20 September 2026.
 * Its own file: message.php is edited by several people at once.
 */
return [
    'title' => 'Cash forecast',
    'note' => 'What will be in hand over the next 30, 60 and 90 days — from open bills and loan instalments.',
    'opening' => 'In hand today (cash + bank + MFS)',
    'when' => 'When',
    'bucket_now' => 'Due now (including overdue)',
    'bucket_d30' => 'Within 30 days',
    'bucket_d60' => '31–60 days',
    'bucket_d90' => '61–90 days',
    'receivables' => 'From customers',
    'loans_in' => 'Instalments on loans given',
    'payables' => 'To suppliers',
    'loans_out' => 'Instalments on loans taken',
    'net' => 'Net',
    'closing' => 'In hand after',
    'caveat' => 'Only money the books give a due date for is counted: open bills and loan instalments. Regular costs such as salaries and rent are not here. Overdue receivables sit in the first row — assuming all of it arrives is optimistic.',

    'cfo' => 'CFO dashboard',
    'cfo_note' => 'The health of the money on one page — where it is, who owes what, what is owed.',
    'cash' => 'Cash in hand',
    'bank' => 'In the bank',
    'mfs' => 'In mobile banking',
    'receivable' => 'Receivable (customers)',
    'payable' => 'Payable (suppliers)',
    'loans' => 'Loans outstanding',
    'current_ratio' => 'Current ratio',
    'current_ratio_hint' => 'Current assets :assets ÷ current liabilities :liabilities',
    'cash_ratio' => 'Cash ratio',
    'no_liabilities' => 'No liabilities',
    'liquidity' => 'Liquidity',
    'liquidity_good' => 'Good',
    'liquidity_warn' => 'Careful',
    'liquidity_bad' => 'At risk',
    'liquidity_rule' => 'Current ratio above 2 is good, 1–2 careful, below 1 at risk',
    'in30' => 'In hand in 30 days',
];
