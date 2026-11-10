<?php

declare(strict_types=1);

// ⭐ Finance dashboard, row 2 onwards — the owner's final sample 4h63xhNiy4m9KaJkZZQaEL (10 Nov 2026 night)

return [
    'lakh' => 'lakh',
    'crore' => 'cr',
    'months_short' => [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    'fc' => [
        'title' => '13-week cash forecast',
        'label' => 'Fund over the next 13 weeks and weekly net flow',
        'line' => 'Week-end fund',
        'up' => 'Rose in the week',
        'down' => 'Fell',
        'floor' => 'Safe floor :value',
        'today' => 'Today',
        'week' => 'W:n',
        'tight' => 'Week :n (:range) is the tightest',
        'tight_who' => ':who bill of :amount',
        'below' => 'The fund falls to :value — below the safe floor; push collections early or keep money from the credit line.',
        'above' => 'The fund falls to :value — above the safe floor.',
        'quiet' => 'No collection or payment is due in the next 13 weeks.',
        'steady' => 'The fund does not fall below today’s :value in the next 13 weeks.',
    ],
    'cf' => [
        'title' => 'Cash flow statement · this month (IAS 7)',
        'change' => 'Fund :value',
        'opening' => 'Fund at month start',
        'operating' => 'From operations',
        'investing' => 'Into investing',
        'financing' => 'From financing',
        'closing' => 'Fund today',
        'operating_short' => 'Operating',
        'investing_short' => 'Investing',
        'financing_short' => 'Financing',
        'nothing' => 'No movement this month',
        'unit' => 'in lakh',
    ],
];
