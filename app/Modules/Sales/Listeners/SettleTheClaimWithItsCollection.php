<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\DomainEvent;
use App\Core\Support\Actor;
use App\Modules\Sales\Services\DepositClaimService;

/**
 * ⭐ জমার খবরের আদায় পাকা হলো — শেষ সইয়ে নিজে ([[ConfirmTheCollectionOnTheLastSignature]]) বা কেউ হাতে — খবরটা "খাতায়"
 * (ধাপ গ, ১৮ নভেম্বর ২০২৬)। ⓘ হাতের "অনুমোদন দিন"-এ খবর আগেই গৃহীত হয়ে যায়, তখন এখানে কিছুই নয়।
 */
final class SettleTheClaimWithItsCollection
{
    public function __construct(private readonly DepositClaimService $claims) {}

    public function handle(DomainEvent $event): void
    {
        $collectionId = (int) ($event->payload['collection_id'] ?? 0);

        if ($collectionId > 0) {
            $this->claims->settleWith($collectionId, Actor::userId());
        }
    }
}
