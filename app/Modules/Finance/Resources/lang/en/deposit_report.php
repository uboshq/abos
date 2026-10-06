<?php

declare(strict_types=1);

/*
 * Deposit reports — finance plan, part 4 (6 Oct 2026, [[DepositReports]]).
 */
return [
    'reports' => 'Deposit reports',
    'accrued_short' => 'Accrued interest',

    'deposit' => 'Deposit',
    'institution' => 'Institution',
    'holder' => 'Held by',
    'holder_business' => 'Business',
    'holder_owner' => 'Owner',
    'principal' => 'Principal',
    'profit_rate' => 'Rate',
    'matures_on' => 'Matures',

    'accrued_title' => 'Accrued interest (earned, not yet received)',
    'accrued' => 'Accrued',
    'source_tax' => 'Source tax',
    'net_accrued' => 'Net',
    'accrued_summary' => 'Total accrued interest, net',
    'accrued_text' => 'Accrued Tk :gross − source tax Tk :tax = Tk :net',

    'instalments_short' => 'DPS instalments',
    'instalments_title' => 'DPS instalment schedule',
    'month' => 'Month',
    'due_on' => 'Due on',
    'due' => 'Due',
    'paid' => 'Paid',
    'waiting' => 'Awaiting signature',
    'outstanding' => 'Outstanding',
    'overdue' => 'Overdue',
    'instalments_summary' => 'Overdue instalments',
    'instalments_text' => 'Overdue Tk :overdue (awaiting signature Tk :waiting)',

    'notice_soon' => ':institution :document — matures this week',
    'notice_matured_body' => 'Matured on :date, :days days ago, and the deposit is still open. Record the encashment or the renewal.',
    'notice_dps' => ':institution :document — DPS instalment overdue',
    'notice_dps_body' => ':count months of instalments overdue — Tk :amount',

    'liens_short' => 'Loan security',
    'liens_title' => 'Deposits held against loans (lien)',
    'loan' => 'Loan',
    'sanctioned' => 'Loan limit',
    'owed' => 'Loan owed',
    'lien_state' => 'State',
    'lien_locked' => 'Held',
    'lien_free' => 'Can be released',

    'pledge_needs_live_facility' => 'The pledge must be against a running bank loan of this company.',
];
