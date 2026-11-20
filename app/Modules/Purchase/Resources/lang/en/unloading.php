<?php

declare(strict_types=1);

/*
 * ⭐ Unloading cost on the goods receipt screens — owner, 18 Nov 2026 ("ok").
 * ⓘ The shared words (modes, amount, till, contractor …) live in supplier::labour; only the receipt screens' own lines are here.
 */
return [
    'expected_after_save' => 'Save the receipt once to see the expected amount (cartons × rate per carton). A blank amount takes that expected amount.',
    'equal_share' => 'equal share',
    'line_amount' => 'Goods value',
    'total' => 'Total',
];
