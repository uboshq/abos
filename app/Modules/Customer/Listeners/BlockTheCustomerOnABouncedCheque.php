<?php

declare(strict_types=1);

namespace App\Modules\Customer\Listeners;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Events\ChequeBounced;
use App\Modules\Customer\Services\CustomerService;

/**
 * ⭐ গ্রাহকের চেক ফেরত এলো — তাঁর নতুন বাকি নিজে থেকেই বন্ধ (মালিকের উত্তর ৪, ১১ অক্টোবর ২০২৬; বাকি নিয়ন্ত্রণ ধাপ ২.৪)।
 *
 * ⓘ কেবল গৃহীত চেক, পক্ষ গ্রাহক — দেওয়া চেক বা সরবরাহকারীর চেকে গ্রাহকের কিছু নেই। ⓘ কোম্পানি ঘটনার নিজের, প্রসঙ্গের নয়।
 * ⛔ খোলেন কেবল মালিক ([[CustomerService::clearCreditBlock()]]); টাকা এলেও এই বন্ধ ওঠে না।
 */
final class BlockTheCustomerOnABouncedCheque
{
    public function __construct(private readonly CustomerService $customers) {}

    public function handle(ChequeBounced $event): void
    {
        $p = $event->payload;

        if (($p['direction'] ?? null) !== 'received' || ($p['party_type'] ?? null) !== 'customer' || empty($p['party_id'])) {
            return;
        }

        CompanyContext::forCompany($event->companyId, fn () => $this->customers->blockForBouncedCheque(
            (int) $p['party_id'],
            (string) ($p['cheque_no'] ?? ''),
            (int) ($p['cheque_id'] ?? 0),
        ));
    }
}
