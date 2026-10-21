<?php

declare(strict_types=1);

return [
    'employee_created' => 'Employee added. Now set the salary structure.',
    'employee_updated' => 'Employee updated.',
    'employment_ended' => 'Employment ended. The name stays, because old payslips need it.',
    'head_created' => 'Head added.',
    'head_updated' => 'Head updated.',
    'head_deactivated' => 'Head deactivated.',
    'heads_installed' => 'The standard heads are in.',
    'salary_saved' => 'Salary structure saved.',
    'no_employees' => 'No employees have been added yet.',
    'no_heads' => 'No heads yet.',
    'empty_heads' => 'Nobody can be paid until there are salary heads.',
    'empty_heads_note' => 'Basic, house rent, conveyance, provident fund — the usual heads go in with one press, and can be changed afterwards.',
    'no_salary_yet' => 'This employee has no salary structure yet.',
    'salary_note' => 'Existing rows are never edited. When pay rises, enter the new amount against a new date — that way last month\'s payslip keeps last month\'s figures.',
    'history' => 'Everything entered so far',
    'as_of' => 'In effect on :date',
    'left_on' => 'Left on :date',
    'run_built' => 'Payslips built for :count employees. Check the figures, then confirm.',
    'run_rebuilt' => 'The payslips were rebuilt.',
    'run_confirmed' => 'The salary is in the books. The bank file can be downloaded now.',
    'run_cancelled' => 'The run was cancelled and its ledger entries reversed.',
    'payroll_search' => 'Search by run number or note…',
    'no_runs' => 'No payroll has been run yet.',
    'draft_payslip' => 'DRAFT — this salary has not been confirmed',
    // ⓘ ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬
    'cancelled_payslip' => 'CANCELLED — this payroll run was cancelled; no money may be paid or received on this slip',
    'ledger_narration' => 'Salary for :month (:no)',
    'confirm_note' => 'Confirming puts the earnings into expense and the net into Salary Payable. No money has moved yet — that happens on a voucher.',
    'bank_file_note' => ':count salaries go through the bank. Cash and mobile-banking rows are not in the file.',
    'attendance_saved' => 'Attendance saved for :count people.',
    'no_attendance_yet' => 'Nothing has been marked for this day yet.',
    'attendance_note' => 'A box left blank is simply not marked — anything already saved stays as it is.',
    'attendance_off_note' => 'The switch that lets absence reduce salary is off, so these figures do not reach payroll. It can be turned on in the Control Panel.',
    'leave_applied' => 'The leave application is in, waiting for approval.',
    'leave_approved' => 'Leave approved, and the days are now in the attendance register.',
    'leave_rejected' => 'The application was rejected.',
    'leave_cancelled' => 'The application was withdrawn and its attendance rows taken back.',
    'leave_type_created' => 'Leave type added.',
    'leave_types_installed' => 'The standard leave types are in.',
    'no_leave' => 'No leave applications yet.',
    'no_leave_types' => 'No leave types yet.',
    'empty_leave_types' => 'Nobody can apply for leave until there are leave types.',
    'no_employees_for_day' => 'Nobody was employed on that day.',

    // ⭐ Own leave from the phone — with no employee record there is nobody to apply for (13 Oct 2026)
    'leave_no_employee' => 'There is no employee record in your name, so leave cannot be applied for. Ask HR.',
    'leave_part_day' => ':share day leave',
    // ⓘ ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬
    'leave_employee_gone' => 'Your employee record is closed (inactive or deleted), so leave cannot be applied for. If this is wrong, tell HR or the owner.',
    // ⭐ চাকরি ছাড়লে লগইন বন্ধ ([[LeaversLoseTheirLogin]])
    'leaver_login_closed' => 'Login of :name closed in this company — the leaving date :date has passed.',
    // ⭐ কে কী দেখলেন — নিরীক্ষায়, অঙ্ক ছাড়া ([[SensitiveViews]]; ঘাটতির তালিকা #১৮)
    'salary_viewed' => ':viewer viewed the salary.',
    'identity_viewed' => ':viewer viewed the identity details (NID, bank, address).',
    'payslip_viewed' => ':viewer opened the payslip.',
    'payroll_viewed' => ':viewer opened the payroll run.',
    // ⭐ the employee is outside the manager's branch reach — HR signs (fe, 21 Oct 2026)
    'leave_falls_to_hr' => 'This leave has gone to HR, because it is outside your reach',
    // ⭐ বেতনের কাঠামো বদলে দ্বিতীয় সই, দেওয়া মাসে বদল নয় (§৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬; [[SalaryStructureService::change()]])
    'salary_change_waiting' => 'The change is waiting for a signature. It takes effect only when someone else presses "Sign"; until then the old amounts stay.',
    'salary_change_asked' => 'Salary structure change from :date, waiting for a signature.',
    'salary_change_late' => 'The signed salary structure change was not applied: :reason',
    'salary_waiting_title' => 'Change from :date, waiting for a signature',
    'salary_waiting_note' => 'These amounts are not in effect yet. The person who asked cannot sign it themselves (the owner can, marked separately in the audit); once signed it is entered in their name, and if it is rejected nothing changes.',
    // ⭐ fe-র উত্তর ১ ও ৪ (a6, ২১ অক্টোবর ২০২৬) — ইনবক্সের সারি, প্রতিটা সইয়ের অডিট, মালিকের নিজের সই
    'salary_change_summary' => 'From :date — :lines',
    'salary_change_signed' => 'Salary structure change from :date signed and applied (request #:approval).',
    'owner_self_signed' => 'The owner signed a salary change he asked for himself — a self-approved change.',
    // ⭐ বেতনের রানের সইয়ের সারি — নিট চাবি ছাড়া ঢাকা (a6, ২১ অক্টোবর ২০২৬; [[PayrollRunSummary]])
    'payroll_signing_summary' => ':month payroll · :count people · net :net',
    // ⭐ ভবিষ্য তহবিল — BLA ধারা ২৬৪ (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFund]])
    'pf_rate_applied' => 'Provident fund rate :from% to :to% — from :date.',
    'pf_applied' => 'Applied for :applied, awaiting signature for :waiting, skipped :skipped.',
    'pf_on_note' => 'Provident fund is on — employer share :employer, proposed employee share :employee.',
    'pf_off_note' => 'Provident fund is off — no employer share is booked. Proposed rates: employee :employee, employer :employer.',
    'pf_proposal_note' => 'Nothing changes by itself. Only fund members (deducted at more than zero today) change, from the chosen date; a month already paid is not touched.',
    'pf_apply_note' => 'The proposed rate applies from :date — written to each person\'s audit trail.',
    // ⭐ অধিকাল — BLA ধারা ১০৮ (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])
    'ot_prepared' => ':count sheets built or refreshed — awaiting signature.',
    'ot_signed' => 'Signed — these hours go into this month\'s salary.',
    'ot_on_note' => 'Overtime is on — a working day is :hours hours; eligible: :types.',
    'ot_off_note' => 'Overtime is off — sheets can be built and signed, but nothing is paid. A working day is :hours hours; eligible: :types.',
    'ot_formula_note' => 'Pay = basic ÷ 208 × 2 × signed extra hours (BLA s.108). Only the employee\'s own manager signs — on one\'s own or self-built sheet, only the owner.',
    'ot_none' => 'No overtime sheets this month.',
    'ot_owner_self_signed' => 'The owner signed an overtime sheet that was their own or that they built.',
    // ⭐ বেতন থেকে উৎসে কর — আয়কর আইন ২০২৩ ধারা ৮৬ (a6, ২১ অক্টোবর ২০২৬; [[SalaryTax]])
    'tax_slabs_missing' => 'No tax slabs are set — no tax was deducted at source in this run. Set HR\'s tax slabs in the control panel.',
    'tax_slabs_unreadable' => 'The tax slabs could not be read — write "width:rate" separated by commas; no tax was deducted at source in this run.',
    // ⭐ উৎসব ভাতা — শ্রম বিধিমালা ২০১৫ বিধি ১১১ (a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])
    'bonus_note' => 'Everyone with at least one year of service on that day gets the set percentage of basic (default one month\'s basic). At most two a year; signature and books as for salary, expense on "Festival Bonus".',
    // ⭐ উৎসব ভাতার সইয়ের সারি (a6, ২১ অক্টোবর ২০২৬)
    'bonus_signing_summary' => 'Festival bonus :month · :count people · net :net',
    // ⭐ শ্রমিক কে — BLA ধারা 2(65) (a6, ২১ অক্টোবর ২০২৬)
    'worker_note' => '"Worker" (WORKER) — under BLA s.2(65) staff employed in a managerial or administrative capacity are not workers; do not give them this type, and they get no overtime.',
    // ⭐ ভবিষ্য তহবিল — কেবল সদস্য, যোগ্যদের আলাদা যোগ, প্রাপ্তির ধাপ (BLA ধারা 264, 267; fe, ২১ অক্টোবর ২০২৬)
    'pf_members_title' => 'Fund members — current against proposed rate',
    'pf_candidates_title' => 'Not members, with a year of service — entitled to join the fund (BLA s.264)',
    'pf_no_candidates' => 'No non-member with a year of service.',
    'pf_enrol_done' => ':name has joined the fund.',
    'pf_enrol_waiting' => ':name joining the fund is awaiting signature.',
    'pf_enrolled' => 'Joined the provident fund — at :rate%, from :date.',
];
