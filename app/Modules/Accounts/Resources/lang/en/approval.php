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
    'counter_payment' => 'Counter payment',
    'receipt' => 'Receipt voucher',
    'payment' => 'Payment voucher',
    'journal' => 'Journal voucher',
    'contra' => 'Contra voucher',
    // ⭐ গ১ — ৪ অক্টোবর ২০২৬
    'note' => 'Credit or debit note',
    'cheque_clear' => 'Cheque cleared',
    'cheque_bounce' => 'Cheque bounced',
    'inter_company' => 'Inter-company transfer',
    'till_opening' => 'Opening balance of a new cash till',
    'fixed_asset_register' => 'Fixed asset registered (with funding)',
    'fixed_asset_dispose' => 'Fixed asset sold or written off',
];
