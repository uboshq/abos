<?php

declare(strict_types=1);

return [
    'risk_points_overdue_age' => 'Age of the oldest overdue bill — points per started 30 days (0 = off)',
    'risk_points_overdue_share' => 'Overdue amount at half the effective limit or more — points',
    'risk_points_late_beyond_terms' => 'Late beyond the credit days agreed with the customer — points',
    'risk_points_wall_stop' => 'Each wall stop in the last 90 days — points (up to four)',
    'risk_points_bounced_cheque' => 'Each bounced cheque in the last 180 days — points (up to two)',
    'risk_points_block' => 'Each credit block in the last 365 days — points (up to two)',
    'risk_medium_at' => 'Medium risk from this score',
    'risk_high_at' => 'High risk from this score',
    'risk_critical_at' => 'Critical risk from this score',
];
