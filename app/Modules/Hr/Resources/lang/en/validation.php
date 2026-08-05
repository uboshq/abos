<?php

declare(strict_types=1);

return [
    'code_required' => 'A code is required.',
    'code_taken' => 'The code \':code\' is already in use.',
    'leaving_before_joining' => 'The leaving date cannot be before the joining date.',
    'bank_account_required' => 'Paying into a bank needs an account number — without it the bank rejects the whole salary file on payday.',
    'mfs_number_required' => 'Paying by mobile banking needs the number.',
    'amount_cannot_be_negative' => 'The amount cannot be negative.',
    'percent_out_of_range' => 'A percentage cannot be more than 100.',
    'basic_must_be_an_earning' => 'The basic salary is an earning, not a deduction.',
    'basic_cannot_be_deactivated' => 'The basic salary head cannot be switched off — the percentage allowances stand on it.',
    'nobody_on_payroll' => 'Nobody was on the payroll that month.',
    'month_already_run' => 'Salary for :month has already been run. Cancel that one before starting another.',
    'only_a_draft_can_change' => 'A confirmed run cannot be changed — cancel it and run again.',
    'nothing_to_confirm' => 'This run has no payslips.',
    'already_cancelled' => 'This run was already cancelled.',
    'head_needs_an_account' => 'The head :head has no ledger account, and the standard one could not be found.',
    'salary_payable_missing' => 'The chart has no Salary Payable account, so there is nowhere for the salary to sit.',
    'to_before_from' => 'The end date cannot be before the start date.',
    'days_must_be_positive' => 'The number of days must be greater than zero.',
    'days_exceed_range' => 'Those dates span :span days, so no more can be asked for.',
    'not_employed_then' => 'They were not employed at that time.',
    'leave_overlaps' => 'They already have leave covering some of those days.',
    'not_enough_leave' => 'Only :left days of :type are left.',
    'leave_already_decided' => 'This application has already been decided.',
    'leave_already_cancelled' => 'This application was already withdrawn.',
    'unknown_attendance_status' => 'That attendance status is not one we know.',
];
