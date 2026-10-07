<?php

/*
 * The customer list's filters — owner, 1 Oct 2026: "date range, due range, advance range, area wise, point wise,
 * active/inactive". The chip names come from here too (toolbar `filterLabels`).
 */
return [
    'created_from' => 'Opened from',
    'created_to' => 'Opened to',
    'due_min' => 'Due at least',
    'due_max' => 'Due at most',
    'advance_min' => 'Advance at least',
    'advance_max' => 'Advance at most',
    'area' => 'Area',
    'point' => 'Point',
    'status' => 'Status',
    'any' => 'Any',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'all' => 'All',

    // One-click views
    'quick' => 'Quick view',
    'quick_due' => 'Owes money',
    'quick_advance' => 'Paid in advance',
    'quick_good' => 'Good customers',
    'quick_over_limit' => 'Over the limit',
    'good_rule' => 'Bought in the last :days days, owes within the credit limit, and no unpaid bill is older than their credit days (30 if empty)',

    // Top / bottom
    'rank' => 'Top / bottom',
    'rank_n' => 'How many',
    'rank_by' => 'Measure',
    'top' => 'Top',
    'bottom' => 'Bottom',
    'by_sales' => 'Sales',
    'by_due' => 'Due',
    'sales_from' => 'Sales from',
    'sales_to' => 'Sales to',
    'last_days' => 'last :days days',
    'today' => 'as of today',
    'rank_title' => ':rank :n — :by, :period',
];
