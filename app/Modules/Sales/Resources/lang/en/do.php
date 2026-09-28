<?php

declare(strict_types=1);

// Delivery orders — every sale's challan, in stage tabs ([[DeliveryOrderTabs]])
return [
    'tab' => [
        'new' => 'New DO',
        'drafts' => 'Drafts',
        'approval' => 'Awaiting approval',
        'awaiting' => 'Awaiting delivery',
        'delivered' => 'Delivered',
        'all' => 'All DOs',
        'cancelled' => 'Cancelled',
        'tracking' => 'DO tracking',
    ],
    'note' => [
        'awaiting' => 'Confirmed, goods not yet with the customer',
        'delivered' => 'Goods reached the customer',
        'all' => 'Everything except cancelled — drafts included',
        'cancelled' => 'Cancelled DOs — view only',
    ],
    'empty' => 'No DOs in this tab.',
];
