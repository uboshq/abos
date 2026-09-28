<?php

declare(strict_types=1);

/*
 * Gate pass - born at the moment of dispatch ([[GatePassService]]), the owner's Delivery Processing design.
 */
return [
    'title' => 'Gate Pass',
    'subtitle' => 'Made at dispatch - one for every dispatch',
    'empty' => 'No gate passes.',
    'search' => 'Gate pass, challan, customer or vehicle…',
    'show_cancelled' => 'Show cancelled too',

    'column' => [
        'number' => 'Gate pass',
        'challan' => 'Challan',
        'customer' => 'Customer',
        'vehicle' => 'Vehicle',
        'driver' => 'Driver',
        'issued_at' => 'When',
        'issued_by' => 'Issued by',
        'status' => 'Status',
        'trip' => 'Trip',
    ],

    'status' => [
        'issued' => 'Issued',
        'cancelled' => 'Cancelled',
    ],

    'print' => 'Print',
    'cancel' => 'Cancel',
    'cancel_reason' => 'Reason for cancelling',
    'cancelled' => 'Gate pass :no cancelled.',
    'cancelled_note' => 'Cancelled - :reason (:by, :at)',
    'reason_required' => 'Write why it is cancelled.',
    'already_cancelled' => 'Gate pass :no is already cancelled.',
    'view_only' => 'A gate pass is made at dispatch; it cannot be changed, only cancelled with a reason.',
    'on_challan' => 'Gate passes for this challan',
];
