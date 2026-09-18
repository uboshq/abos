<?php

declare(strict_types=1);

/*
 * অনুমোদনের ছকে এই মডিউলের কাজগুলো কী নামে দেখা যাবে।
 *
 * নামটা কেবল মডিউলই জানে — `ApprovalFlowService::labels()` এখান থেকেই
 * "inventory · transfer"-কে মানুষের ভাষায় বদলায়।
 */

return [
    'count' => 'মজুদ গণনার পার্থক্য মেনে নেওয়া',
    'transfer' => 'গুদাম বদলে মাল রওনা',
];
