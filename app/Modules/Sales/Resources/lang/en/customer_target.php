<?php

declare(strict_types=1);

/* ডিলারের মাসিক আদায়ের লক্ষ্য ([[CustomerTargetService]], ৩ অক্টোবর ২০২৬) */

return [
    'title' => 'Dealer monthly targets',
    'subtitle' => 'Each dealer\'s collection target and closing date for the month, printed on the bill as the target reminder',
    'month' => 'Month',
    'dealer' => 'Dealer',
    'code' => 'Code',
    'target' => 'Target',
    'closes_on' => 'Closing date',
    'achieved' => 'Collected',
    'remaining' => 'Remaining',
    'saved' => 'Targets saved.',
    'nobody' => 'No active dealers.',
    'empty_means_none' => 'Blank or 0 means no target this month, and no box on the bill. A blank closing date means the last day of the month.',
    'import' => 'Dealer monthly targets',
    'no_such_dealer' => 'No dealer has the code :code.',
    'month_invalid' => 'The month was not understood. Write it like 2026-10.',
    'amount_invalid' => 'The target must be a positive number.',
    'closes_in_month' => 'The closing date must fall inside that month.',
    'month_too_far' => 'A target cannot be set more than two years ahead.',
];
