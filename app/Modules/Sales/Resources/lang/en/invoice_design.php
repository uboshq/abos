<?php

declare(strict_types=1);

/*
 * Labels only the new invoice designs print ([[InvoicePaperView]]). The shared ones stay in
 * `sales::print.classic`, so every design names a field the same way.
 */
return [
    'due_date' => 'Due Date',
    'sales_officer' => 'Sales Officer',
    'details' => 'Details',
    'cut_here' => 'Cut here — the depot keeps this part as proof of delivery',
    'statement' => 'Account Statement',
    'account_summary' => 'Account Summary',
    'goods' => 'Goods on this invoice',
    'movement' => 'Account movement',
    'particulars' => 'Particulars',
    'debit' => 'Debit',
    'credit' => 'Credit',
    'balance' => 'Balance',
    'thanks' => 'Thank you for your business',
];
