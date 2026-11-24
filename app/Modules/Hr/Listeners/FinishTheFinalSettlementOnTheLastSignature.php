<?php

declare(strict_types=1);

namespace App\Modules\Hr\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Events\ApprovalWithdrawn;
use App\Models\Approval;
use App\Modules\Hr\Services\FinalSettlements;

/**
 * ⭐ চূড়ান্ত পাওনার শেষ সিদ্ধান্ত — মালিকের অনুমোদনে খাতায়, "না" হলে কাগজ বন্ধ; অনুমোদনের কেন্দ্র থেকে ফিরিয়ে নিলেও বন্ধ
 * (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlements::finishSigned()]], [[ALeaverIsSettledOnOnePaperTest]])।
 */
final class FinishTheFinalSettlementOnTheLastSignature
{
    public function handle(ApprovalDecided|ApprovalWithdrawn $event): void
    {
        $payload = $event->payload;

        if (($payload['module'] ?? null) !== FinalSettlements::MODULE || ($payload['action'] ?? null) !== FinalSettlements::ACTION) {
            return;
        }

        $approval = Approval::query()->find($payload['approval_id'] ?? null);

        if ($approval === null) {
            return;
        }

        $event instanceof ApprovalWithdrawn
            ? app(FinalSettlements::class)->closeWithdrawn($approval)
            : app(FinalSettlements::class)->finishSigned($approval);
    }
}
