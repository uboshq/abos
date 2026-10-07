<?php

declare(strict_types=1);

/*
 * The lot on a direct purchase line — number, expiry, printed price (MRP).
 *
 * Field labels come from inventory::field so the warehouse screens and the
 * counter use one name each; only this screen's own sentences live here.
 */
return [
    'auto' => 'Leave it empty and the lot number is filled in on save, on the paper date — for example :number. A lot you type stays as typed.',
];
