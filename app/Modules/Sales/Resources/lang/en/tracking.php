<?php

declare(strict_types=1);

// Delivery tracking — 2 October 2026 ([[SaleTracking]])
return [
    'title' => 'Delivery tracking',
    'search' => 'Sale no, DO or shop',
    'find' => 'Find',
    'all' => 'All',
    'empty' => 'No sales.',
    'step' => [
        'ordered' => 'Order received',
        'draft' => 'Draft',
        'approval' => 'Awaiting approval',
        'warehouse' => 'In the warehouse',
        'gate_out' => 'Left the gate',
        'partial' => 'Partly delivered',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'billed' => 'Billed',
    ],
    'ordered' => 'Order :no placed',
    'do_written' => 'DO :no written',
    'sent_for_signature' => 'Sent for signature',
    'decision' => [
        'approved' => 'Approved',
        'rejected' => 'Sent back',
        'forwarded' => 'Forwarded',
        'other' => 'Decided',
    ],
    'received_by' => 'received by :name',
    'gate_pass' => 'Gate pass :no, vehicle :vehicle',
    'billed' => 'Bill :no, total :total',
];
