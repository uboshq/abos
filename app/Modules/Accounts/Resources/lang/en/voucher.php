<?php

declare(strict_types=1);

return [
    'receipt' => 'Receipt Voucher',
    'payment' => 'Payment Voucher',
    'origin_sales_deposit' => 'Sales added deposit',
    'origin_purchase_payment' => 'Purchase added payment',
    'expense' => 'Expense Voucher',
    'journal' => 'Journal Voucher',
    'contra' => 'Contra Voucher',
    'tab' => [
        'receipt' => 'Receipt Voucher',
        'sales_deposit' => 'Sales Added Deposit',
        'payment' => 'Payment Voucher',
        'expense' => 'Exp. Voucher',
        'journal' => 'Journal',
        'contra' => 'Contra',
        'others' => 'Others Voucher',
        'purchase' => 'Purchase',
        'sales' => 'Sales',
    ],
    'template_journal' => 'A journal takes no cash, bank or mobile account. When money moves use a contra, receipt or payment voucher.',
    'template_contra' => 'A contra takes only cash, bank or mobile accounts. With other accounts use a receipt, payment or journal.',
    'template_receipt' => 'A receipt brings money in: a cash/bank account must be debited and none credited.',
    'template_payment' => 'A payment takes money out: a cash/bank account must be credited and none debited.',
    'adjusting_journal' => 'Adjusting Journal',
    'adjusting_mark' => 'Month-end adjustment: accrual, prepaid release, depreciation or provision',
    'adjusting_badge' => 'Adjusting',
    'adjusting_filter' => 'Adjusting journals only',
    'adjusting_only' => 'Showing month-end adjusting journals only.',
    'template_expense' => 'An expense either takes money out (credit a cash/bank account) or creates a payable (credit a payable account, with a party), and debits no cash/bank account.',
    'template_control_needs_party' => ':account is held per party. Give the line a party (who owes us, whom we owe, or whose loan or advance it is).',
    'adjusting_reversal_narration' => 'Reversing entry for :no (:date)',
];
