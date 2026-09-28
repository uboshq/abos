<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Sales\Services\HeldCounterSaleFinisher;

/**
 * ⭐ শেষ সই পড়ল — কাগজটা কাউন্টারের আটকে থাকা বিক্রির হলে, বিক্রিটা শেষ।
 *
 * ⓘ যুক্তি সবটা [[HeldCounterSaleFinisher]]-এ; এখানে কেবল কোন অনুরোধ, কে সই দিলেন।
 * ⚠️ চালান, বিল বা জমা — যেকোনোটার শেষ সই শেষটা হতে পারে, তাই তিনটাই শোনা হয়;
 * বাকি সই অপেক্ষায় থাকলে সেবা নিজেই কিছু করে না।
 */
final class FinishTheHeldSaleOnTheLastSignature
{
    public function __construct(private readonly HeldCounterSaleFinisher $finisher) {}

    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['status'] ?? null) !== Approval::APPROVED) {
            return;
        }

        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null) {
            return;
        }

        $invoice = $this->finisher->invoiceFor($approval);

        if ($invoice === null) {
            return;
        }

        $signer = $event->actorId === null ? null : User::query()->find($event->actorId);

        $this->finisher->finish($invoice, $signer);
    }
}
