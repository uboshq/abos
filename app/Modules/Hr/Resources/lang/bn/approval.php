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
    'expense_claim' => 'কর্মীর খরচের দাবি',
    'cash_advance' => 'কর্মীর অগ্রিম অনুরোধ',
];
