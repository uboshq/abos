<?php

declare(strict_types=1);

/*
 * Credit block — stop new credit for one customer by hand (credit and collections, 5 Oct 2026; [[CreditBlockController]]).
 */
return [
    'title' => 'Credit blocked',
    'badge' => 'Credit blocked',
    'by_on' => ':user, :date',
    'reason' => 'Reason',
    'block' => 'Block credit',
    'clear' => 'Allow credit again',
    'clear_reason' => 'Reason for allowing',
    'blocked' => 'New credit is blocked for this customer — a sale paid in full still goes through.',
    'cleared' => 'Credit is allowed again for this customer.',
    'already' => 'Credit is already blocked for this customer.',
    'not_blocked' => 'Credit is not blocked for this customer.',
    'help' => 'While blocked, no new credit goes out at the counter, on a challan, bill, DO or order; a sale paid in full still goes through.',
];
