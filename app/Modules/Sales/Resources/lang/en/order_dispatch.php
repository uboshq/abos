<?php

declare(strict_types=1);

return [
    'title' => 'Order to dispatch time',
    'do' => 'DO',
    'invoice' => 'Invoice',
    'submitted_at' => 'Submitted',
    'approved_at' => 'Approved',
    'invoiced_at' => 'Invoice & challan',
    'hours_to_approve' => 'Submitted → approved (h)',
    'hours_to_invoice' => 'Approved → invoice (h)',
    'hours_to_gate' => 'Invoice → gate pass (h)',
    'hours_to_leave' => 'Gate pass → dispatch (h)',
    'hours_total' => 'Total (h)',
    'dispatched' => 'Dispatched',
    'summary' => 'Average time, submitted to dispatch',
    'summary_text' => 'Average :hours hours — :count DOs dispatched',
    'none_left' => 'No DO was dispatched in this period.',
];
