<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Sales\Models\SalesInvoiceCancellation;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;

/**
 * বাতিল-ইনভয়েস — শেষ সইয়ে নিজে পাকা, প্রত্যাখ্যানে কাগজ বাতিল (মালিক, ৪ অক্টোবর ২০২৬; [[SalesInvoiceCancellationService]])।
 *
 * ⓘ সইকারীর নামে পাকা হয় — খাতার উল্টো সারি আর বিলের সংশোধনের সারিতে তাঁর নাম। ⚠️ পাকা হতে না পারলে (মাঝে গেট পাস
 * হয়ে গেছে, মাস বন্ধ) ব্যতিক্রম ওঠে আর সইটা ফেরে না — কাগজ সইয়ের অপেক্ষায় থাকে, পাতায় কারণসহ আবার চেষ্টা করা যায়।
 */
final class FinishTheCancellationOnTheLastSignature
{
    public function __construct(private readonly SalesInvoiceCancellationService $cancellations) {}

    public function handle(ApprovalDecided $event): void
    {
        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null
            || $approval->approvable_type !== SalesInvoiceCancellation::class
            || $approval->action !== SalesInvoiceCancellationService::APPROVAL_ACTION) {
            return;
        }

        $cancellation = SalesInvoiceCancellation::query()->find((int) $approval->approvable_id);

        if ($cancellation === null) {
            return;
        }

        if (($event->payload['status'] ?? null) === Approval::REJECTED) {
            $this->cancellations->reject($cancellation);

            return;
        }

        if (($event->payload['status'] ?? null) !== Approval::APPROVED) {
            return;
        }

        $signer = $event->actorId === null ? null : User::query()->find($event->actorId);

        $this->cancellations->confirm($cancellation, $signer);
    }
}
