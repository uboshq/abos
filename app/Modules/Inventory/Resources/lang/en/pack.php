<?php

declare(strict_types=1);

/*
 * Product packs — step 4b, 20 September 2026.
 * Its own file: inventory::field / message are edited by several people at once.
 */
return [
    'title' => 'Packs',
    'note' => 'How much this product holds in a carton, box or dozen — write it as "1 carton = 12 boxes" and the total in pieces shows beside it.',
    'needs_unit' => 'Pick a unit above and save first — a pack needs to know what "1 carton = 24" is 24 of.',
    'unit' => 'Unit',
    'per_qty' => 'How many',
    'per_unit' => 'Of what',
    'in_base' => 'Comes to',
    'barcode' => 'Pack barcode',
    'is_base' => "(the product's own unit)",
    'default_purchase' => 'Buying',
    'default_sales' => 'Selling',
    'default_pos' => 'POS',
    'default_counter' => 'Counter',
    'add' => 'Another pack',
    'remove' => 'Remove row',
];
