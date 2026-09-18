<?php

declare(strict_types=1);

/*
 * What this module's approvable actions are called in the flow builder.
 *
 * Only the module knows the name — ApprovalFlowService::labels() turns
 * "inventory · transfer" into human words from here.
 */

return [
    'count' => 'Accepting a stock-count difference',
    'transfer' => 'Dispatching a stock transfer',
];
