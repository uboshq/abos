<?php

declare(strict_types=1);

return [
    'bill_total' => 'A bill total matches its lines',
    'bill_total_q' => 'Whether the stored total equals the sum of the bill lines.',
    'bill_total_broken' => 'When they differ the supplier is paid the wrong amount — and overpaying is not easy to claw back.',

    'unposted' => 'Confirmed bills reached the ledger',
    'unposted_q' => 'Whether any confirmed purchase bill is sitting there with no posting.',
    'unposted_broken' => 'Payables read low — the company owes more than it believes it owes.',

    'total_detail' => 'On the bill :stored · lines add to :lines',
    'unposted_detail' => ':amount bill with no posting',
];
