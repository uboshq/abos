<?php

declare(strict_types=1);

/* আদায়ের সূচি — রিপোর্ট সেন্টার ধাপ ৪ ([[CollectionDueReport]], ২ অক্টোবর ২০২৬) */

return [
    'title' => 'Collections due',
    'code' => 'Code',
    'customer' => 'Customer',
    'overdue' => 'Overdue',
    'today' => 'Today',
    'week' => 'Next 7 days',
    'month' => 'Rest of this month',
    'total' => 'Total due',
    'limit' => 'Credit limit',
    'used' => 'Limit used %',
    // ⛔ the due date stays within the customer's credit days ([[DueWithinTerms]])
    'beyond_terms' => 'This customer is allowed :days credit days (last date :latest), but the due date asked is :due, :asked days from the bill date. To give more days, raise the customer credit days (needs the owner signature).',
];
