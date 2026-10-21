<?php

declare(strict_types=1);

namespace App\Modules\Hr\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Hr\Services\SalaryStructureService;

/**
 * ⭐ বেতনের কাঠামো বদলের শেষ সই — প্রস্তাবটা এবার বসে; "প্রত্যাখ্যাত" হলে কিছুই বদলায় না (§৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬;
 * [[SalaryStructureService::change()]], [[ASalaryChangeNeedsASecondHandTest]])।
 *
 * ⓘ খরচের দাবির শ্রোতার একই ধাঁচ ([[FinishTheClaimOnTheLastSignature]]) — কোর কেবল ঘোষণা করে, মডিউল নিজের কাজ নিজে শোনে।
 */
final class FinishTheSalaryChangeOnTheLastSignature
{
    public function handle(ApprovalDecided $event): void
    {
        $payload = $event->payload;

        if (($payload['module'] ?? null) !== SalaryStructureService::MODULE
            || ($payload['action'] ?? null) !== SalaryStructureService::ACTION_CHANGE) {
            return;
        }

        $approval = Approval::query()->find($payload['approval_id'] ?? null);

        // ⓘ "প্রত্যাখ্যাত" বা আর কিছু হলে সেবা নিজেই থামে ([[SalaryStructureService::applySigned()]] কেবল অনুমোদিত, না-খাটানো সই নেয়)
        if ($approval !== null) {
            app(SalaryStructureService::class)->applySigned($approval);
        }
    }
}
