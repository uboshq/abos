<?php

/*
 * ⭐ Sales invoice list filters — the central engine ([[InvoiceListFilters]], 15 Nov 2026).
 */
return [
    'stage' => 'Status',
    'pay' => 'Payment',
    'pay_due' => 'Still owing',
    'pay_overdue' => 'Overdue',
    'pay_paid' => 'Paid',
    'date' => 'Invoice date',
    'depot' => 'Depot',
    'area' => 'Area',
    'total' => 'Invoice amount (৳)',
    'bills' => ':n invoices',
    'card_all' => 'All invoices',
    'card_draft_sub' => 'not in the books yet',
    'cancelled' => 'Cancelled',
    'noun' => 'invoices',

    // ⭐ চালানের তালিকা (১৫ নভেম্বর ২০২৬)
    'delivery' => 'Delivery',
    'delivery_awaiting' => 'Waiting to arrive',
    'delivery_done' => 'Delivered',
    'challan_date' => 'Challan date',
    'challans' => ':n challans',
    'card_all_challans' => 'All challans',
    'card_all_challans_sub' => 'not counting cancelled',
    'card_awaiting_sub' => 'in the books, not yet arrived',
    'card_delivered_sub' => 'with the customer',
    'challan_noun' => 'challans',
];
