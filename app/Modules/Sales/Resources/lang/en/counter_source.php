<?php

declare(strict_types=1);

/* উৎস থেকে কাউন্টারে — ডিপোর যাচাই আর বিক্রয় (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ+চ; [[CounterSaleSources]]) */

return [
    'gone' => 'The paper this sale came from (DO) can no longer be found — deleted, cancelled, or not in your branch.',
    'mismatch' => 'The open draft came from a different paper — it cannot be confirmed against this DO.',
    'taken_by_draft' => 'The bill for :ref is already in draft :no — open it and confirm, or cancel it.',
    'other_customer' => ':ref belongs to another customer — the bill and the DO must be for the same customer.',
    'not_on_source' => ':product is not on :ref — goods outside the DO cannot be added to this bill.',
    'more_than_approved' => ':product — :approved approved on :ref, :asked on the bill. More than approved cannot be given; if more is needed the DO goes back to the supervisor.',
    'free_more_than_approved' => ':product — :approved free approved on :ref, :asked free on the bill. More free than approved cannot be given.',
    'other_warehouse' => 'The goods for :ref are held in the :warehouse warehouse — the sale must be made from that warehouse.',
    'from' => 'From :ref',
    'banner_hint' => 'Lines are filled in — reduce them if stock is short (the rest is a back order), never increase. Lots are set by FEFO.',

    'menu' => 'Depot check',
    'title' => 'Depot check',
    'subtitle' => 'DOs approved by accounts — check and open in the sale',
    'empty' => 'No DO is waiting for the depot check.',
    'unavailable' => 'Delivery orders are not switched on yet — this list fills by itself when they are.',
    'search' => 'DO number or customer',
    'open' => 'Check and open in the sale',
    'column' => [
        'do' => 'DO',
        'date' => 'Date',
        'customer' => 'Customer',
        'lines' => 'Products',
        'total' => 'Total',
        'status' => 'Status',
        'warnings' => 'Warnings',
        'action' => 'Action',
    ],
    'warning' => [
        'stock_short' => 'Stock short — reduce the order (asked :wanted, there is :available)',
        'rate_vs_lot' => 'Rate does not match the lot price — :rate on the order, :lot_price on lot :lot',
        'qty_changed' => 'The supervisor changed the quantity — asked :asked, approved :approved',
        'limit_near' => ':used_percent% of the credit limit is used',
        'lot_expiring' => 'Lot :lot expires in :days_left days',
        'other' => 'Warning: :kind',
    ],
];
