<?php

declare(strict_types=1);

/*
 * Report Center words — Report Center step 1, 2 October 2026 (ReportCenterController, ReportCenterPlan).
 *
 * `plan.*` — the owner's reports that are not built yet (docs checklist, steps 2–6); only the owner/admin sees them.
 */
return [
    'title' => 'Report Center',
    'subtitle' => 'Every module\'s reports, by module — the ones you may open',
    'lines_done' => 'reports built',
    'across' => 'Across all modules',
    'steps' => [
        's2' => 'Step 2 · owner page',
        's3' => 'Step 3 · analysis',
        's4' => 'Step 4 · control',
        's5' => 'Step 5 · people and security',
        's6' => 'Step 6 · books and audit',
    ],
    'favourites' => 'My favourite reports',
    'no_favourites' => 'No favourites yet. Set the filters on any report and choose "Save this view" from its title menu — it will appear here.',
    'open' => 'Report Center',

    'plan' => [
        'executive' => 'Executive page — branches side by side: sales, profit, cash, receivable, payable, stock value',
        'branch_profit_loss' => 'Profit and loss by branch',
        'sales_analysis' => 'Sales analysis — by customer, product, brand, salesman, area; growth %, best/worst 10',
        'receivable_calendar' => 'Receivable due today, this week, this month — credit limit use',
        'payable_calendar' => 'Payable due today, this week, this month',
        'cash_position' => 'Cash position — cash/bank, cheque register, post-dated and bounced cheques',
        'expense_analysis' => 'Expense analysis — by head, branch, month; unusually large expenses',
        'attendance' => 'Attendance — daily, monthly, late, absent; leave balance',
        'payroll' => 'Payroll register — deductions, bonus, salary cost by branch',
        'security' => 'Security — sign-ins, failed attempts, permission and role changes',
        'backup_runs' => 'Backups — succeeded/failed, last run, restore tests',
        'customer_activity' => 'Customers — new, active/inactive, by area, not bought for long',
        'supplier_activity' => 'Suppliers — new, active/inactive',
        'paper_audit' => 'Audit — who changed or cancelled which paper, back-dating, month reopened',
        'party_vs_ledger' => 'Party ledgers against the general ledger, branch-to-branch balances',
    ],
];
