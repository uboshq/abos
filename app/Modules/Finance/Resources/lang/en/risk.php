<?php

declare(strict_types=1);

/* Risk dashboard — finance map §1, 20 September 2026. */
return [
    'title' => 'Risk dashboard',
    'note' => 'What needs looking at today — cash, dues, limits, maturities and stock that is not moving.',
    'all_clear' => 'Nothing to look at right now.',
    'all_clear_hint' => 'Cash does not go below zero, nothing is overdue, and no facility limit is filling up.',
    'level_bad' => 'Today',
    'level_warn' => 'Coming up',
    'look' => 'Look →',

    'cash_runs_out' => 'Cash runs out',
    'cash_runs_out_hint' => 'Money in hand goes below zero by :until.',

    'receivables_overdue' => 'Overdue receivables',
    'receivables_overdue_hint' => 'Due now; :total receivable in all.',

    'payables_due' => 'Payables due now',
    'payables_due_hint' => 'Owed to suppliers now; :total payable in all.',

    'facility_used' => 'Bank facility filling up',
    'facility_used_hint' => ':name — :used used of :ceiling.',

    'deposits_maturing' => 'Deposits maturing',
    'deposits_maturing_hint' => 'Within :days days, :amount in all.',

    'loan_instalments' => 'Loan instalments',
    'loan_instalments_hint' => ':count instalments within :days days.',

    'stock_stuck' => 'Money stuck in unsold stock',
    'stock_stuck_hint' => ':count products have not moved in :days days.',
];
