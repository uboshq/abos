<?php

declare(strict_types=1);

/*
 * The QR scan on a bill or challan — [[DeliveryScanController]].
 */
return [
    'who_title' => 'Sign in to open this paper',
    'who_note' => 'Depot staff choose the staff sign-in; buyers and dealers choose the dealer sign-in. After signing in you come straight back to this paper.',
    'staff_login' => 'Staff sign-in',
    'dealer_login' => 'Dealer sign-in',

    'staff_title' => 'Scan — :no',
    'next_hint' => 'Below is the current stage and the button for the next one. Press it to set the next stage.',

    'dealer_title' => 'Challan :no',
    'your_due' => 'Your total due',
    'open_ledger' => 'See the full account',
    'goods' => 'Goods on this challan',
    'bills' => 'Bills for this challan',
    'no_bill' => 'No bill has been made for this challan yet.',
    'bill_total' => 'Bill total',
    'bill_paid' => 'Paid',
    'bill_due' => 'Due',
    'stage_now' => 'Goods are now',
    'confirm_title' => 'Did you receive the goods?',
    'confirm_hint' => 'If every item arrived in order, press the button below. The challan becomes "delivered" with your name as the receiver.',
    'confirm' => 'I received the goods',
    'cannot_confirm' => 'This cannot be confirmed now — the goods have not left yet, or it is already confirmed. If something is wrong, call the depot.',
    'received_saved' => 'Thank you — receipt of the goods is confirmed.',
    'dealer_note' => 'Confirmed by the customer by scanning the QR (code :code)',
];
