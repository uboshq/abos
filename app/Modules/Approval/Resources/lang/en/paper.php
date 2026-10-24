<?php

/*
 * Names for actions no module declares in its `approvals`, yet that reach the inbox (point 4, owner 21 Oct 2026):
 * voucher types and actions left on older rows. A value may be another lang key. Never show the raw code.
 */

return [
    'accounts' => [
        'receipt' => 'accounts::voucher.receipt',
        'payment' => 'accounts::voucher.payment',
        'expense' => 'accounts::voucher.expense',
        'journal' => 'accounts::voucher.journal',
        'contra' => 'accounts::voucher.contra',
    ],
    'sales' => [
        'invoice' => 'Sales bill',
    ],
    'unknown' => 'A :module paper',
    'some_module' => 'another module',
];
