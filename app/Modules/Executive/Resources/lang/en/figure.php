<?php

declare(strict_types=1);

return [
    'sales' => 'Net sales',
    'sales_parts' => 'Billed :billed − returned :returned',
    'collections' => 'Collections',
    'receivable' => 'Receivables',
    'payable' => 'Supplier payables',
    'fund' => 'Cash + bank',
    'stock' => 'Stock value',
    'profit' => 'Profit',
    'signatures' => 'Waiting for approval',
    'expenses' => 'Expenses',
    'cash_flow' => 'Net cash flow',

    'sales_hint' => 'Net sales: posted bills less posted sales returns, excl. VAT (returns by their own date) — the same figure as the sales dashboard',
    'collections_hint' => 'Posted collections and customer receipt vouchers — the same figure as the sales dashboard',
    'receivable_hint' => 'What customers owe in the books, as of today — the same figure as the accounts dashboard',
    'payable_hint' => 'What we owe suppliers in the books, as of today — the same figure as the accounts dashboard',
    'fund_hint' => 'Cash in hand, bank and mobile money — the finance dashboard\'s "total fund"',
    'stock_hint' => 'Remaining goods at their purchase cost — the same figure as the inventory dashboard',
    'profit_hint' => 'Income less expenses for the period, from the books — the accounts dashboard\'s "net profit"',
    'signatures_hint' => 'Papers waiting for someone\'s approval — the same figure as the approvals dashboard',
];
