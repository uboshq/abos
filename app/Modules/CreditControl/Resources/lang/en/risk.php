<?php

declare(strict_types=1);

return [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'critical' => 'Critical',
    'title' => 'Risk rating',
    'score' => 'Score',
    'reasons' => 'Reasons',
    'no_reason' => 'No signs',
    'informs' => 'The rating only informs — it never changes a limit or the wall.',
    'reason_overdue_age' => 'Oldest bill still unpaid :days days past its due date',
    'reason_overdue_share' => 'Overdue :amount — half the limit :limit or more',
    'reason_late_beyond_terms' => ':days days past due — beyond the :terms credit days agreed with the customer',
    'reason_wall_stops' => 'Stopped at the wall :n times in the last 90 days',
    'reason_bounced_cheques' => ':n bounced cheque(s) in the last 180 days',
    'reason_blocks' => 'Credit blocked :n time(s) in the last 365 days',
];
