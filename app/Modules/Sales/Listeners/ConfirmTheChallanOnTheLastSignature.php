<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Sales\Services\SignedChallanConfirmer;

/**
 * ⭐ শেষ সই পড়ল — কাগজটা অফিসের খসড়া চালান হলে, চালানটা পাকা।
 *
 * ⓘ যুক্তি সবটা [[SignedChallanConfirmer]]-এ; এখানে কেবল কোন অনুরোধ, কে সই দিলেন।
 * ⓘ ঘটনাটা ছোড়া হয় কেবল **শেষ** সিদ্ধান্তে ([[ApprovalEngine::approve()]]) — দুই ধাপের সইয়ে
 * প্রথম সইয়ে কিছুই আসে না। কাউন্টারের বিক্রি শোনে [[FinishTheHeldSaleOnTheLastSignature]]।
 */
final class ConfirmTheChallanOnTheLastSignature
{
    public function __construct(private readonly SignedChallanConfirmer $confirmer) {}

    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['status'] ?? null) !== Approval::APPROVED) {
            return;
        }

        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null) {
            return;
        }

        $challan = $this->confirmer->challanFor($approval);

        if ($challan === null) {
            return;
        }

        $signer = $event->actorId === null ? null : User::query()->find($event->actorId);

        $this->confirmer->confirm($challan, $signer);
    }
}
