<?php

declare(strict_types=1);

return [
    'show_left_employees' => 'Show employees who have left',
    'shared_across_companies' => 'One staff register for the whole group, across every company. ⚠️ This switch is installation-wide, not per company: turning it on lets every company see the staff and pay of every other company.',
    'attendance_affects_salary' => 'Absence reduces salary',
    'paper_payslip' => 'Paper for payslips',
    // ⭐ ভবিষ্য তহবিল — BLA ধারা ২৬৪ (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFund]])
    'pf_enabled' => 'Provident fund on — the employer\'s matching share goes on the payslip and into the books as expense and fund liability',
    'pf_employee_percent' => 'Provident fund — proposed employee rate (% of basic; existing structures do not change by themselves)',
    'pf_employer_percent' => 'Provident fund — employer share (% of basic)',
    // ⭐ অধিকাল — BLA ধারা ১০৮ (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])
    'ot_enabled' => 'Overtime on — signed extra hours are paid at basic ÷ 208 × 2',
    'ot_daily_hours' => 'Overtime — working hours a day (beyond this is extra)',
    'ot_eligible_types' => 'Who gets overtime — employment type codes, comma separated (e.g. WORKER)',
    // ⭐ বেতন থেকে উৎসে কর — আয়কর আইন ২০২৩ ধারা ৮৬ (a6, ২১ অক্টোবর ২০২৬; [[SalaryTax]])
    'tax_slabs' => 'Tax at source — slabs as "width:rate" separated by commas, the last may be "*:rate" (e.g. 350000:0, 100000:5, 400000:10, *:30); empty = nothing is deducted',
    'tax_exemption_cap' => 'Tax at source — salary exemption cap (one third or this amount, whichever is lower)',
    // ⭐ উৎসব ভাতা — শ্রম বিধিমালা ২০১৫ বিধি ১১১ (a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])
    'bonus_percent_of_basic' => 'Festival bonus — % of basic (100 = one month\'s basic; never more)',
];
