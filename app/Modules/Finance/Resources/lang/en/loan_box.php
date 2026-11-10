<?php

declare(strict_types=1);

// ⭐ The loan box at the bottom of the finance dashboard (the owner's approved sample, 9 Nov 2026). [[LoanBox]]
return [
    'title' => 'Loans',
    'down' => '▼ Bank loans :amount less than last month',
    'up' => '▲ Bank loans :amount more than last month',
    'same' => 'Bank loans same as last month',
    'all' => 'See all loans →',
    'next' => 'Next instalment',
    'next_hint' => ':name :kind · :days days left',
    'next_today' => ':name :kind · today',
    'next_late' => ':name :kind · :days days late',
    'next_none' => 'No instalment ahead',
    'interest' => "This month's interest",
    'avg_rate' => 'Average rate :rate%',
    'od' => 'Credit line used',
    'od_hint' => 'OD :used / limit :limit',
    'od_none' => 'No running OD/CC',
    'de' => 'Debt / equity',
    'de_hint' => 'Below 1 is good',
    'dscr' => 'Instalment cover',
    'dscr_value' => ':n times',
    'dscr_hint' => "Last month's net income + interest ÷ this month's instalments · 1.25+ good",
    'dscr_none' => 'No instalment this month',
    'lender' => 'Lender',
    'kind' => 'Kind',
    'left' => 'Left',
    'rate' => 'Rate',
    'next_col' => 'Next instalment',
    'month_end_interest' => 'Interest at month end',
    'hand' => 'Hand loan',
    'hand_people' => 'Hand loans (:n people)',
    'hand_late' => ':n people past their return day',
    'none' => 'No loans',
    'share' => 'Share by lender',
];
