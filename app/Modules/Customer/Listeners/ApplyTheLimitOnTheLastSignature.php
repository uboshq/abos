<?php

declare(strict_types=1);

namespace App\Modules\Customer\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বাকির সীমা বাড়ানোর শেষ সই পড়ল — নতুন সীমাটা নিজে বসে। ২ অক্টোবর ২০২৬।
 *
 * ⓘ আগে সই পড়ার পরে কাউকে ফিরে এসে আবার "সংরক্ষণ" চাপতে হত — ৪১৪ জনের সীমায় সেটা ৪১৪ বার। অঙ্কটা অনুরোধেই বাঁধা
 * ([[CustomerService::assertRaiseIsSigned()]] `amount` বসায়, আর সইকারী ঠিক সেটাই দেখে সই দেন), তাই বসানো হয় ঠিক
 * সেই অঙ্ক — বেশিও নয়, কমও নয়। "না" হলে কিছুই হয় না।
 */
final class ApplyTheLimitOnTheLastSignature
{
    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['module'] ?? null) !== 'customer'
            || ($event->payload['action'] ?? null) !== 'credit_limit'
            || ($event->payload['status'] ?? null) !== Approval::APPROVED) {
            return;
        }

        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null || $approval->amount === null) {
            return;
        }

        DB::transaction(function () use ($approval): void {
            $customer = Customer::query()->withoutGlobalScopes()
                ->where('company_id', $approval->company_id)
                ->whereKey($approval->approvable_id)
                ->lockForUpdate()
                ->first();

            if ($customer === null) {
                return;
            }

            $customer->forceFill(['credit_limit' => (string) $approval->amount])->save();
        });
    }
}
