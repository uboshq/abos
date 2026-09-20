<?php

declare(strict_types=1);

/*
 * Budget — finance map §1, §16, §29; 20 September 2026.
 * Its own file: message.php is edited by several people at once.
 */
return [
    'title' => 'Budget',
    'note' => 'What each account should take in a month, and what the books say it took.',
    'report' => 'Budget report',
    'new' => 'New budget',
    'edit' => 'Edit',
    'edit_title' => 'Edit budget',
    'form_note' => 'One account, one year — all twelve months together. A blank month has no budget.',
    'tab_plan' => 'Budget plan',
    'tab_actual' => 'Budget vs actual',
    'tab_centers' => 'Budget by department',
    'year' => 'Year',
    'center' => 'Department',
    'all_centers' => 'All departments',
    'no_center' => 'No department',
    'by_center' => 'Split by department',
    'show' => 'Show',
    'account' => 'Account',
    'months' => 'Monthly budget (Tk)',
    'total' => 'Total',
    'budget' => 'Budget',
    'actual' => 'Actual',
    'variance' => 'Variance',
    'used' => 'Used',
    'period' => 'Period',
    'scope_ytd' => 'Year to this month',
    'scope_year' => 'Whole year',
    'plan_for' => 'Plan for :year',
    'no_plan' => 'No budget has been written for this year yet.',
    'no_plan_for_period' => 'No account has a budget in this period.',
    'saved' => 'Budget saved.',
    'account_must_be_income_or_expense' => 'A budget goes on an income or expense account — not a group.',
    'unknown_center' => 'That department was not found.',
    'amount_not_negative' => 'A budget cannot be negative.',

    // Dashboard card — §1 budget status
    'status' => 'Budget status',
    'status_hint' => 'Expense budget this month :budget, spent :actual',

    'month_short' => [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ],
    'month_long' => [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ],
];
