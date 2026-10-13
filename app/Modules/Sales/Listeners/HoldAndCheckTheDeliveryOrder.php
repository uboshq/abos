<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Support\CompanyContext;
use App\Modules\Sales\Events\DeliveryOrderSupervisorApproved;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderAccounts;
use App\Modules\Sales\Services\DeliveryOrderStock;

/**
 * ⭐ সুপারভাইজারের শেষ অনুমোদন — মাল আটকানো, তারপর হিসাবের যাচাই। বিক্রয়ের কাজের ধারা, ধাপ গ + ঘ (৩ অক্টোবর ২০২৬)।
 *
 * ⓘ ক্রম ইচ্ছাকৃত: আগে মাল ২৪ ঘণ্টার কড়া আটকানো ([[DeliveryOrderStock::holdForSupervisor()]]) — টাকা না কুলোলেও
 * মালিকের নিয়মে মাল আটকে থাকে, ঘড়ি চলে। তারপর হিসাব ([[DeliveryOrderAccounts::check()]]); কুলোলে আটকানোটা বিল
 * পর্যন্ত কড়া হয়ে যায়।
 */
final class HoldAndCheckTheDeliveryOrder
{
    public function __construct(
        private readonly DeliveryOrderStock $stock,
        private readonly DeliveryOrderAccounts $accounts,
    ) {}

    public function handle(DeliveryOrderSupervisorApproved $event): void
    {
        /*
         * ⛔ খোঁজা সব শাখা জুড়ে, কাজ DO-র নিজের শাখায় (fe, ১৩ অক্টোবর ২০২৬; [[ADealerWritesTheDeliveryOrderAndTheSupervisorSignsItTest]])।
         * ⚠️ আগে সুপারভাইজারের হেডারে অন্য শাখা থাকলে DO "নেই" — মাল ধরা হত না, হিসাবের যাচাইও না, আর কোনো খবরও না।
         */
        $order = DeliveryOrder::acrossBranches()->find((int) ($event->payload['delivery_order_id'] ?? 0));

        if ($order === null) {
            return;
        }

        CompanyContext::inBranch($order->branch_id === null ? null : (int) $order->branch_id, function () use ($order): void {
            $this->stock->holdForSupervisor($order);
            $this->accounts->check($order);
        });
    }
}
