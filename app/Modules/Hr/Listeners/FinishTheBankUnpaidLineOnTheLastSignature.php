<?php

declare(strict_types=1);

namespace App\Modules\Hr\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Events\ApprovalWithdrawn;
use App\Models\Approval;
use App\Modules\Hr\Services\HeldWages;

/**
 * ⭐ "ব্যাংক এই লাইন দেয়নি"-র শেষ সই — মজুরি আবার দেওয়ার তালিকায়, একবারই; "না" বা "ফিরিয়ে নিন" হলে লাইন বন্ধ, মজুরির সারি যেমন ছিল
 * (fe-র সিদ্ধান্ত ২, ২১ অক্টোবর ২০২৬; [[HeldWages::finishBankUnpaid()]], [[ACancelledRunsBankLineIsNotReleasedByItselfTest]])।
 *
 * ⓘ সই ইনবক্স থেকে হোক বা বকেয়া মজুরির পাতার বোতাম থেকে — একই অনুরোধ, একই শ্রোতা।
 */
final class FinishTheBankUnpaidLineOnTheLastSignature
{
    public function handle(ApprovalDecided|ApprovalWithdrawn $event): void
    {
        $payload = $event->payload;

        if (($payload['module'] ?? null) !== HeldWages::UNPAID_MODULE || ($payload['action'] ?? null) !== HeldWages::UNPAID_ACTION) {
            return;
        }

        $approval = Approval::query()->find($payload['approval_id'] ?? null);

        if ($approval === null) {
            return;
        }

        if ($event instanceof ApprovalWithdrawn) {
            app(HeldWages::class)->closeWithdrawnUnpaid($approval);

            return;
        }

        app(HeldWages::class)->finishBankUnpaid($approval);
    }
}
