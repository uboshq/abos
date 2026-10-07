<?php

declare(strict_types=1);

/*
 * Credit and collections — the new-credit wall and its reports (5 Oct 2026; [[CreditExposure::stopsFor()]]).
 */
return [
    'overdue_stop' => 'Unpaid for more than :days days past due (:bills, :amount) — no new credit',
    'uncleared_cheques' => 'Cheques not yet cleared',
    'blocked_stop' => 'Credit is blocked for this customer (:reason) — a sale paid in full still goes through',
    // ── Credit and collections reports ([[CreditControlReports]]) ──
    'use_title' => 'Credit limit use',
    'blocked_title' => 'Credit-blocked customers',
    'risk_title' => 'Customers at risk',
    'history_title' => 'Credit limit change history',
    'code' => 'Code',
    'customer' => 'Customer',
    'limit' => 'Credit limit',
    'outstanding' => 'Outstanding',
    'held' => 'Held',
    'available' => 'Limit left',
    'used' => 'Used %',
    'reason' => 'Reason',
    'blocked_by' => 'Blocked by',
    'blocked_on' => 'Blocked on',
    'overdue_amount' => 'Overdue',
    'bounced_cheques' => 'Bounced cheques (90 days)',
    'over_limit' => 'Over the limit',
    'risk_flags' => 'Risk notes',
    'requested_on' => 'Asked on',
    'asked_limit' => 'Limit asked',
    'status' => 'Status',
    'requested_by' => 'Asked by',
    'decided_on' => 'Decided on',
    'status_pending' => 'Awaiting signature',
    'status_approved' => 'Approved',
    'status_rejected' => 'Rejected',
    'status_cancelled' => 'Cancelled',
];
