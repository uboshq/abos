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

    // transport screen — record and pay later
    'section' => 'Vehicle fare',
    'amount' => 'Fare',
    'carrier' => 'Carrier',
    'state' => 'State',
    'state_now' => 'Paid (expense voucher)',
    'state_due' => 'Fare due, owed to the carrier',
    'state_due_paid' => 'Paid later',
    'state_old' => 'Recorded the old way',
    'voucher' => 'Voucher',
    'record' => 'Record the fare',
    'pay_now' => 'Pay the fare',
    'none_yet' => 'No fare is recorded on this challan.',
    'recorded' => 'The fare is recorded.',
    'paid' => 'The fare is paid: :no.',
    'paid_waits_signature' => 'Fare voucher :no is waiting for a signature.',
    'paid_waits_checker' => 'Fare voucher :no is written; someone else posts it.',
    'paid_narration' => 'Vehicle fare paid — challan :challan, carrier :by',
    'only_confirmed' => 'A fare can be recorded here only on a confirmed challan.',
    'already_recorded' => 'This challan already has a fare recorded.',
    'who_pays' => 'Pick who pays the fare.',
    'needs_amount' => 'Enter the fare amount.',
    'nothing_due' => 'There is no unpaid fare on this challan.',
];
