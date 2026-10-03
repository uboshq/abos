<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\DomainEvent;
use App\Modules\Accounts\Events\ChequeCleared;
use App\Modules\Accounts\Events\VoucherPosted;
use App\Modules\Sales\Events\CollectionConfirmed;
use App\Modules\Sales\Services\DeliveryOrderAccounts;

/**
 * ⭐ গ্রাহকের টাকা এল — তাঁর "টাকার জন্য আটকে" DO নিজে আবার যাচাই। বিক্রয়ের কাজের ধারা, ধাপ গ (৩ অক্টোবর ২০২৬)।
 *
 * তিন দরজা, এক কাজ: আদায় পাকা ([[CollectionConfirmed]]), রসিদ ভাউচার ([[VoucherPosted]], পক্ষ গ্রাহক), চেক পাশ
 * ([[ChequeCleared]], গৃহীত চেক, পক্ষ গ্রাহক)। ⛔ দেওয়া চেক বা সরবরাহকারীর টাকা নয় — ওতে গ্রাহকের জায়গা বাড়ে না।
 */
final class RecheckTheHeldDeliveryOrders
{
    public function __construct(private readonly DeliveryOrderAccounts $accounts) {}

    public function handle(DomainEvent $event): void
    {
        $customerId = $this->customerOf($event);

        if ($customerId !== null) {
            $this->accounts->recheckCustomer($customerId);
        }
    }

    private function customerOf(DomainEvent $event): ?int
    {
        $p = $event->payload;

        if ($event instanceof CollectionConfirmed) {
            return isset($p['customer_id']) ? (int) $p['customer_id'] : null;
        }

        if ($event instanceof ChequeCleared && ($p['direction'] ?? null) !== 'received') {
            return null;
        }

        if (($event instanceof ChequeCleared || $event instanceof VoucherPosted)
            && ($p['party_type'] ?? null) === 'customer' && isset($p['party_id'])) {
            return (int) $p['party_id'];
        }

        return null;
    }
}
