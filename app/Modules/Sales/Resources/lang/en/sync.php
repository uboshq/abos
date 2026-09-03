<?php

declare(strict_types=1);

/**
 * Sales sync messages. The Bangla file is the one that reaches a phone —
 * the app shows Bangla server sentences and swallows English framework
 * noise on purpose. This exists so both languages say the same thing, and
 * so a log read in English carries the same meaning.
 */
return [
    'order_edit_needs_network' => 'An order cannot be corrected offline — somebody at the office may have changed it in the meantime. Come back into coverage and correct it there.',
    'unknown_customer' => 'That shop was not found on the server. Sync the list and try again.',
    'unknown_product' => 'One of the products on the order was not found on the server. Sync the list and try again.',
    'order_has_no_lines' => 'The order has no products on it.',
    'collection_edit_needs_network' => 'A collection cannot be corrected offline — the office may have applied it to a bill in the meantime. Come back into coverage and correct it there.',
    'collection_needs_amount' => 'A collection needs an amount above zero.',
    'unknown_invoice' => 'One of the bills on this collection was not found on the server. Sync the list and try again.',
];
