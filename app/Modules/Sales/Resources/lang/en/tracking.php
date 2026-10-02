<?php

declare(strict_types=1);

// Delivery tracking — 2 October 2026 ([[SaleTracking]])
return [
    'title' => 'Delivery tracking',
    'search' => 'Sale no, DO or shop',
    'find' => 'Find',
    'all' => 'All',
    'history' => 'Who did what, and when',
    'notice' => [
        'title' => ':no — :step',
        'body' => ':customer',
    ],
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
    'milestone' => [
        'order_created' => 'Order created',
        'approval_level' => 'Approval — level :level',
        'challan_draft' => 'Challan draft',
        'challan_confirmed' => 'Challan confirmed',
        'stock_allocated' => 'Stock allocated',
        'transport_assigned' => 'Transport assigned',
        'loading_started' => 'Loading started',
        'loading_completed' => 'Loading completed',
        'invoice_generated' => 'Invoice generated',
        'gate_pass_generated' => 'Gate pass generated',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
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
