<?php

declare(strict_types=1);

/*
 * What the handset is told - 25 September 2026.
 *
 * These lines are read on a phone, not at a desk, so they are short and
 * direct: the person reading is standing beside a lorry with no time to
 * work out what is meant.
 *
 * Each one says what to do next, not only what went wrong - "unknown
 * order" leaves the reader with nowhere to go.
 */
return [
    'unknown_order' => 'The server does not have this order. Sync once and try again.',
    'order_not_open' => 'Goods cannot be received against this order - it is either a draft or cancelled.',
    'unknown_product' => 'The server does not have this product. Sync once and try again.',
    'product_not_on_order' => 'This product is not on that order. Anything not ordered has to be received at the office.',
    'receipt_needs_qty' => 'How much arrived has to be a positive number.',
    'receipt_needs_lines' => 'There are no lines - write down what came off the lorry first.',
    'receipt_edit_needs_network' => 'A receipt already sent cannot be changed from the handset. Correct it on the office screen.',
    'from_the_handset' => 'From the warehouse handset - the figures have not been checked yet.',
];
