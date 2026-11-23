<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Models\Approval;

/**
 * ⭐ অনুরোধকারী নিজের অনুরোধ ফিরিয়ে নিলেন — অনুমোদনের কেন্দ্রের সাধারণ "ফিরিয়ে নিন" ([[ApprovalEngine::cancel()]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ [[ApprovalDecided]]-এর যমজ, লেনদেন পাকা হওয়ার পরে। ⛔ আগে ফেরানোর কোনো খবর ছিল না — কাগজের মডিউল জানত না, তাই নিজের
 * সারি (যেমন বেতনের ঠিকানার অপেক্ষার বদল) "অপেক্ষায়" থেকে যেত, অথচ সইয়ের অনুরোধ আর নেই।
 */
final class ApprovalWithdrawn extends DomainEvent
{
    public static function from(Approval $approval): self
    {
        return new self(
            publicId: (string) $approval->public_id,
            payload: [
                'approval_id' => (int) $approval->id,
                'status' => (string) $approval->status,
                'module' => (string) $approval->module,
                'action' => (string) $approval->action,
                'approvable_type' => (string) $approval->approvable_type,
                'approvable_id' => (int) $approval->approvable_id,
            ],
            companyId: (int) $approval->company_id,
        );
    }
}
