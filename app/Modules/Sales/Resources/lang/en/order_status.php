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
        'portal' => 'Customer portal',
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
    // ⭐ নতুন ধারা — জমা, বাকির যাচাই, সুপারভাইজার (ধাপ ৩, ৪ অক্টোবর ২০২৬)
    'setting_replace_do' => 'The sales order does the DO\'s job (submit, credit check, supervisor\'s signature)',
    'submit_needs_switch' => ':no — the new submit flow is not switched on in this company.',
    'only_draft_submits' => ':no is not a draft — only a draft can be submitted.',
    'not_awaiting_you' => ':no is not awaiting a signature — its quantities cannot change now.',
    'approved_qty_range' => 'The quantity must be between 0 and the requested :asked — it cannot go up.',

    // ⭐ এক লাইনের বাকিটা বন্ধ (ধাপ ৭, ৪ অক্টোবর ২০২৬)
    'reject_needs_reason' => 'Write a reason to close the rest.',
    'only_confirmed_rejects' => ':no is not reserved — only a reserved order\'s line can have its rest closed.',
    'reject_qty_range' => ':no — :open is open on this line; the quantity to close must be above 0 and no more than what is open.',

    // ⭐ আদেশের পাতা আর তালিকা — নতুন ধারা (ধাপ ৮, ৫ অক্টোবর ২০২৬)
    'submit' => 'Submit',
    'requested' => 'asked :qty',
    'only_mine' => 'Only those waiting for my signature',
    'tab_draft' => 'Draft',
    'hint_tab_draft' => 'Not submitted yet — can still change',
    'tab_awaiting' => 'Awaiting signature',
    'hint_tab_awaiting' => 'Waiting for the supervisor\'s signature',
    'tab_credit_held' => 'Credit hold',
    'hint_tab_credit_held' => 'The credit limit did not fit — checked again when money arrives',
    'tab_depot' => 'At depot check',
    'hint_tab_depot' => 'Checked by the depot and opened at the counter',
    'tab_old_do' => 'Old DOs',
    'hint_tab_old_do' => 'Open DOs of the old flow — they finish on their own numbers',
    'box_awaiting' => 'Awaiting signature',
    'box_level' => 'Now at level :level',
    'box_open_signature' => 'Open the signature page',
    'box_credit_held' => 'Held on the credit limit',
    'box_short' => 'Short of the limit by ৳:amount',
    'box_held_since' => 'First held: :date',
    'box_checked_at' => 'Last checked: :date',
    'box_credit_hint' => 'When money arrives the order is checked again by itself',
    'box_warnings' => 'Warnings',
    'box_held' => 'Stock held for this order',
    'box_held_line' => ':product — :qty',
    'box_held_hint' => 'Nobody else can sell this stock — it leaves on a challan and is released on cancel or close',
    'lower_title' => 'Supervisor: lower the quantities',
    'lower_hint' => 'Only lower, never raise; 0 means not this product this time. Then sign on the signature page.',
    'lower_line' => ':product — asked :asked',
    'lower_save' => 'Keep the quantities',
    'lower_saved' => 'Quantities kept.',
    'field_lower_qty' => 'Quantity',
    'reject_title' => 'Close the rest of one line',
    'reject_hint' => 'What you close here will not be supplied — the held stock is released. A reason is required.',
    'reject_option' => ':product — :open open',
    'field_reject_line' => 'Line',
    'field_reject_qty' => 'How much to close',
    'field_reject_reason' => 'Reason',
    'reject_save' => 'Close the rest',
    'reject_saved' => 'The rest of the line is closed.',

    'closed_cannot_cancel' => ':no is closed — a closed order cannot be cancelled.',
];
