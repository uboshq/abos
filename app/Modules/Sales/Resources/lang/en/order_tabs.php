<?php

declare(strict_types=1);

// Order list tabs — formerly separate menu rows ([[OrderTracking::applyListTab()]])
return [
    'label' => 'Order tabs',
    'all' => 'All orders',
    'pending' => 'Pending',
    'partial' => 'Partial',
    'back' => 'Back order',
    'history' => 'History',
    'hint_all' => 'Every order except cancelled ones.',
    'hint_pending' => 'Orders with nothing delivered yet.',
    'hint_partial' => 'Orders with some goods delivered and some still to go.',
    'hint_back' => 'Confirmed orders whose remaining goods are not on the warehouse shelf — they go when stock arrives.',
    'hint_history' => 'Finished orders — everything delivered, or cancelled.',
];
