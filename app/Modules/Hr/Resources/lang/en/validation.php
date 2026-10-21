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
    'salary_already_paid' => ':paid of the :put this run owed has already been paid out — cancelling would turn salary payable negative. Cancel the salary payment voucher first, then the run.',
    'employee_advance_missing' => 'The chart has no Employee Advance account (1131), so there is nowhere to hold what an employee owes when deductions exceed the salary.',
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
    'branch_out_of_reach' => 'That branch is outside your reach — if you placed someone there, you could no longer see them yourself.',
    'reports_to_loop' => 'That would make the reporting line loop back — nobody can report to themselves or to someone below them.',
    'photo_only' => 'It must be a photo — JPG, PNG or WebP. Renaming a file does not make it one.',
    'photo_required' => 'A photo of the employee is required — the record is not saved without one.',
    'leave_own_approval' => 'You cannot approve your own leave — ask another manager or the owner.',
    'head_account_earning' => 'An earning head can only point at an active, postable expense account — not cash, bank or any other kind.',
    'head_account_deduction' => 'A deduction head can only point at an active, postable liability account or the employee advance account — not cash, bank or income.',
    'user_taken' => 'This user is already tied to another employee — one login belongs to one employee only.',
    'branch_required' => 'Pick a branch — your reach is limited to some branches, so an employee cannot be left without one.',
    'user_not_in_company' => 'This user is not in this company — only a user of this company can be tied to an employee.',
    'manager_out_of_reach' => 'This manager is outside your branch reach — you cannot place someone under them.',
    'leave_month_paid' => 'The :month payroll (:no) is already confirmed, so this leave cannot be withdrawn. Cancel that month\'s payroll run first.',
    // ⓘ ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬
    'basic_cannot_be_unset' => 'The basic salary head cannot stop being basic — the percentage allowances stand on it. To make another head basic, tick basic on that head.',
    'head_in_use_keeps_its_shape' => 'The salary structures of employees use this head — changing its kind (earning/deduction) or calculation (fixed/percent) would turn the next payslips upside down. Create a new head and put it in the structures instead.',
    'payroll_month_in_future' => 'Salary for a future month cannot be run in advance — choose the current month or an earlier one.',
    'payroll_date_before_month' => 'The payroll date cannot be before the first day of :month — this month\'s salary would land in an earlier month\'s books.',
    'salary_amount_shape' => 'Write the salary amount as a plain number — such as 20000 or 12.5, with at most four decimal places; forms like "1e5" or very large amounts are not accepted.',
    'leave_month_paid_approve' => 'The :month payroll (:no) is already confirmed, so leave in that month cannot be approved now: the payslip has already counted those days. Cancel the payroll run of that month first.',
    'attendance_month_paid' => 'The :month payroll (:no) is already confirmed, so the attendance of that month cannot be changed: the payslip was worked out from it. Cancel the payroll run of that month first.',
    'leave_range_too_long' => 'One application can cover at most :max days. Check the year in the dates, or split a longer leave into two applications.',
    'leave_own_withdrawal' => 'You cannot withdraw your own approved leave. Ask another manager or the owner.',
    'days_format' => 'Write the days as a plain number with at most one decimal place, such as 2 or 1.5.',
    'leave_type_closed' => 'That leave type cannot be chosen: it is switched off, deleted or not of this company. Choose an active leave type.',
    // ⭐ leave goes to the line manager (gap list #15, a6, 21 Oct 2026)
    'leave_not_your_line' => 'Only the employee\'s own manager (Reports To) or HR can sign this leave.',
    // ⭐ segregation of duties (fe, 21 Oct 2026)
    'leave_entered_by_you' => 'You entered this leave for someone else, so you cannot sign or refuse it. Ask another HR signer or the owner.',
    'leave_owner_self_signed' => 'The owner signed a leave he entered himself.',
    // ⭐ বেতনের কাঠামো বদলে দ্বিতীয় সই, দেওয়া মাসে বদল নয় (§৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬; [[SalaryStructureService::change()]])
    'salary_month_paid' => 'The :month payroll (:no) has already paid this employee. Changing the amount from that month or earlier would make the paid payslip and the structure disagree. Arrears pay is coming; until then, enter the new amount from the next month.',
    'salary_change_waiting' => 'Another salary change for this employee is still waiting for a signature. Let it be signed or rejected first, then enter the new change.',
    // ⭐ working days only (labour law ss.115-118; a6, 21 Oct 2026; [[LeaveService::assertWithinWorkingDays()]])
    'days_exceed_working' => 'Only :working working days fall in these dates — the weekly off and listed holidays are not leave days.',
    'holiday_twice' => 'This date is already a holiday for this branch or for all branches.',
    'holiday_branch_out_of_reach' => 'That branch is outside your reach.',
    'holiday_branch_required' => 'Pick a branch — your reach is limited, so you cannot set a holiday for every branch.',
    // ⭐ maternity leave (labour law ch.4, ss.45-50; a6, 21 Oct 2026; [[LeaveService::assertEligible()]])
    'maternity_women_only' => 'Maternity leave is for women employees only. If this is wrong, set the gender to female on the employee profile.',
    'maternity_six_months' => 'Maternity leave needs at least 6 months of service on the first day of leave. This employee completes 6 months on :date.',
    // ⭐ maternity s.46(3), s.47 (a6, 21 Oct 2026)
    'maternity_two_children' => 'No maternity benefit is due: the employee has :count surviving children (two or more, s.46(3)). Other leave she is entitled to can still be taken.',
    'maternity_expected_required' => 'Give the expected delivery date — maternity leave is 8 weeks before and 8 weeks after it (s.47).',
    'maternity_too_early' => 'Maternity leave can start at most 8 weeks before the expected delivery date — from :date.',
    'maternity_too_late' => 'Maternity leave ends at most 8 weeks after the expected delivery date — on :date.',
    // ⭐ actual delivery date (s.45; a6, 21 Oct 2026)
    'delivery_not_maternity' => 'The actual delivery date goes only on an approved maternity leave.',
    'delivery_out_of_range' => 'The actual delivery date must be on or after the first day of leave and not in the future.',
    'delivery_already_waiting' => 'An actual delivery date for this leave is already waiting for a signature.',
    'delivery_entered_by_you' => 'You entered this delivery date, so you cannot sign or refuse it. Ask another signer or the owner.',
    'weekly_off_company_needs_all_branches' => 'Only someone whose reach covers every branch can change the weekly off for the whole company.',
    // ⭐ ভবিষ্য তহবিল — BLA ধারা ২৬৪ (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFund]])
    'pf_accounts_missing' => 'The provident fund accounts are missing — the chart needs 5225 (employer contribution) and 2131 (provident fund payable). Use "Install standard chart" in the chart of accounts.',
    'pf_head_missing' => 'No provident fund salary head — a deduction head on a percentage of basic, booked to 2131 (provident fund payable), is needed.',
    'pf_apply_needs_the_key' => 'Applying the proposed rate needs the salary-change permission (hr.salary.manage).',
    // ⭐ অধিকাল — BLA ধারা ১০৮ (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])
    'ot_month_paid' => 'The salary for :month (:no) is already confirmed — that month\'s overtime can no longer be built or signed.',
    'ot_already_signed' => 'This sheet is already approved.',
    'ot_not_your_line' => 'Only the employee\'s own manager can sign this sheet — on one\'s own or self-built sheet, only the owner.',
    // ⭐ বেতন থেকে উৎসে কর — আয়কর আইন ২০২৩ ধারা ৮৬ (a6, ২১ অক্টোবর ২০২৬; [[SalaryTax]])
    'e_tin_shape' => 'An e-TIN is twelve digits — no spaces or dashes.',
    'tds_account_missing' => 'The tax-at-source account (2132) is missing — use "Install standard chart" in the chart of accounts.',
    // ⭐ উৎসব ভাতা — শ্রম বিধিমালা ২০১৫ বিধি ১১১ (a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])
    'bonus_month_already_run' => 'There is already a festival bonus run in :month — cancel it before making another.',
    'bonus_limit_reached' => ':max festival bonuses were already run in :year — no more in a year.',
    'nobody_due_a_bonus' => 'Nobody has a year of service on that day (or a basic set) — no one is due a festival bonus.',
    'bonus_account_missing' => 'The festival bonus account (5226) is missing — use "Install standard chart" in the chart of accounts.',
    // ⭐ ভবিষ্য তহবিল — কেবল সদস্য, যোগ্যদের আলাদা যোগ, প্রাপ্তির ধাপ (BLA ধারা 264, 267; fe, ২১ অক্টোবর ২০২৬)
    'pf_not_eligible' => 'This employee cannot join the fund — already a member, or not a year of service on that date.',
    // ⭐ গ্র্যাচুইটি — BLA ধারা 2(10), IAS 19 (fe, ২১ অক্টোবর ২০২৬, দফা ৮; [[Gratuity]])
    'gratuity_accounts_missing' => 'The gratuity accounts (5227 expense, 2134 payable) are missing — run `php artisan abos:hr-law-accounts`.',
];
