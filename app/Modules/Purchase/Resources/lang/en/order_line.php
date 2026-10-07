<?php

declare(strict_types=1);

/*
 * One order line, two ways in — 27 Sep 2026.
 *
 * Goods on an order come in either on a goods receipt or on a bill raised
 * against the order. Each message says what to do next, not only what is
 * wrong. See OrderLineIntake.
 */
return [
    'over_ceiling' => 'The order was for :ordered. :received is already on goods receipts and :billed on bills against the order, so :qty more would bring the same goods in twice.',
    'bill_from_receipt' => 'The goods for this order line are on goods receipt :no. Bill them from that goods receipt, not against the order.',
];
