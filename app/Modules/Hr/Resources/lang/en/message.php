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
    'salary_change_waiting' => 'The change is pending approval. It takes effect only when someone else presses "Approve"; until then the old amounts stay.',
    'salary_change_asked' => 'Salary structure change from :date, pending approval.',
    'salary_change_late' => 'The approved salary structure change was not applied: :reason',
    'salary_waiting_title' => 'Change from :date, pending approval',
    'salary_waiting_note' => 'These amounts are not in effect yet. The person who asked cannot approve it themselves (the owner can, marked separately in the audit); once approved it is entered in their name, and if it is rejected nothing changes.',
    // ⭐ fe-র উত্তর ১ ও ৪ (a6, ২১ অক্টোবর ২০২৬) — ইনবক্সের সারি, প্রতিটা সইয়ের অডিট, মালিকের নিজের সই
    'salary_change_summary' => 'From :date — :lines',
    'salary_change_signed' => 'Salary structure change from :date approved and applied (request #:approval).',
    'owner_self_signed' => 'The owner approved a salary change he asked for himself — a self-approved change.',
    // ⭐ বেতনের রানের সইয়ের সারি — নিট চাবি ছাড়া ঢাকা (a6, ২১ অক্টোবর ২০২৬; [[PayrollRunSummary]])
    'payroll_signing_summary' => ':month payroll · :count people · net :net',
    // ⭐ public holidays (a6, 21 Oct 2026; [[HolidayController]])
    'holiday_saved' => 'Holiday added.',
    'holiday_updated' => 'Holiday changed.',
    'holiday_deleted' => 'Holiday removed.',
    'no_holidays' => 'No holidays listed for this year.',
    'holiday_note' => 'Leave days and salary cuts skip these days and the weekly off (:weekly). A holiday for all branches applies to staff with no branch too.',
    // ⭐ earned leave (labour law s.117; a6, 21 Oct 2026)
    'earned_accrues' => '1 per :per days worked',
    // ⭐ a standard type is missing (a6, 21 Oct 2026; [[LeaveTypeDefaults]])
    'leave_types_missing' => 'Some standard leave types (for example maternity leave) are not in this company yet. Installing adds only the missing ones.',
    // ⭐ maternity signer warning (s.46(3); a6, 21 Oct 2026)
    'no_information' => 'No information',
    'maternity_children_unknown' => 'No information on surviving children — with two or more, no maternity benefit is due (s.46(3)). Check before you approve.',
    'maternity_benefit_line' => 'Maternity benefit',
    // ⭐ the average rule written on the page — a festival bonus is not wages (s.2(45), s.48; fe, 21 Oct 2026)
    'maternity_wage_rule' => 'Average daily wage: from the salary runs of the 3 months before notice; a festival bonus is not wages (s.2(45), 48)',
    // ⭐ branch weekly off (s.103, s.114; a6, 21 Oct 2026)
    'weekly_off_saved' => 'Weekly off saved for the branch.',
    'weekly_off_branches' => 'Weekly off by branch',
    'weekly_off_company' => 'As the company (:day)',
    // ⭐ actual delivery date (s.45; a6, 21 Oct 2026)
    'delivery_recorded' => 'Actual delivery date entered — pending approval.',
    'delivery_signed_short' => 'Approved — the weeks after delivery now run from the actual date.',
    'delivery_rejected' => 'Actual delivery date rejected — nothing changed.',
    'delivery_waiting' => 'Delivery :date — pending approval (leave would end :to)',
    'delivery_signed' => 'Actual delivery :actual approved: leave end moved :old → :new.',
    'weekly_off_company_row' => 'Company default',
    // ⭐ ভবিষ্য তহবিল — BLA ধারা ২৬৪ (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFund]])
    'pf_rate_applied' => 'Provident fund rate :from% to :to% — from :date.',
    'pf_applied' => 'Applied for :applied, pending approval for :waiting, skipped :skipped.',
    'pf_on_note' => 'Provident fund is on — employer share :employer, proposed employee share :employee.',
    'pf_off_note' => 'Provident fund is off — no employer share is booked. Proposed rates: employee :employee, employer :employer.',
    'pf_proposal_note' => 'Nothing changes by itself. Only fund members (deducted at more than zero today) change, from the chosen date; a month already paid is not touched.',
    'pf_apply_note' => 'The proposed rate applies from :date — written to each person\'s audit trail.',
    // ⭐ অধিকাল — BLA ধারা ১০৮ (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])
    'ot_prepared' => ':count sheets built or refreshed — pending approval.',
    'ot_signed' => 'Approved — these hours go into this month\'s salary.',
    'ot_on_note' => 'Overtime is on — a working day is :hours hours; eligible: :types.',
    'ot_off_note' => 'Overtime is off — sheets can be built and approved, but nothing is paid. A working day is :hours hours; eligible: :types.',
    'ot_formula_note' => 'Pay = basic ÷ 208 × 2 × approved extra hours (BLA s.108). Only the employee\'s own manager approves — on one\'s own or self-built sheet, only the owner.',
    'ot_none' => 'No overtime sheets this month.',
    'ot_owner_self_signed' => 'The owner approved an overtime sheet that was their own or that they built.',
    // ⭐ বেতন থেকে উৎসে কর — আয়কর আইন ২০২৩ ধারা ৮৬ (a6, ২১ অক্টোবর ২০২৬; [[SalaryTax]])
    'tax_slabs_missing' => 'No tax slabs are set — no tax was deducted at source in this run. Set HR\'s tax slabs in the control panel.',
    'tax_slabs_unreadable' => 'The tax slabs could not be read — write "width:rate" separated by commas; no tax was deducted at source in this run.',
    // ⭐ উৎসব ভাতা — শ্রম বিধিমালা ২০১৫ বিধি ১১১ (a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])
    'bonus_note' => 'Everyone with at least one year of service on that day gets the set percentage of basic (default one month\'s basic). At most two a year; approval and books as for salary, expense on "Festival Bonus".',
    // ⭐ উৎসব ভাতার সইয়ের সারি (a6, ২১ অক্টোবর ২০২৬)
    'bonus_signing_summary' => 'Festival bonus :month · :count people · net :net',
    // ⭐ শ্রমিক কে — BLA ধারা 2(65) (a6, ২১ অক্টোবর ২০২৬)
    'worker_note' => '"Worker" (WORKER) — under BLA s.2(65) staff employed in a managerial or administrative capacity are not workers; do not give them this type, and they get no overtime.',
    // ⭐ ভবিষ্য তহবিল — কেবল সদস্য, যোগ্যদের আলাদা যোগ, প্রাপ্তির ধাপ (BLA ধারা 264, 267; fe, ২১ অক্টোবর ২০২৬)
    'pf_members_title' => 'Fund members — current against proposed rate',
    'pf_candidates_title' => 'Not members, with a year of service — entitled to join the fund (BLA s.264)',
    'pf_no_candidates' => 'No non-member with a year of service.',
    'pf_enrol_done' => ':name has joined the fund.',
    'pf_enrol_waiting' => ':name joining the fund is pending approval.',
    'pf_enrolled' => 'Joined the provident fund — at :rate%, from :date.',
    // ⭐ গ্র্যাচুইটি — BLA ধারা 2(10), IAS 19 (fe, ২১ অক্টোবর ২০২৬, দফা ৮; [[Gratuity]])
    'gratuity_on_note' => 'Monthly provision is on — confirming a month\'s salary trues the books up to that month\'s obligation.',
    'gratuity_off_note' => 'Monthly provision is off — it is not booked with salary; use the button below to book it by hand.',
    'gratuity_formula_note' => 'Obligation = last basic × days ÷ 30; days = counted years × 30, or × 45 after more than 10 years; counted years = completed years, plus one when the remainder exceeds six months (BLA s.2(10)). The books are trued up to the obligation each month — an increase is expense, a decrease reverses.',
    'gratuity_booked_so_far' => 'Provision booked so far :booked; obligation as computed :total.',
    'gratuity_booked' => 'The gratuity provision for :month is booked.',
    'gratuity_nothing' => 'Nothing to book for :month — already booked, or the obligation did not change.',
    'gratuity_narration' => 'Gratuity provision :month',
    // ⭐ নিরীক্ষকের জন্য — IAS 19-এর সরলীকরণ (fe, ২১ অক্টোবর ২০২৬)
    'gratuity_ias19' => 'Under IAS 19 gratuity is a defined benefit; instead of a full actuarial valuation we carry the accrued obligation at current salary — an acceptable simplification for a small company.',
];
