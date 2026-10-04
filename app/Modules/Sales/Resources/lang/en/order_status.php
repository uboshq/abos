<?php

declare(strict_types=1);

/*
 * Sales order status and progress — on the header and on each line (owner, 4 Oct 2026: international standard;
 * [[SalesOrderStatus]], [[OrderProgress]]).
 */
return [
    'state' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'awaiting_approval' => 'Awaiting approval',
        'approved' => 'Approved',
        'credit_held' => 'Credit hold',
        'confirmed' => 'Reserved',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ],

    'delivery' => [
        'none' => 'Not delivered',
        'partial' => 'Partly delivered',
        'full' => 'Fully delivered',
    ],

    'billing' => [
        'none' => 'Not billed',
        'partial' => 'Partly billed',
        'full' => 'Fully billed',
    ],

    'line' => [
        'open' => 'Open',
        'closed' => 'Closed',
        'rejected' => 'Will not be supplied',
    ],

    'source' => [
        'portal' => 'Dealer portal',
        'sr' => 'Salesman\'s phone',
        'counter' => 'Counter',
        'office' => 'Office',
    ],

    'back_order' => 'Back order',
    'back_order_hint' => 'The rest is not on the shelf of the order\'s warehouse',
    'stale' => 'Old draft · :days days',
    'stale_hint' => 'A draft for :days days — red after 3 days',

    // ⓘ বয়স লেখার মুহূর্ত থেকে; পাশে কাগজের তারিখ (সমন্বয়ক, ৪ অক্টোবর ২০২৬)
    'stale_paper' => 'paper dated :date',

    'tab_stale' => 'Old drafts',
    'hint_tab_stale' => 'Drafts not submitted, 3 days old or more',

    'tile_stale' => 'Old draft orders (3+ days)',

    'field_billed' => 'Billed',
    'field_progress' => 'Delivery and billing',
    'field_cancel_reason' => 'Cancel reason',
    'field_close_reason' => 'Close reason',
    'field_closed_by' => 'Closed by',

    'close' => 'Close the order',
    'close_hint' => 'A fully billed order closes without a reason. Closing with goods still open needs a reason — the rest becomes "will not be supplied" and the held stock is released.',
    'close_reason' => 'Reason (required when closing short)',
    'closed_flash' => 'Order closed.',

    'only_confirmed_closes' => ':no is not reserved — only a reserved order can be closed.',
    'short_close_needs_reason' => ':no is not fully billed — write a reason to close it short.',
    'nothing_went_cancel_instead' => 'Nothing has gone out on :no — cancel it instead of closing.',
    'closed_cannot_cancel' => ':no is closed — a closed order cannot be cancelled.',
];
