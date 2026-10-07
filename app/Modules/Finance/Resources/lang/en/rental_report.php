<?php

declare(strict_types=1);

/*
 * Rental contract and deposit reports — finance plan, part 5 (6 Oct 2026, [[RentalReports]]).
 */
return [
    'reports' => 'Rental reports',
    'schedule_short' => 'Rent schedule',

    'month' => 'Month',
    'contract' => 'Contract',
    'counterparty' => 'Landlord',
    'subject' => 'Place',

    'schedule_title' => 'Rent schedule',
    'rent_due' => 'Rent due',
    'paid_cash' => 'Paid in cash',
    'from_deposit' => 'From deposit',
    'waiting' => 'Awaiting signature',
    'outstanding' => 'Outstanding',
    'schedule_summary' => 'Rent outstanding',
    'schedule_text' => 'Due Tk :due, outstanding Tk :outstanding (awaiting signature Tk :waiting)',

    'advance_short' => 'Advance adjustment',
    'advance_title' => 'Advance and deposit adjustment',
    'opening_balance' => 'Opening',
    'given' => 'Given',
    'deducted' => 'Taken against rent',
    'refunded' => 'Refunded',
    'closing_balance' => 'Closing',
    'monthly_adjustment' => 'Taken per month',
    'months_left' => 'Months left',
    'advance_summary' => 'Total left in deposits',

    'book_short' => 'Deposit book',
    'book_title' => 'Deposit book',
    'opening_row' => 'Opening balance',
    'legacy' => 'Opening deposit (no voucher)',
    'taken_back' => 'Taken / refunded',

    'notice_ending' => ':who — rental contract ending soon (:place)',
    'notice_ending_body' => 'Ends on :date, :days days left. Give notice to renew or leave in time.',
    'notice_ended_body' => 'The term ended on :date, :days days ago, and the contract is still running. Renew or close it.',
    'notice_overdue' => ':who — rent overdue (:place)',
    'notice_overdue_body' => ':count months of rent overdue (:months) — Tk :amount',
    'dash_label' => 'Rent overdue',
    'dash_hint' => ':overdue contracts with rent overdue · :ending contracts ending within 60 days',

    'contracts_short' => 'Contracts',
    'contracts_title' => 'Rental contracts',
    'monthly_rent' => 'Monthly rent',
    'deposit_left' => 'Deposit left',
    'starts_on' => 'Starts',
    'ends_on' => 'Ends',
    'days_left' => 'Days left',
    'state' => 'State',
    'state_running' => 'Running',
    'state_lapsed' => 'Term lapsed',
    'state_closed' => 'Closed',

    'increase_percent' => 'Yearly increase % (if in the contract)',
    'effective_from' => 'From which month',
    'term_line' => 'From :from, Tk :rent a month',
    'term_outside_contract' => 'This month is outside the contract term.',
    'notice_anniversary' => ':who — contract anniversary coming (:place)',
    'notice_anniversary_body' => 'Anniversary on :date. The contract provides a :percent% increase — from Tk :rent to Tk :next. If it rises, record the new terms.',
];
