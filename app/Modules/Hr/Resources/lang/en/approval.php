<?php

declare(strict_types=1);

/*
 * What this module's approvable actions are called in the flow builder.
 *
 * Only the module knows the name — ApprovalFlowService::labels() turns
 * "hr · payroll" into human words from here.
 */

return [
    'payroll' => 'Confirming a payroll run',
    'expense_claim' => 'Employee expense claim',
    'cash_advance' => 'Employee cash advance request',
    // ⭐ বেতনের কাঠামো বদল — দ্বিতীয় সই, ছক চালু করলে (§৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬)
    'salary_change' => 'Changing a salary structure',
    // ⭐ কর্মীর বেতনের ঠিকানা বদল — সবসময় দ্বিতীয় সই (মালিক, a6, ২১ অক্টোবর ২০২৬)
    'payment_change' => 'Changing where an employee\'s salary goes',
    'wppf_provision' => 'Workers\' profit participation fund provision',
    // ⭐ a cancelled run's bank-file line the bank did not pay — the wage goes back on the to-pay list; always a second signature (fe decision 2, 21 Oct 2026)
    'bank_unpaid' => 'Bank did not pay this line — wage arrears back on the to-pay list',
    // ⭐ final settlement of a leaver — always the owner's approval (owner, 21 Oct 2026)
    'final_settlement' => 'Final settlement of a leaver',
];
