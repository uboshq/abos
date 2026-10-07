<?php

declare(strict_types=1);

return [
    'top_owed' => 'Who we owe the most',
    'supplier' => 'Supplier',
    'we_owe_nobody' => 'We owe nobody',

    'top_due' => 'Who owes the most',
    'customer' => 'Customer',
    'nobody_owes' => 'Nobody owes anything',

    // Owner Dashboard-এর সংখ্যাগুলো — ৪ সেপ্টেম্বর ২০২৬
    'today_collection' => 'Collected today',
    'today_collection_hint' => 'Money received from customers today',
    'today_payment' => 'Paid today',
    'today_payment_hint' => 'Money paid to suppliers today',
    'today_expense' => 'Spent today',
    'today_expense_hint' => 'All expense heads for today',
    'outstanding_loan' => 'Outstanding loan',
    'outstanding_loan_hint' => 'What is still owed on long-term loans',
    'asset_value' => 'Asset value',
    'asset_value_hint' => 'Book value of fixed assets, after depreciation',

    'money_on_hand' => 'Money on hand',
    'in_transit' => 'In transit',
    'cash_in_hand' => 'Cash in hand',
    'mfs_balance' => 'MFS',
    'bank_balance' => 'In the bank',
    'draft_vouchers' => 'Draft vouchers',
    'pending_transfers' => 'Transfers awaiting receipt',

    // The engine-shaped dashboard (2 September 2026)
    'title' => 'Dashboard',
    'subtitle' => 'Where the money sits, who owes whom, and what moved this month',

    'cash_in_hand_hint' => 'Across every open cash till',
    'bank_balance_hint' => 'The balance of every account marked as a bank',
    'receivable' => 'Receivable',
    'receivable_hint' => 'What customers still owe',
    'payable' => 'Payable',
    'payable_hint' => 'What suppliers are still owed',

    'month_so_far' => 'This month so far',
    'month_so_far_hint' => 'From the first of the month to today',
    'income' => 'Income',
    'expense' => 'Expense',

    'needs_finishing' => 'Left as a draft',
    'nothing_draft' => 'No draft vouchers',
    'transfers_waiting' => 'Transfers awaiting receipt',
    'no_transfers_waiting' => 'No transfer is left hanging',

    'document' => 'Document',
    'type' => 'Type',
    'amount' => 'Amount',
    'trial_balance' => 'Trial balance — to date',
    'total_debit' => 'Total debit',
    'total_credit' => 'Total credit',
    'trial_balance_ok' => 'Debit equals credit — the books agree ✓',
    'trial_balance_off' => '⚠️ Out of balance by :gap',
    'papers_this_month' => 'Vouchers this month',
    'papers_draft' => 'Draft (not in the books)',
    'papers_posted' => 'Posted',
    'papers_cancelled' => 'Cancelled',
    'papers_this_month_hint' => 'Drafts should be down to zero by day end',
    'income_expense_months' => 'Income and expense — last six months',
    // 5 Oct 2026 — book health and current position
    'net_profit_month' => 'Net profit this month',
    'net_profit_month_hint' => 'Income minus expense — from the 1st of the month to today',
    'posted_today' => 'Vouchers posted today',
    'posted_today_hint' => 'Reached the books today, whatever the paper date',
    'reversed_this_month' => 'Entries reversed this month',
    'reversed_this_month_hint' => 'Papers reversed by a cancel or an edit — each paper once',
    'backdated_this_month' => 'Backdated vouchers',
    'backdated_this_month_hint' => 'Written this month but dated before the day they were written',
    'tills_below_zero' => 'Tills below zero',
    'tills_below_zero_hint' => 'Cash tills holding less than nothing — cash in hand cannot',
    'awaiting_signature' => 'Vouchers awaiting signature',
    'awaiting_signature_hint' => 'They reach the books only once approved',
    'current_position' => 'Current position — to date',
    'current_assets' => 'Current assets',
    'current_liabilities' => 'Current liabilities',
    'net_assets' => 'Net assets',
    'current_position_hint' => 'Working capital (current assets − current liabilities): :working · Net assets = total assets − total liabilities',
    // ⓘ হোমের নতুন রূপ (পরিকল্পনা ২, ৫ অক্টোবর ২০২৬)
    'kpi_receivable' => 'Owed by customers',
    // 5 Oct 2026 — first chart: total income, total expense, net profit; follows the home period
    'today_so_far' => 'Income and expense today',
    'year_so_far' => 'This year so far',
    'total_income' => 'Total income',
    'total_expense' => 'Total expense',
    'net_profit' => 'Net profit',
    'income_expense_profit_hint' => 'Net profit = total income − total expense; negative means a loss',
];
