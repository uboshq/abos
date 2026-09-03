<?php

declare(strict_types=1);

/*
 * What this module's approvable actions are called in the flow builder.
 *
 * Only the module knows the name — ApprovalFlowService::labels() turns
 * "purchase · order" into human words from here.
 */

return [
    'order' => 'Confirming a purchase order',
    'bill' => 'Posting a purchase bill',
    'payment' => 'Paying a supplier',
    'return' => 'Purchase return',
];
