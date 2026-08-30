<?php

declare(strict_types=1);

return [
    'posted' => 'Posted',
    'active' => 'Active',
    'closed' => 'Closed',
    'overdue' => 'Past maturity',
    'cancelled' => 'Cancelled',
    'settled' => 'Settled',

    /*
     * A pledged deposit -- the money is there, but not in hand.
     *
     * The document number rides along, because "pledged" invites
     * exactly one follow-up question and the row should answer it.
     */
    'pledged' => 'Pledged against :loan — cannot be broken',
    'pledged_short' => 'Pledged',
];
