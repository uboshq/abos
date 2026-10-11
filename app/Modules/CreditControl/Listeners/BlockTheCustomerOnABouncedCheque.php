<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Listeners;

use App\Core\Services\NotificationService;
use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Events\ChequeBounced;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ চেক ফেরত গেল — গ্রাহকের বাকি নিজেই বন্ধ, আর মালিককে খবর (বাকি নিয়ন্ত্রণ ধাপ ২.৪, মালিকের উত্তর ৪, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ কেবল গ্রাহকের দেওয়া চেক (`received`, পক্ষ গ্রাহক) — আমাদের দেওয়া চেক বা সরবরাহকারীর টাকায় গ্রাহকের কিছু নেই।
 * ⓘ বন্ধটা চেক ফেরতের একই লেনদেনে বসে ([[ChequeBounced]]); খবর যায় লেনদেন পাকা হলে — রোলব্যাকে মুছে যাওয়া ফেরতের খবর
 * মালিকের কাছে যায় না। ⓘ একই চেক দুবার এলে বন্ধও একবার, খবরও একবার ([[CustomerService::blockForBouncedCheque()]])।
 * ⛔ খোলেন কেবল মালিক — টাকা দিলেও না, সময় গেলেও না ([[CustomerService::clearCreditBlock()]])।
 */
final class BlockTheCustomerOnABouncedCheque
{
    /** খবরের ধরন — [[NotificationKinds]]-এ নাম লেখা, যাতে কেউ চাইলে বন্ধ রাখতে পারেন */
    public const NOTICE = 'credit_control.cheque_block';

    public function __construct(
        private readonly CustomerService $customers,
        private readonly NotificationService $notices,
        private readonly Ownership $owners,
    ) {}

    public function handle(ChequeBounced $event): void
    {
        $p = $event->payload;

        if (($p['direction'] ?? null) !== 'received' || ($p['party_type'] ?? null) !== 'customer' || empty($p['party_id'])) {
            return;
        }

        $companyId = (int) $p['company_id'];
        $customerId = (int) $p['party_id'];
        $chequeNo = (string) ($p['cheque_no'] ?? '');

        if (! $this->customers->blockForBouncedCheque($customerId, $companyId, $chequeNo)) {
            return;
        }

        DB::afterCommit(fn () => $this->tellTheOwners($companyId, $customerId, $chequeNo, (string) ($p['amount'] ?? '0')));
    }

    private function tellTheOwners(int $companyId, int $customerId, string $chequeNo, string $amount): void
    {
        CompanyContext::forCompany($companyId, function () use ($companyId, $customerId, $chequeNo, $amount): void {
            $owners = $this->owners->activeOwnersIn($companyId);

            if ($owners->isEmpty()) {
                return;
            }

            $customer = \App\Modules\Customer\Models\Customer::query()->withoutGlobalScopes()->find($customerId);
            $name = (string) ($customer?->name() ?? '');

            $this->notices->sendMany(
                $owners,
                self::NOTICE,
                __('credit_control::notice.cheque_block_title', ['customer' => $name], 'bn'),
                __('credit_control::notice.cheque_block_body', ['no' => $chequeNo, 'amount' => \App\Core\Support\Money::format($amount)], 'bn'),
                route('credit_control.customer.show', $customerId),
                priority: 'high',
                // ⓘ একই চেকের খবর একবারই
                key: 'cheque_block:'.$customerId.':'.$chequeNo,
                about: $customer,
                data: array_filter(['party' => $name, 'paper_no' => $chequeNo, 'amount' => \App\Core\Support\Money::format($amount)]),
            );
        });
    }
}
