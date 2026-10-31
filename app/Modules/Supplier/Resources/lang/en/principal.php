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
    'basis_actual' => 'Actual — less the purchase cost',
    'basis_actual_short' => 'Actual',
    'basis_hint' => 'Actual: the principal gets only the purchase cost of the goods sold, everything above is commission — no rate needed.',
    'commission_earned' => '৳:amount',
    'commission_lost' => 'Loss ৳:amount — sold below the purchase cost',
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
    'dash_to_pay' => 'To pay: :amount',
    'dash_to_get' => 'Due from the company: :amount',
    'dash_period' => 'Period: :period',
    'so_far' => ':from – to date',

    // ⭐ Drill-down: the ledger lines behind collections and payments, and the formula box (owner, 24 Oct 2026)
    'lines_collections_title' => 'Principal collections: where the commission comes from',
    'lines_payments_title' => 'Paid to the principal: list',
    'line_date' => 'Date',
    'line_document' => 'Paper',
    'line_customer' => 'Received from',
    'line_account' => 'Into account',
    'drill_line' => 'Open the paper: :amount',
    'drill_inflow' => 'Open the collections behind :amount',
    'drill_commission' => 'Open how the commission :amount was worked out',
    'drill_share' => 'Open how the principal’s share :amount was worked out',
    'drill_paid' => 'Open the payments to the principal: :amount',
    'drill_balance' => 'Open how the balance :amount was worked out (share − paid)',
    'explain' => ':name · :period · collections :inflow × :rate = commission :commission · principal’s share = :inflow − :commission = :share · paid :paid · balance :balance',
    'explain_actual' => ':name · :period · principal’s share = actual purchase cost of the goods sold :share · commission = collections :inflow − :share = :commission · paid :paid · balance :balance',
    'date_invalid' => 'The date is not in the right shape (for example 2026-10-25).',
    'principal_invalid' => 'Only one principal can be chosen.',
];
