<?php

declare(strict_types=1);

return [
    'show_left_employees' => 'Show employees who have left',
    'shared_across_companies' => 'One staff register for the whole group, across every company. ⚠️ This switch is installation-wide, not per company: turning it on lets every company see the staff and pay of every other company.',
    'attendance_affects_salary' => 'Absence reduces salary',
    'paper_payslip' => 'Paper for payslips',
    // ⭐ weekly off (labour law s.103; a6, 21 Oct 2026)
    'weekly_off' => 'Weekly off day (not counted in leave, absence on it is not deducted)',
    'weekly_off_none' => 'None',
    'weekly_off_friday' => 'Friday',
    'weekly_off_saturday' => 'Saturday',
    'weekly_off_sunday' => 'Sunday',
    'weekly_off_friday_saturday' => 'Friday and Saturday',
    'weekly_off_saturday_sunday' => 'Saturday and Sunday',
    // ⭐ earned leave carry forward (labour law s.117; a6, 21 Oct 2026)
    'earned_leave_carry_cap' => 'Earned leave: most days carried into the next year',
    'weekly_off_owned' => 'This is changed on the weekly off card of the holiday screen.',
    // ⭐ ভবিষ্য তহবিল — BLA ধারা ২৬৪ (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFund]])
    'pf_enabled' => 'Provident fund on — the employer\'s matching share goes on the payslip and into the books as expense and fund liability',
    'pf_employee_percent' => 'Provident fund — proposed employee rate (% of basic; existing structures do not change by themselves)',
    'pf_employer_percent' => 'Provident fund — employer share (% of basic)',
    // ⭐ অধিকাল — BLA ধারা ১০৮ (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])
    'ot_enabled' => 'Overtime on — approved extra hours are paid at basic ÷ 208 × 2',
    'ot_daily_hours' => 'Overtime — working hours a day (beyond this is extra)',
    'ot_eligible_types' => 'Who gets overtime — employment type codes, comma separated (e.g. WORKER)',
    // ⭐ বেতন থেকে উৎসে কর — আয়কর আইন ২০২৩ ধারা ৮৬ (a6, ২১ অক্টোবর ২০২৬; [[SalaryTax]])
    'tax_slabs' => 'Tax at source — slabs as "width:rate" separated by commas, the last may be "*:rate" (e.g. 350000:0, 100000:5, 400000:10, *:30); empty = nothing is deducted',
    'tax_exemption_cap' => 'Tax at source — salary exemption cap (one third or this amount, whichever is lower)',
    // ⭐ উৎসব ভাতা — শ্রম বিধিমালা ২০১৫ বিধি ১১১ (a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])
    'bonus_percent_of_basic' => 'Festival bonus — % of basic (100 = one month\'s basic; never more)',
    // ⭐ ভবিষ্য তহবিল — কেবল সদস্য, যোগ্যদের আলাদা যোগ, প্রাপ্তির ধাপ (BLA ধারা 264, 267; fe, ২১ অক্টোবর ২০২৬)
    'pf_vesting_steps' => 'Provident fund — employer-share vesting steps, "completed years:percent" separated by commas (e.g. 3:50, 5:100); empty = not set, final settlement will not compute',
    // ⭐ গ্র্যাচুইটি — BLA ধারা 2(10), IAS 19 (fe, ২১ অক্টোবর ২০২৬, দফা ৮; [[Gratuity]])
    'gratuity_enabled' => 'Gratuity — when a month\'s salary is confirmed, the provision is trued up to that month\'s obligation (5227 expense / 2134 liability). Under IAS 19 gratuity is a defined benefit; instead of a full actuarial valuation we carry the accrued obligation at current salary — an acceptable simplification for a small company.',
    'wppf_enabled' => 'Workers\' profit participation fund (Labour Act 2006, chapter 15) applies to this company',
];
