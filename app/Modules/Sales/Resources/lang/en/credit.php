<?php

declare(strict_types=1);

/*
 * Credit and collections — the new-credit wall and its reports (5 Oct 2026; [[CreditExposure::stopsFor()]]).
 */
return [
    'overdue_stop' => 'Unpaid for more than :days days past due (:bills, :amount) — no new credit',
    'uncleared_cheques' => 'Cheques not yet cleared',
    'blocked_stop' => 'Credit is blocked for this customer (:reason) — a sale paid in full still goes through',
];
