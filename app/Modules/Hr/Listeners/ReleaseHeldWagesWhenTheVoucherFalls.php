<?php

declare(strict_types=1);

namespace App\Modules\Hr\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Modules\Accounts\Events\VoucherCancelled;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Hr\Services\HeldWages;

/**
 * ⭐ বকেয়া মজুরির খসড়া ভাউচার বাতিল হলো বা সইয়ে "না" পেল — মজুরি আবার "দেওয়ার অপেক্ষায়" (শ্রম আইন ধারা ১২৩; fe, ২১ অক্টোবর ২০২৬;
 * [[HeldWages::releaseFor()]], [[TheSalaryWentWhereOneHandSaidTest]])।
 *
 * ⓘ দুই খবর শোনে: হিসাবের বাতিল ([[VoucherCancelled]]) আর সইয়ের "না" ([[ApprovalDecided]], অনুমোদনের কাগজ ভাউচার)। ⛔ পাকা ভাউচার
 * কখনো ছাড়া হয় না — সেবা নিজেই দেখে।
 */
final class ReleaseHeldWagesWhenTheVoucherFalls
{
    public function handle(VoucherCancelled|ApprovalDecided $event): void
    {
        $payload = $event->payload;

        if ($event instanceof VoucherCancelled) {
            app(HeldWages::class)->releaseFor((int) ($payload['voucher_id'] ?? 0));

            return;
        }

        if (($payload['status'] ?? null) !== Approval::REJECTED) {
            return;
        }

        // ⓘ কাগজটা ভাউচার কি না — সারির নিজের সম্পর্ক ধরে (morph-এর নাম যা-ই হোক)
        $voucher = Approval::query()->find($payload['approval_id'] ?? null)?->approvable;

        if (! $voucher instanceof Voucher || ! \App\Modules\Hr\Models\HeldWagePayment::query()->where('voucher_id', $voucher->id)->exists()) {
            return;
        }

        /*
         * ⛔ "না" পাওয়া খসড়া বাতিলও হয় — নাহলে কেউ সেটা বদলে পরে পাকা করলে, আর ছাড়া মজুরি নতুন কাগজে গেলে, একই মজুরি দুইবার।
         * ⓘ বাতিলের খবর ([[VoucherCancelled]]) নিজেই সারি ছাড়ে; পাকা হয়ে থাকলে কিছুই হয় না।
         */
        if (! $voucher->isPosted() && ! $voucher->isCancelled()) {
            app(\App\Modules\Accounts\Services\VoucherService::class)->cancel($voucher, (string) __('hr::payroll.held_rejected_cancel'));

            return;
        }

        app(HeldWages::class)->releaseFor((int) $voucher->id);
    }
}
