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
];
