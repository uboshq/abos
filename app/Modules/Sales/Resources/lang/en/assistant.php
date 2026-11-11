<?php

declare(strict_types=1);

// ⭐ কোপাইলটের দরজার কথা (১১ নভেম্বর ২০২৬)
return [
    'net' => 'Net sales',
    'bills' => 'Bills',
    'collected' => 'Collected',
    'summary' => ':period net sales Tk :net (:count bills), collected Tk :collected',
    'period' => ['today' => 'Today', 'yesterday' => 'Yesterday', 'month' => 'This month', 'year' => 'This financial year'],
    'source_dashboard' => 'Sales dashboard',
    'need_expected' => 'Expected demand',
    'need_have' => 'On hand',
    'need_buy' => 'To buy',
    'need_confidence' => 'Confidence',
    'confidence' => ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'],
    'accuracy' => 'last week matched :pct%',
    'need_summary' => 'Over the next :days days :product about :qty (:low–:high). On hand :have — buy about :buy.',
    'need_how' => 'Basis: last :days days of sales, weekday pattern; festivals not counted',
];
