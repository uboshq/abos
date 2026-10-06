<?php

declare(strict_types=1);

/** Customer rows on the home screen. Keys must match bn. */
return [
    'over_limit' => 'Over their credit limit',
    'receivable_over' => 'Total receivable (limit :limit)',
    'kpi_owed' => 'Owed by customers',
    'kpi_owed_advance' => 'Advance :amount',

    'title' => 'Dashboard',
    'subtitle' => 'The customer list — where it stands',
    'total' => 'Customers',
    'total_hint' => 'Active and inactive together',
    'active' => 'Active',
    'active_hint' => 'Still trading with us',
    'inactive' => 'Inactive',
    'inactive_hint' => 'Switched off, not deleted — the history stays',
    'new_this_month' => 'New this month',
    'new_hint' => 'Added this month',
    'newest' => 'Recently added',
    'none' => 'No customers yet.',

    'growth' => 'Customer growth — last six months',
    'growth_added' => 'Added',
    'growth_still_on' => 'Still active',
    'ageing' => 'Receivable ageing — customers',
    'ageing_hint' => 'Total owed :total — the ageing report\'s own figures',
    'sales_month' => 'Sales this month',
    'sales_month_hint' => ':count posted invoices — the same figure as the sales screen',
    'average_order' => 'Average order value',
    'average_order_hint' => 'Sales this month divided by the number of posted invoices',
    'overdue' => 'Overdue receivable',
    'overdue_hint' => 'Past the due date and still unpaid — posted invoices',
    'overdue_customers' => 'Customers overdue',
    'overdue_customers_hint' => 'Customers with at least one unpaid invoice past its due date',
    'overdue_by_customer' => 'Overdue receivable — by customer',
    'overdue_by_customer_hint' => 'Invoice due date, or the invoice date when none is set — the collection schedule rule',
    'segments' => 'Active customers — by type',
    'segments_hint' => 'Split by customer type — adds up to the active count above',
    'segments_none' => 'No type set',
    'top_buyers' => 'Top five buyers this month',
    'top_buyers_sold' => 'Bought this month',
    'top_buyers_due' => 'Outstanding',
    'top_buyers_none' => 'No posted invoices yet this month.',
];
