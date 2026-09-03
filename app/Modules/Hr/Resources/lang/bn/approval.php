<?php

declare(strict_types=1);

/*
 * অনুমোদনের ছকে এই মডিউলের কাজগুলো কী নামে দেখা যাবে।
 *
 * নামটা কেবল মডিউলই জানে — `ApprovalFlowService::labels()` এখান থেকেই
 * "hr · payroll"-কে মানুষের ভাষায় বদলায়।
 */

return [
    'payroll' => 'বেতনের রান নিশ্চিত করা',
];
