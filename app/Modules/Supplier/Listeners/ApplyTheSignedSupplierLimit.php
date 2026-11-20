<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Modules\Supplier\Models\SupplierCreditRequest;
use App\Modules\Supplier\Services\SupplierLimitRequests;

/**
 * ⭐ সরবরাহকারীর সীমা বাড়ানোর অনুরোধে শেষ সিদ্ধান্ত — "হ্যাঁ" হলে নতুন সীমা নিজে বসে, "না" হলে অনুরোধ থাকে "না" হয়ে
 * (মালিক, ১৮ নভেম্বর ২০২৬; [[SupplierLimitRequests::applySigned()]])।
 *
 * ⓘ ইঞ্জিন ঘটনাটা ছোড়ে কেবল শেষ সিদ্ধান্তে (সব ধাপের সই, বা যেকোনো "না") — মাঝের ধাপের সইয়ে কিছুই বসে না।
 */
final class ApplyTheSignedSupplierLimit
{
    public function __construct(private readonly SupplierLimitRequests $requests) {}

    public function handle(ApprovalDecided $event): void
    {
        $p = $event->payload;

        if (($p['module'] ?? null) !== SupplierLimitRequests::MODULE
            || ($p['action'] ?? null) !== SupplierLimitRequests::ACTION
            || ($p['approvable_type'] ?? null) !== SupplierCreditRequest::class
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
