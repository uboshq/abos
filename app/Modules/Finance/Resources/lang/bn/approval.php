<?php

declare(strict_types=1);

/*
 * অনুমোদনের ছকে এই মডিউলের কাজগুলো কী নামে দেখা যাবে।
 *
 * নামটা কেবল মডিউলই জানে — `ApprovalFlowService::labels()` এখান থেকেই
 * "finance · withdrawal"-কে মানুষের ভাষায় বদলায়।
 */

return [
    'withdrawal' => 'মালিকের উত্তোলন',
];
