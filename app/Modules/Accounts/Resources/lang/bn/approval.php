<?php

declare(strict_types=1);

/*
 * অনুমোদনের ছকে এই মডিউলের কাজগুলো কী নামে দেখা যাবে।
 *
 * নামটা কেবল মডিউলই জানে — `ApprovalFlowService::labels()` এখান থেকেই
 * "accounts · expense"-কে মানুষের ভাষায় বদলায়।
 */

return [
    'expense' => 'খরচের ভাউচার',
    'receipt' => 'আদায় ভাউচার',
    'payment' => 'পরিশোধ ভাউচার',
    'journal' => 'জাবেদা ভাউচার',
    'contra' => 'কন্ট্রা ভাউচার',
];
