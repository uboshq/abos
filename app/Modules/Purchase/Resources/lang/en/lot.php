<?php

declare(strict_types=1);

/*
 * The lot on a direct purchase line — number, expiry, printed price (MRP).
 *
 * Field labels come from inventory::field so the warehouse screens and the
 * counter use one name each; only this screen's own sentences live here.
 */
return [
    'needs_lot' => 'Line :line — :product is tracked by lot, so a lot number is required. It cannot be found out later, only now while the goods come in.',

    'needs_lot_short' => 'Enter the lot number — this product is tracked by lot.',

    'from_last_lot' => 'Filled in from the last lot received — change it if this one differs.',
];
