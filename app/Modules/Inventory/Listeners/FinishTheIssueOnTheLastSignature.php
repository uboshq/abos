<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Services\StockCountService;

/**
 * ⭐ শেষ সই পড়লে "মাল বের করা" কাগজটা নিজেই শেষ হয় — Inventory অডিট গ৫, ৪ অক্টোবর ২০২৬ ([[StockCountService::issue()]])।
 *
 * ⓘ মালিকের সিদ্ধান্ত (২৭ সেপ্টেম্বর): শেষ সইয়ের পর কাউকে আবার চাপতে হবে না। ফেরত দিলে কাগজটা খসড়াই থাকে — যিনি
 * লিখেছেন তিনি গণনার পাতা থেকে বাতিল করেন ([[StockCountService::cancel()]])।
 */
final class FinishTheIssueOnTheLastSignature
{
    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['module'] ?? null) !== 'inventory' || ($event->payload['action'] ?? null) !== 'issue') {
            return;
        }

        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval?->status !== Approval::APPROVED) {
            return;
        }

        $paper = $approval->approvable;

        // ⓘ খসড়া থাকলেই — বাতিল হয়ে গেলে বা আগেই শেষ হলে কিছু হয় না
        if ($paper instanceof StockCount && $paper->isIssue() && $paper->status === DocumentStatus::DRAFT) {
            app(StockCountService::class)->finishIssue($paper);
        }
    }
}
