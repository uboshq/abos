<?php

declare(strict_types=1);

namespace App\Modules\Hr\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Support\OwnerSelfSigned;

/**
 * ⛔ মালিক নিজের চাওয়া বেতনের রানে নিজেই "সই দিন" চাপলেন — রানের অডিটে `owner_self_signed` (মালিকের ব্যতিক্রম, a6, ২১ অক্টোবর ২০২৬;
 * [[OwnerSelfSigned]], [[APayrollRunNeedsASecondHandEvenWithApprovalsOffTest]])।
 *
 * ⓘ রানটা সইয়ে নিজে পোস্ট হয় না — সই পড়ার পর "নিশ্চিত" চাপলে খাতায় বসে ([[PayrollService::confirm()]]); তাই চিহ্নটা সইয়ের মুহূর্তে,
 * সই যে কাগজে পড়ল তার অডিটে।
 */
final class MarkTheOwnersOwnPayrollSignature
{
    public function handle(ApprovalDecided $event): void
    {
        $payload = $event->payload;

        if (($payload['module'] ?? null) !== 'hr' || ($payload['action'] ?? null) !== 'payroll') {
            return;
        }

        $approval = Approval::query()->find($payload['approval_id'] ?? null);

        if ($approval === null || $approval->status !== Approval::APPROVED) {
            return;
        }

        $run = $approval->approvable;

        if ($run instanceof PayrollRun) {
            OwnerSelfSigned::mark($approval, $run, __('hr::message.owner_self_signed_payroll'));
        }
    }
}
