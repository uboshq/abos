<?php

declare(strict_types=1);

/*
 * পিওএস-এর নিজের কথা — কাউন্টার আর শিফটের যে কী কেবল এই মডিউল পড়ে, বিক্রয়ের একই নামের ফাইল থেকে
 * হুবহু সরানো (পিওএস ধাপ ২, ১১ অক্টোবর ২০২৬)। ⓘ দুই মডিউলের ভাগের কী (গ্রাহক, মোট, ছাড়…) বিক্রয়েই থাকে।
 */
return [
    'pos_search' => 'Scan a barcode or type a name…',
    'pos_hint' => 'A scan goes straight into the basket. Enter adds the first product.',
    'pos_empty_cart' => 'Basket is empty — tap a product or scan a barcode.',
    'pos_done' => ':no — sale complete. Change :change.',
    'pos_narration' => ':no — cash at the counter',
    // Bills held at the counter
    'pos_parked' => ':no is waiting at the counter. Nothing has been posted.',
    'pos_resumed' => ':no is back on the counter.',
    'pos_park' => 'Hold',
    'pos_parked_bills' => 'Waiting at the counter',
    // Counter shift
    'shift_opened' => ':till is open. The closing count goes in here at the end of the day.',
    'shift_closed' => 'Shift closed — the figures are below.',
    'shift_note' => 'Someone answers for the drawer. Enter what you counted on opening, count again and close at the end.',
    'shift_none_open' => 'You have no shift open.',
    'shift_no_till' => 'Someone is at every drawer right now.',
    'shift_opening' => 'Counted at open',
    'shift_cash_in' => 'Came in',
    'shift_cash_out' => 'Went out',
    'shift_expected' => 'Should be in the drawer',
    'shift_counted' => 'Counted',
    'shift_difference' => 'Difference',
    'shift_short' => 'Short — and now there is someone to ask.',
    'shift_over' => 'Over — a collection somewhere was not written down.',
    'shift_matched' => 'It matches.',
    'shift_bills' => 'Bills this shift',
    'shift_today' => 'Closed today',
    /*
     * The one-line keyboard map on the counter — not `pos_keys`, which is the
     * heading of the help panel.
     *
     * Both were called `pos_keys`, and PHP keeps the last, so this line read
     * "Keyboard shortcuts" instead: the keys themselves were written nowhere.
     * That was the line's whole job — "don't touch the mouse" only holds when
     * the keys are in front of you.
     */
    'pos_keys_map' => 'F2 payment · F4 hold · F8 search · Esc clear · Enter in payment completes the sale',
    'pos_not_found' => 'No product for this code:',
    'pos_return_narration' => 'Counter return against :no',
    'pos_refund_narration' => 'Cash refunded against :no',
    'pos_returned' => ':no — :amount taken back.',
    'pos_keys' => 'Keyboard shortcuts',
    'key_paid' => 'Jump to the amount',
    'key_return' => 'Take back against a bill',
    'key_hold' => 'Hold the cart',
    'key_split' => 'Split the payment',
    'key_customer' => 'Pick the customer',
    'key_search' => 'Find a product',
    'key_exact' => 'Exact amount',
    'pos_return' => 'Take back against a bill',
    'pos_refund_cash' => 'Refund the cash from the drawer',
    'pos_bill_not_found' => 'No confirmed bill with that number was found.',
    'pos_needs_approval' => 'This discount needs the manager. If they are beside you, let them type their own email and password — the sale then goes through at once.',
];
