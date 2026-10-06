<?php

declare(strict_types=1);

/**
 * Delivery stage screens.
 */
return [
    'title' => 'Deliveries',
    'subtitle' => 'Where each challan\'s goods are now — on the shelf, on the van, or with the customer',
    'empty' => 'No challan is at this stage.',
    'saved' => 'Stage set — :stage.',
    'open_challan' => 'Open challan',
    'now' => 'Now',

    'tab' => [
        'open' => 'Work in hand',
    ],

    'summary' => [
        'awaiting' => 'Awaiting delivery',
        'delivered' => 'Delivered',
        'partial' => 'Partly delivered',
        'failed' => 'Not delivered',
        'cancelled' => 'Cancelled',
        'column' => 'Delivery',
    ],

    'stage' => [
        'pending' => 'Pending',
        'allocated' => 'Allocated',
        'picking' => 'Picking',
        'packed' => 'Packed',
        'dispatched' => 'Dispatched',
        'partially_delivered' => 'Partially delivered',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    'source' => [
        'manual' => 'By hand',
        'challan' => 'From the challan',
        'shipment' => 'From the trip',
        'backfill' => 'From earlier records',
    ],

    'column' => [
        'challan' => 'Challan',
        'date' => 'Challan date',
        'customer' => 'Customer',
        'stage' => 'Stage',
        'next' => 'Next stage',
        'since' => 'Since',
        'total' => 'Total',
    ],

    'timeline' => [
        'title' => 'Delivery stages',
        'empty' => 'No stage has been recorded yet.',
        'by' => 'Set by',
        'trip' => 'Trip',
        'receiver' => 'Received by',
        'reason' => 'Reason',
        'quantities' => 'Quantity delivered',
    ],

    'action' => [
        'title' => 'Change stage',
        'confirm' => 'Confirm delivery',
        'confirm_hint' => 'The goods reached the buyer - who received them.',
        'other' => 'Other stages',
        'none' => 'Nothing more can be set by hand from this stage.',
        'on_trip' => 'This challan is on trip :trip — dispatch and delivery news comes from the trip page.',
        'submit' => 'Set',
        'to' => 'Set :stage',
        'trip_short' => 'Trip :trip',
        'gate_pass_hint' => 'Setting Dispatched makes the gate pass, with this vehicle and driver.',
        'partial_page' => 'Partly delivered - on the challan page (quantities per line)',
    ],

    'field' => [
        'note' => 'Note',
        'reason' => 'Reason (from the list)',
        'reason_note' => 'Write the reason',
        'reason_hint' => 'Pick from the list or write it — one is required.',
        'receiver_name' => 'Received by',
        'receiver_phone' => 'Their phone',
        'delivered_qty' => 'Delivered quantity',
        'sent_qty' => 'On the challan',
        'damaged_qty' => 'Damaged',
        'product' => 'Product',
        'vehicle' => 'Vehicle (fleet)',
        'vehicle_not_in_fleet' => '- not in the fleet -',
        'vehicle_no' => 'Vehicle number',
        'driver_name' => 'Driver',
        'driver_phone' => 'Driver phone',
        'partial_hint' => 'Enter what the customer took in good order and what arrived damaged on each line; the rest (short) comes back on a sales return, the damaged part into held stock.',
    ],

    // ⭐ কম নিলে বাকিটা নিজে ফেরত — [[ShortDeliveryReturn]]
    'short_return_note' => 'Short delivery — the customer did not take the rest (challan :no)',

    'errors' => [
        'unknown_stage' => 'There is no such stage.',
        'not_allowed' => 'Cannot go straight from ":from" to ":to".',
        'on_a_trip' => 'This challan is on trip :trip — record this from the trip page.',
        'cannot_travel' => 'Challan :no is at ":stage" — it cannot go on a van.',
        'returned_cannot_travel' => 'Challan :no has a posted return (:return) — it cannot leave again. Make a new challan for the goods still to go.',
        'failed_needs_reason' => '"Failed" needs a reason — pick one or write it.',
        'receiver_required' => 'Write the name of the person who received the goods.',
        'phone_invalid' => 'The phone number is not valid — digits only, a leading + is fine.',
        'unknown_reason' => 'That reason is not on the list, or is not a reason for this job.',
        'line_not_on_challan' => 'A line does not belong to this challan.',
        'qty_invalid' => 'The quantity must be a number, zero or more.',
        'qty_over' => 'Line :line has more than was sent on the challan.',
        'partial_needs_something' => 'Nothing was delivered — choose "Failed" instead.',
        'partial_is_full' => 'Every line was delivered in full — choose "Delivered" instead.',
        'too_long' => 'The text is longer than :max characters.',
    ],
];
