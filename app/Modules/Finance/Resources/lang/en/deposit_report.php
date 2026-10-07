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

    'accrual_month' => 'Month',
    'accrual_run' => 'Book accrued profit for the month',
    'accrual_note' => 'Booked on the last day of the month and reversed by itself on the first day of the next; once per month.',
    'accrual_done' => 'Profit booked on :accrued deposits, :reversed earlier accruals reversed, :held awaiting signature.',
    'accrual_month_not_over' => 'The month is not over yet — only a finished month can be booked.',
    'accrual_narration' => 'Accrued profit for :month — :deposit (:institution)',
    'accrual_reversal_narration' => 'Accrued profit for :month reversed — :deposit',

    'source_tax_cut' => 'Source tax (deducted by the bank)',
    'excise_duty' => 'Excise duty',
    'penalty' => 'Early encashment penalty',
    'deduction_negative' => 'A deduction cannot be negative.',
    'deduction_owner' => 'Tax or deductions on an owner-held deposit do not go into the business books.',
    'penalty_only_on_close' => 'A penalty applies only when the deposit is encashed.',

    'lien_title' => 'The bank encashed the deposit to settle the loan',
    'lien_hint' => 'Enter from the bank letter: how much went to the loan, any surplus paid to us and into which account, and what was deducted.',
    'lien_applied' => 'Applied to the loan',
    'lien_remainder' => 'Surplus paid to us',
    'lien_run' => 'Record the lien encashment',
    'lien_done' => ':no — lien encashment recorded.',
    'lien_needs_live_facility' => 'This deposit is not pledged to a running bank loan — use the normal encashment.',
    'lien_applied_over_owed' => 'The amount applied is more than the loan owed that day (Tk :owed) — enter the surplus as paid to us.',
    'lien_narration' => ':no — encashed to settle :loan',
];
