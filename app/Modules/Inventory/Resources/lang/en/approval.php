<?php

declare(strict_types=1);

/*
 * What this module's approvable actions are called in the flow builder.
 *
 * Only the module knows the name — ApprovalFlowService::labels() turns
 * "inventory · transfer" into human words from here.
 */

return [
    'transfer' => 'Dispatching a stock transfer',
];
