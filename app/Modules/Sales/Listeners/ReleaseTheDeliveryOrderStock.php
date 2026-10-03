<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Events\DeliveryOrderCancelled;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderStock;

/**
 * DO বাতিল বা ফেরত — তার আটকানো মাল ফেরে। বিক্রয়ের কাজের ধারা, ধাপ ঘ (৩ অক্টোবর ২০২৬)।
 *
 * ⓘ কারণটা ঘটনা থেকেই (`rejected` | `cancelled`) — পরে বলা যায় মাল কেন ফিরল।
 */
final class ReleaseTheDeliveryOrderStock
{
    public function __construct(private readonly DeliveryOrderStock $stock) {}

    public function handle(DeliveryOrderCancelled $event): void
    {
        $order = DeliveryOrder::query()->withTrashed()->find((int) ($event->payload['delivery_order_id'] ?? 0));

        if ($order === null) {
            return;
        }

        $reason = (string) ($event->payload['reason'] ?? 'cancelled');

        $this->stock->release($order, in_array($reason, ['rejected', 'cancelled'], true) ? $reason : 'cancelled');
    }
}
