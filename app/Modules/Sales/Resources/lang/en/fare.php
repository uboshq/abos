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
    'paid_waits_signature' => 'Fare voucher :no is waiting for an approval.',
    'paid_waits_checker' => 'Fare voucher :no is written; someone else posts it.',
    'paid_narration' => 'Vehicle fare paid — challan :challan, carrier :by',
    'only_confirmed' => 'A fare can be recorded here only on a confirmed challan.',
    'already_recorded' => 'This challan already has a fare recorded.',
    'who_pays' => 'Pick who pays the fare.',
    'needs_amount' => 'Enter the fare amount.',
    'nothing_due' => 'There is no unpaid fare on this challan.',

    // trip — one truck, one fare (decision d)
    'trip_amount' => 'Truck fare',
    'trip_hint' => 'The fare is for the whole truck and is booked when the truck leaves. A trip with a fare cannot carry a challan that has its own fare.',
    'trip_narration' => 'Truck fare — trip :trip (challans :challans), vehicle :vehicle, carrier or driver :by',
    'trip_paid_narration' => 'Truck fare paid — trip :trip, carrier :by',
    'state_trip_waits' => 'Booked when the truck leaves',
    'challan_on_trip' => 'This challan is on trip :trip, which has its fare recorded. The same truck\'s fare cannot be recorded twice.',
    'trip_challan_has_fare' => 'These challans have their own fare: :documents. With a fare on the trip the same truck would be paid twice. Remove them, or leave the trip fare empty.',

    // The fare is required (owner, 7 Nov 2026, sample "ok")
    'needs_amount_vehicle' => 'Goods going in our own or a hired vehicle need the fare amount. Without it the bill cannot be printed or confirmed.',
    'amount_before_bill' => 'Challan :challan has no vehicle fare yet. Enter the fare first, then the bill can be printed and confirmed.',
    'write_fare' => 'Enter fare',
    'box_title' => 'Vehicle fare',
    'box_done' => 'Fare entered. The bill can be printed and confirmed.',
    'no_fare_customer_vehicle' => 'No fare. The customer\'s own vehicle',
    'no_fare_no_vehicle' => 'No fare. The customer takes the goods',
    'no_fare_customer_pays' => 'The customer pays the fare. Nothing in the books',
    'no_fare_nothing' => 'No fare',
    'carrier_phone_new' => 'Carrier mobile (type it if not in the list)',
    'for_paper' => 'For',
    'trip_label' => 'Trip',
];
