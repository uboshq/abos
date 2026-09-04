<?php

declare(strict_types=1);

return [
    'supplier' => 'Supplier',
    'product' => 'Product',
    'gift' => 'Gift',
    'gift_against' => 'Came with',
    'warehouse' => 'Warehouse',
    'branch' => 'Branch',
    'ordered' => 'Ordered',
    'received' => 'Received',
    'pending' => 'Pending',
    'unbilled' => 'Not billed',
    'unbilled_value' => 'Value not billed',
    'bill_count' => 'Bills',
    'subtotal' => 'Subtotal',
    'discount' => 'Discount',
    'tax' => 'VAT',
    'total' => 'Total',
    'rate' => 'Rate',
    'quantity' => 'Quantity',
    'amount' => 'Amount',
    'document_no' => 'Number',
    'date' => 'Date',
    'expected_on' => 'Expected on',
    'due_on' => 'Due on',
    'supplier_challan_no' => 'Supplier\'s challan no',
    'supplier_bill_no' => 'Supplier\'s bill no',
    'narration' => 'Narration',
    'status' => 'Status',
    'order' => 'Purchase order',
    'receipt' => 'Receipt',
    'unit' => 'Unit',
    'line_no' => 'No',

    // Payment fields
    'bill' => 'Bill',
    'account' => 'Paid from',
    'instrument' => 'Method',
    'instrument_no' => 'Cheque / reference no',
    'instrument_date' => 'Cheque date',

    // Return fields
    'reason' => 'Reason',
    'state' => 'State',

    // Sales price — on the purchase paper, because the rate is what sets it
    'sales_price' => 'Sales price',
    'markup' => 'Markup %',
    'margin' => 'Margin %',
    'trx_date' => 'Invoice date',
    'qty' => 'Qty',
    'free_qty' => 'Free',
    'line_total' => 'Line total',
    'sub_total' => 'Sub total',
    'net_payable' => 'Net payable',
    'paid_now' => 'Paid now',
    'paid_from' => 'Paid from',
    'balance_due' => 'Balance due',

    // The owner's totals card — three separate rows, not one
    //
    // The next two are not on the screen yet: the fields were pulled
    // because nothing carries them to the ledger (see the totals card
    // in direct/index.blade). The words stay so the work returns whole.
    'expense' => 'Expense',
    'rounding' => 'Rounding',
    'invoice_due' => 'Invoice Due',
    'previous_due' => 'Previous Due',
    'previous_advance' => 'Previous Advance',
    'total_due' => 'DUE',
    'total_item' => 'Total items',
    'total_qty' => 'Total qty',

    /*
     * The payment panel — one bill, several ways of paying it.
     *
     * 'paid_how' is the empty option on the method list. It reads as a
     * question because the list is not a filter: nothing can be added
     * until it is answered.
     */
    'paid_how' => 'How was it paid',
    'paid_total' => 'Paid now',
    'reference' => 'Cheque / transaction no.',

    /*
     * Who brought the goods, and what the ride cost.
     *
     * The same four names as the sales side uses on a challan, on
     * purpose: one word should mean one thing in both directions.
     */
    'carrier' => 'Who brought it',
    'carrier_name' => 'Carrier name',
    'transport_cost' => 'Freight',
    'vehicle_no' => 'Vehicle no.',
    'driver_name' => 'Driver',
];
