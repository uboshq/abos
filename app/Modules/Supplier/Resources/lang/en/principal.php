<?php

declare(strict_types=1);

/*
 * Principal commission — owner, 5 Oct 2026 ([[PrincipalCommission]]).
 */
return [
    'title' => 'Principal commission',
    'notice' => 'Report only — nothing is posted to the books. Collections = money received from customers in cash, bank, MFS or cheque in the principal branch (moves between own accounts excluded). Each row runs on its own cycle.',

    'section' => 'Principal and commission',
    'section_hint' => 'If this supplier is a principal — the depot commission on collections. For the report only; nothing is posted.',
    'branch' => 'Principal branch',
    'branch_hint' => 'The branch whose collections the commission is counted on',
    'basis' => 'Commission basis',
    'basis_margin' => 'Margin',
    'basis_markup' => 'Markup',
    'rate' => 'Commission rate (%)',
    'start_day' => 'Cycle start day',
    'close_day' => 'Cycle close day',
    'month_end' => 'Month end',

    'principal' => 'Principal',
    'period' => 'Period',
    'inflow' => 'Collections',
    'basis_rate' => 'Basis and rate',
    'commission' => 'Commission',
    'share' => 'Share of the principal',
    'paid' => 'Paid to the principal',
    'balance' => 'Balance',
    'balance_to_pay' => 'To pay ৳:amount',
    'balance_to_get' => 'Due from the company ৳:amount',

    'month' => 'Month the cycle closes in',
    'current_cycle' => 'Current cycle',
    'month_invalid' => 'Give the month as year-month, for example 2026-10.',

    'dashboard_title' => 'Principal commission — current cycle',
    'dashboard_empty' => 'No principal has a commission set.',
    'dash_inflow' => 'Cumulative inflow',
    'dash_sent' => 'Sent to principal',
    'dash_balance' => 'Balance inflow',
    'dash_remarks' => 'Remarks / period',
];
