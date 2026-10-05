<?php

declare(strict_types=1);

return [
    'title' => 'Money & custody',
    'subtitle' => 'Where the money is, and who has it',

    'kind' => 'Kind',
    'kind_till' => 'Cash counter',
    'kind_office_cash' => 'Office cash',
    'kind_bank' => 'Bank / MFS',
    'kind_transit' => 'In transit',

    'holder' => 'Custodian',
    'balance' => 'Balance',
    'sent' => 'Sent, unaccepted',
    'primary' => '(main)',

    // Nobody's name against it — a shortage would be nobody's fault
    'nobody' => 'nobody is responsible',
    'nobody_holds_it' => "in nobody's hands",

    'on_the_road' => 'Cash in transit',
    'on_the_road_detail' => 'Who is carrying what',

    'waiting_for_you' => 'One handover is waiting for you to accept|:count handovers are waiting for you to accept',

    'empty' => 'There are no cash counters or bank accounts yet. Open a cash counter first.',

    // ⭐ Handover of a cash box — audit m8, 4 Oct 2026
    'handover_title' => 'Hand over the box',
    'handover_to' => 'New holder',
    'handover_counted' => 'Cash counted',
    'handover_book' => 'Book balance :amount',
    'handover_counted_was' => 'counted :amount',
    'handover_action' => 'Hand over',
    'handover_cancel' => 'Cancel',
    'handover_awaiting' => 'Handover :no is waiting for its signature - the box is still with the previous holder.',
    'handover_done' => 'Handover :no - the box is now held by :name.',
    'handover_cancelled' => 'Handover :no cancelled - the box stays with the previous holder.',
    'handover_count' => 'Counted at a handover - new holder :to',
    'handover_state' => [
        'draft' => 'In progress',
        'awaiting' => 'Awaiting signature',
        'confirmed' => 'Handed over',
        'cancelled' => 'Cancelled',
    ],
];
