<?php

declare(strict_types=1);

return [
    'receipt_needs_order' => 'Goods can only be received against an order',
    'over_receipt_percent' => 'Percent over the ordered quantity that may be received',
    'block_price_mismatch' => 'Block a bill whose value does not match the receipt',
    'reserve_on_order' => 'Hold stock when an order is confirmed',
    'allow_negative_stock' => 'Allow selling more than is available',
    'invoice_needs_challan' => 'An invoice must follow a challan',
    'walkin_customer' => 'Customer that cash sales are booked against',
    'field_free_qty' => 'Show the free quantity field',
    'field_gift' => 'Show the gift lines',
    'field_line_discount' => 'Show discount on each line',
    'field_expense' => 'Show the expense field',
    'field_rounding' => 'Show the rounding field',
    'field_do_no' => 'Show the DO number field',
    'field_deposit' => 'Show the counter deposit field',
    'field_credit_limit' => 'Show the customer\'s credit limit',
    'field_warehouse_select' => 'Show the warehouse picker',
    'field_sub_total' => 'Show the Sub Total (without VAT) row',
    'field_total_item' => 'Show the Total Item count',
    'field_sales_qty' => 'Show Total Sales Qnty',
    'field_free_qty_total' => 'Show Free Qty.',
    'field_total_qty' => 'Show Total Free+Sales Qty',

    'screen_pos' => 'Show the Counter (POS) screen',
    'screen_direct' => 'Show the Direct Sales screen',
    'screen_orders' => 'Show the Sales Orders screen',
    'screen_challans' => 'Show the Delivery Challans screen',
    'reprint_limit' => 'How many times one paper may be printed (0 = no limit)',
    'screen_shipments' => 'Show the Shipments screen',

    // ডিলারের কমিশন — কোম্পানির কাছে দাবি
    'commission_max_amount' => 'Highest commission in taka (0 = no limit)',
    'commission_max_percent' => 'Highest commission rate % (0 = no limit)',
];
