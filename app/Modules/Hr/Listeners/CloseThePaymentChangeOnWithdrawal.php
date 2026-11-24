<?php

declare(strict_types=1);

namespace App\Modules\Hr\Listeners;

use App\Core\Events\ApprovalWithdrawn;
use App\Models\Approval;
use App\Modules\Hr\Services\PaymentChanges;

/**
 * ⭐ বেতনের ঠিকানা বদলের অনুরোধ অনুমোদনের কেন্দ্র থেকে ফিরিয়ে নেওয়া হলো — অপেক্ষার বদলটাও বন্ধ, অডিটে; কর্মীর সারিতে আগের তথ্য
 * (fe, ২১ অক্টোবর ২০২৬; a6; [[PaymentChanges::closeWithdrawn()]], [[TheSalaryWentWhereOneHandSaidTest]])।
 */
final class CloseThePaymentChangeOnWithdrawal
{
    public function handle(ApprovalWithdrawn $event): void
    {
        $payload = $event->payload;

        if (($payload['module'] ?? null) !== PaymentChanges::MODULE || ($payload['action'] ?? null) !== PaymentChanges::ACTION) {
            return;
        }

        $approval = Approval::query()->find($payload['approval_id'] ?? null);

        if ($approval !== null) {
            app(PaymentChanges::class)->closeWithdrawn($approval);
        }
    }
}
