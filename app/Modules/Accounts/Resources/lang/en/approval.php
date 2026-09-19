<?php

declare(strict_types=1);

/*
 * What this module's approvable actions are called in the flow builder.
 *
 * Only the module knows the name — ApprovalFlowService::labels() turns
 * "accounts · expense" into human words from here.
 */

return [
    'cash_count' => 'Accepting a cash-count difference',
    'transfer' => 'Money transfer',
    'year_end' => 'Year-end closing',
    'expense' => 'Expense voucher',
    'counter_deposit' => 'Counter deposit',
    'receipt' => 'Receipt voucher',
    'payment' => 'Payment voucher',
    'journal' => 'Journal voucher',
    'contra' => 'Contra voucher',
];
