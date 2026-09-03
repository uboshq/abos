<?php

declare(strict_types=1);

return [
    'new_order' => 'New order',
    'new_receipt' => 'Receive goods',
    'new_bill' => 'New bill',
    'confirm' => 'Confirm',
    'cancel_document' => 'Cancel',
    'edit' => 'Edit',
    'add_line' => 'Add a line',
    'remove_line' => 'Remove',
    'show_cancelled' => 'Show cancelled too',
    'receive_against' => 'Receive against this order',
    'bill_against' => 'Bill this receipt',
    'new_challan' => 'Send goods',
    'new_invoice' => 'New invoice',
    'new_collection' => 'Collect money',
    'deliver_against' => 'Deliver against this order',
    'invoice_against' => 'Invoice this challan',
    'collect_against' => 'Collect against this invoice',
    'checkout' => 'Complete sale',
    'exact' => 'Exact',
    'add_to_cart' => 'Add to Cart',
    'clear_data' => 'Clear Data',
    /*
     * ⚠️ "Clear All", not "Clear Data" — the owner's decision (3 Sep 2026).
     *
     * The line-level button beside it already reads "Clear Data", and this
     * one wipes the whole invoice, which cannot be undone. Two buttons with
     * the same word for two different scopes is how a counter loses a bill.
     */
    'clear_full' => 'Clear All',
    'add_gift' => 'Add a gift',
    'chart_bulk_do' => 'Chart Entry',
    'transportation' => 'Transportation',
    'shipment' => 'Shipment',
    'add_deposit' => 'Add Deposit',
    'add_note' => 'Add Note',
    'expense' => 'Expense',
    'new_return' => 'New return',
    'shift_open' => 'Open shift',
    'shift_close' => 'Close shift',
    'print_again' => 'Print again',
    'print_settled' => 'It came out',
    'split_payment' => 'Split the payment',
    'add_payment_row' => 'Another method',
    'take_back' => 'Take it back',

    /*
     * ⚠️ These two were written in Bangla only, and the guard caught it —
     * on the English screen the buttons would have read
     * "sales::action.draft_restore" and "sales::action.draft_discard".
     *
     * "OK / Cancel" was avoided on purpose in both languages: neither word
     * says what the press does, and here one press either brings a whole
     * unfinished invoice back or throws it away for good.
     */
    'draft_restore' => 'Bring it back',
    'draft_discard' => 'Throw it away',

    // ডিলারের কমিশন
    'commission_settle' => 'Accepted',
    'commission_reject' => 'Refused',
    'new_scheme' => 'New scheme',
    'add_band' => 'Add a band',
    'activate_scheme' => 'Activate',
];
