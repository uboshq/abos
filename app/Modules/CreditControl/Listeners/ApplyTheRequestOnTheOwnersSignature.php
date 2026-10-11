<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Modules\CreditControl\Models\CreditLimitRequest;
use App\Modules\CreditControl\Services\CreditLimitRequests;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধে মালিকের সিদ্ধান্ত — "হ্যাঁ" হলে ঠিক চাওয়া অঙ্কটা বসে, "না" হলে কেবল অবস্থা (বাকি নিয়ন্ত্রণ ধাপ ২.৫)।
 *
 * ⓘ শোনে কেবল এই কাগজের সই ([[CreditLimitRequest]]) — গ্রাহকের সারিতে বসা পুরনো সই শোনে [[ApplyTheLimitOnTheLastSignature]]।
 * ⓘ ঘোষণা আসে লেনদেন পাকা হলে ([[ApprovalEngine::approve()]]); কাজটা [[CreditLimitRequests::settle()]]-এ, যে নিজে দুবার করে না।
 */
final class ApplyTheRequestOnTheOwnersSignature
{
    public function __construct(private readonly CreditLimitRequests $requests) {}

    public function handle(ApprovalDecided $event): void
    {
        $p = $event->payload;

        if (($p['approvable_type'] ?? null) !== (new CreditLimitRequest)->getMorphClass()
            || ! in_array($p['status'] ?? null, [Approval::APPROVED, Approval::REJECTED], true)) {
            return;
        }

        $approval = Approval::query()->withoutGlobalScopes()->find((int) ($p['approval_id'] ?? 0));

        if ($approval === null) {
            return;
        }

        CompanyContext::forCompany((int) $approval->company_id,
            fn () => $this->requests->settle((int) $p['approvable_id'], $approval));
    }
}
