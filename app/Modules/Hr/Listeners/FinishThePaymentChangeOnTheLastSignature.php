<?php

declare(strict_types=1);

namespace App\Modules\Hr\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Hr\Services\PaymentChanges;

/**
 * ⭐ বেতনের ঠিকানা বদলের শেষ সই — বদলটা এবার কর্মীর সারিতে বসে; "প্রত্যাখ্যাত" হলে সারিটা বন্ধ, কর্মী জানেন (মালিকের ব্যতিক্রম,
 * a6, ২১ অক্টোবর ২০২৬; [[PaymentChanges::finishSigned()]], [[TheSalaryWentWhereOneHandSaidTest]])।
 *
 * ⓘ সই ইনবক্স থেকে হোক বা কর্মীর পাতার বোতাম থেকে — একই অনুরোধ, একই শ্রোতা।
 */
final class FinishThePaymentChangeOnTheLastSignature
{
    public function handle(ApprovalDecided $event): void
    {
        $payload = $event->payload;

        if (($payload['module'] ?? null) !== PaymentChanges::MODULE || ($payload['action'] ?? null) !== PaymentChanges::ACTION) {
            return;
        }

        $approval = Approval::query()->find($payload['approval_id'] ?? null);

        if ($approval !== null) {
            app(PaymentChanges::class)->finishSigned($approval);
        }
    }
}
