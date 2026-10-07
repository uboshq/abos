<?php

declare(strict_types=1);

// Vehicle fare — from which account, paid by whom (owner, 7 Oct 2026; FarePayment)
return [
    'needs_account' => 'Pick the account the fare was paid from: a cash till, a bank or MFS.',
    'not_your_till' => 'This cash till is not yours. Pay the fare from your own till, or pick a bank or MFS.',
    'needs_reference' => 'For a bank or MFS payment, enter the transaction number (TrxID).',
    'unknown_payer' => 'The person who paid is not part of this company.',
    'later_needs_carrier' => 'To pay the fare later, pick the carrier, so the debt has a name on it.',
    'narration' => 'Vehicle fare — challan :challan, vehicle :vehicle, carrier or driver :by',

    // screen
    'when' => 'Fare paid',
    'now' => 'Paid now',
    'later' => 'Pay later',
    'account' => 'From which account',
    'pick_account' => 'Pick an account',
    'reference' => 'Transaction number (TrxID)',
    'payer' => 'Paid by',
    'payer_cash_is_you' => 'cash from your till',
    'later_hint' => 'The fare stays as a debt in the carrier\'s name; pay it later from the transport screen. A carrier is needed.',
];
