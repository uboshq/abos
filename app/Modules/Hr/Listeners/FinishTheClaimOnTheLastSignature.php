<?php

declare(strict_types=1);

namespace App\Modules\Hr\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\ExpenseClaimService;

/**
 * ⭐ খরচের দাবি বা অগ্রিম অনুরোধের শেষ সই — অনুমোদিত আর খসড়া ভাউচার; "না" হলে ফেরানো (মালিকের আদেশ, ৭ অক্টোবর ২০২৬;
 * [[ExpenseClaimService]])। ⓘ অর্থের শ্রোতার একই ধাঁচ ([[FinishTheFinancePaperOnTheLastSignature]])।
 */
final class FinishTheClaimOnTheLastSignature
{
    public function handle(ApprovalDecided $event): void
    {
        $payload = $event->payload;

        if (($payload['module'] ?? null) !== ExpenseClaimService::MODULE
            || ! in_array($payload['action'] ?? null, ExpenseClaim::ACTIONS, true)) {
            return;
        }

        $approval = Approval::query()->find($payload['approval_id'] ?? null);

        if ($approval === null || ! in_array($approval->status, [Approval::APPROVED, Approval::REJECTED], true)) {
            return;
        }

        $claim = $approval->approvable;

        if (! $claim instanceof ExpenseClaim) {
            return;
        }

        if ($approval->status === Approval::APPROVED) {
            app(ExpenseClaimService::class)->noteTheRequesterSigned($claim, $approval);
            app(ExpenseClaimService::class)->approve($claim);

            return;
        }

        app(ExpenseClaimService::class)->refuse($claim);
    }
}
