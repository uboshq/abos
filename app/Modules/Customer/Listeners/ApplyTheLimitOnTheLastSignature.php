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
            || ! in_array($event->payload['action'] ?? null, ['credit_limit', 'credit_days'], true)
            ||($event->payload['approvable_type'] ?? null) !== Customer::class
            || ($event->payload['status'] ?? null) !== Approval::APPROVED) {
            return;
        }

        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null || $approval->amount === null) {
            return;
        }

        /*
         * ⛔ মালিকের সই ছাড়া সীমা বসে না — D5 (পূর্ণ নিরীক্ষা, ১৮ অক্টোবর ২০২৬)। ⓘ অনুরোধের পথ এটা আগে থেকেই দেখত
         * ([[CreditLimitRequests::ownerSigned()]]); গ্রাহকের ফর্ম আর আমদানির পথে ছকের অন্য কোনো সইকারীর "হ্যাঁ"-তেই বাড়ত।
         */
        if (! \App\Modules\Customer\Services\CreditLimitRequests::ownerSigned($approval)) {
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

            /*
             * ⭐ বাকির দিন — একই পথ, নিজের ঘর (fe-র গ্যাপ ২৬, ২০ অক্টোবর ২০২৬)। ⓘ আগে সইয়ের পরে কাউকে ফিরে এসে আবার
             * সংরক্ষণ চাপতে হত। অঙ্ক = সই-করা নতুন দিন ([[CustomerService::assertDaysRaiseIsSigned()]]); মাঝে দিন অন্য পথে
             * বদলে থাকলে (`payload.days_before` মেলে না) কাগজটা পুরনো — কিছুই বসে না, সীমার নিয়মই।
             */
            if ($approval->action === 'credit_days') {
                $daysBefore = $approval->payload['days_before'] ?? null;

                if ($daysBefore === null || (int) $customer->credit_days !== (int) $daysBefore) {
                    return;
                }

                CustomerCreditChange::because(
                    $approval->requested_reason,
                    (int) $approval->id,
                    fn () => $customer->forceFill(['credit_days' => (int) $approval->amount])->save(),
                );
                $approval->markUsed();

                return;
            }

            /*
             * ⛔ অনুরোধের পরে সীমা অন্য পথে বদলে থাকলে সইটা পুরনো কাগজের — "১ লাখ থেকে ২ লাখ" সই পড়ল, অথচ মাঝে ঝুঁকিতে
             * সীমা ০ করা হয়েছিল; আগে সইয়ের শেষে ২ লাখ বসে কমানোটা নীরবে মুছত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
             * [[AnOldLimitSignatureUndidACutTest]])। ⓘ কিছুই বসে না; আবার বাড়াতে হলে নতুন অনুরোধ, নতুন সই।
             *
             * ⓘ অনুরোধ যে সীমা থেকে উঠেছিল, সেটা তার সাথেই রাখা ([[CustomerService::assertRaiseIsSigned()]], `payload.limit_before`)
             * — সময় ধরে মেলানো যেত না: একই সেকেন্ডে আগের বদল আর পরের বদল আলাদা করা যায় না। ⓘ পুরনো অনুরোধে ঘরটা নেই, তাদের আগের মতো।
             */
            $before = $approval->payload['limit_before'] ?? null;

            if ($before !== null && bccomp((string) ($customer->credit_limit ?? '0'), (string) $before, 4) !== 0) {
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
