<?php

declare(strict_types=1);

namespace App\Modules\Customer\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
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
        /*
         * ⛔ কেবল গ্রাহকের নিজের সারির সই — ১১ অক্টোবর ২০২৬ থেকে একই ছকে সীমা বাড়ানোর **অনুরোধের কাগজও** আসে
         * ([[CustomerCreditRequest]]), যার আইডি গ্রাহকের নয়; না দেখলে অনুরোধ #৭-এর সইয়ে গ্রাহক #৭-এর সীমা বসত।
         * ⓘ অনুরোধ বসায় [[ApplyTheSignedCreditRequest]]।
         */
        if (($event->payload['module'] ?? null) !== 'customer'
            || ($event->payload['action'] ?? null) !== 'credit_limit'
            || ($event->payload['approvable_type'] ?? null) !== Customer::class
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

            // ⭐ ইতিহাসে কারণ আর সইয়ের সূত্রসহ (বাকি নিয়ন্ত্রণ ধাপ ১.৪)
            CustomerCreditChange::because(
                $approval->requested_reason,
                (int) $approval->id,
                fn () => $customer->forceFill(['credit_limit' => (string) $approval->amount])->save(),
            );

            // ⛔ সই একবারই খাটে — সীমা নামিয়ে আবার একই অঙ্কে তুললে নতুন সই ([[Approval::markUsed()]])
            $approval->markUsed();
        });
    }
}
