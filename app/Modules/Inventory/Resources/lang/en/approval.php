<?php

declare(strict_types=1);

/*
 * What this module's approvable actions are called in the flow builder.
 *
 * Only the module knows the name — ApprovalFlowService::labels() turns
 * "inventory · transfer" into human words from here.
 */

return [
    'count' => 'Stock count or adjustment (accepting the difference)',
    'issue' => 'Goods given out without a sale (hospitality, gifts, use by the owner)',
    'transfer' => 'Dispatching a stock transfer',
];
