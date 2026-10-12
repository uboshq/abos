<?php

declare(strict_types=1);

namespace App\Modules\Customer\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Services\CreditLimitRequests;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধে শেষ সিদ্ধান্ত — "হ্যাঁ" হলে (মালিকের সইসহ) সীমা বসে, "না" হলে অনুরোধ থাকে "না" হয়ে
 * (মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬; বাকি নিয়ন্ত্রণ ধাপ ২.৫; [[CreditLimitRequests::applySigned()]])।
 *
 * ⓘ কেবল অনুরোধের কাগজ — গ্রাহকের ফর্মে সরাসরি বাড়ানোর সই আগের মতোই [[ApplyTheLimitOnTheLastSignature]] বসায়।
 */
final class ApplyTheSignedCreditRequest
{
    public function __construct(private readonly CreditLimitRequests $requests) {}

    public function handle(ApprovalDecided $event): void
    {
        $p = $event->payload;

        if (($p['module'] ?? null) !== 'customer'
            || ($p['action'] ?? null) !== 'credit_limit'
            || ($p['approvable_type'] ?? null) !== CustomerCreditRequest::class
            || ! in_array($p['status'] ?? null, [Approval::APPROVED, Approval::REJECTED], true)) {
            return;
        }

        CompanyContext::forCompany($event->companyId, function () use ($p): void {
            $approval = Approval::query()->find((int) ($p['approval_id'] ?? 0));

            if ($approval !== null) {
                $this->requests->applySigned($approval);
            }
        });
    }
}
