<?php

declare(strict_types=1);

/*
 * On time in full (OTIF) — by order line (sales plan v2 §9, 6 Oct 2026, [[DeliveryReports]]).
 */
return [
    'title' => 'On time in full (OTIF)',
    'order' => 'Order',
    'customer' => 'Customer',
    'product' => 'Product',
    'promised_on' => 'Promised',
    'wanted' => 'Ordered',
    'on_time_qty' => 'Arrived on time',
    'arrived_qty' => 'Arrived in all',
    'first_arrival' => 'First arrival',
    'state' => 'State',
    'due' => 'Counted',
    'otif' => 'On time in full',
    'state_upcoming' => 'Upcoming',
    'state_otif' => 'On time in full',
    'state_late' => 'In full, late',
    'state_short' => 'Short',
    'state_none' => 'Not arrived',
    'summary' => 'OTIF',
    'summary_text' => ':rate% — :otif of :due lines on time in full',
    'none_due' => 'No line is past its promised date',
];
