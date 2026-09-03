<?php

declare(strict_types=1);

/*
 * What this module's approvable actions are called in the flow builder.
 *
 * Only the module knows the name — ApprovalFlowService::labels() turns
 * "hr · payroll" into human words from here.
 */

return [
    'payroll' => 'Confirming a payroll run',
];
