<?php

declare(strict_types=1);

/**
 * The sales overview screen (NEXUS §4) — card, trend and breakdown names.
 */
return [
    'title' => 'Sales overview',
    'subtitle' => 'Sales, collections, returns and targets — for the chosen period',
    'period' => 'Period',
    'kind' => [
        'today' => 'Today',
        'month' => 'This month',
        'year' => 'This financial year',
        'custom' => 'Pick dates',
    ],
    'showing' => 'From :from to :to',
    'range_refused' => 'That date range was not valid (end before start, or longer than :days days), so this month is shown instead.',
    'nothing_for_you' => 'You are not allowed to see any figure on this screen.',
    'empty' => 'Nothing in this period.',
    'nobody' => 'No name',
    'unplaced' => 'No area set',
    'no_branch' => 'No branch',

    'direct_hint' => ':count invoices — without an order, from the counter',
    'discount_hint' => ':percent% of gross — on confirmed invoices',
    'returns_hint' => ':count confirmed returns',
    'receivable_hint' => 'In :shops shops, by the ledger up to :date',
    'target_none' => 'No target is set for :month',
    'target_hint' => ':achieved of a :target target — before VAT',

    'card' => [
        'invoices' => 'Confirmed invoices',
        'direct' => 'Direct sales',
        'gross' => 'Gross',
        'discount' => 'Discount',
        'tax' => 'VAT',
        'net' => 'Net sales',
        'orders' => 'Confirmed orders',
        'pending_delivery' => 'Not yet delivered',
        'returns' => 'Returns',
        'collection' => 'Collected',
        'receivable' => 'Receivable',
        'outstanding' => 'Left unpaid this period',
        'target' => 'Target achieved',
    ],

    'hint' => [
        'invoices' => 'Drafts and cancelled left out, by transaction date',
        'direct' => 'Invoices of challans cut without an order',
        'gross' => 'Before discount and VAT — quantity × rate',
        'discount' => 'Discount on confirmed invoices',
        'tax' => "The government's money — not our income",
        'net' => 'After discount, VAT included — the same figure as the home screen',
        'orders' => 'Orders confirmed in this period',
        'pending_delivery' => 'As of today — nothing or only part delivered',
        'returns' => 'Confirmed returns',
        'collection' => 'Collections and customer receipts, posted',
        'receivable' => "Positive balances in the customers' ledger",
        'outstanding' => 'Net sales − collected; negative means old dues came in',
        'target' => 'For the month of the end date',
    ],

    'panel' => [
        'daily' => 'Sales over the last :days days',
        'monthly' => 'Sales over twelve months',
        'return_trend' => 'Returns over twelve months',
        'discount' => 'Discount month by month',
        'top_customers' => 'Top customers',
        'top_products' => 'Top products',
        'slow_products' => 'Slowest products',
        'by_seller' => 'Invoices by who cut them',
        'by_territory' => 'Sales by :level',
        'by_branch' => 'Sales by branch',
    ],

    'col' => [
        'name' => 'Name',
        'count' => 'Invoices',
        'amount' => 'Amount',
        'share' => 'Share',
        'product' => 'Product',
        'qty' => 'Quantity',
        'revenue' => 'Sales (before VAT)',
        'month' => 'Month',
        'gross' => 'Gross',
        'discount' => 'Discount',
        'discount_percent' => 'Discount %',
        'net' => 'Net',
        'collected' => 'Collected',
    ],
];
