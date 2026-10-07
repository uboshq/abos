<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * সুপারভাইজারের সিদ্ধান্তে DO এগোয় বা থামে — মালিকের বিক্রয়-ধারা §২খ (২ অক্টোবর ২০২৬)।
 *
 * ⓘ শেষ স্তরের সই (`approved`) → `supervisor_approved` আর abos-86-এর সংকেত; ফেরত (`rejected`) → `rejected`
 * আর বাতিলের সংকেত (মজুদ ছাড়)। ⛔ কেবল `supervisor_pending`-এ থাকা DO — দুইবার আসা সংকেতে কিছু হয় না।
 */
final class MoveTheDeliveryOrderOnItsSignature
{
    public function __construct(private readonly DeliveryOrderService $orders) {}

    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['module'] ?? null) !== 'sales'
            || ($event->payload['action'] ?? null) !== DeliveryOrderService::APPROVAL_ACTION
            || ($event->payload['approvable_type'] ?? null) !== DeliveryOrder::class) {
            return;
        }

        $status = (string) ($event->payload['status'] ?? '');

        if (! in_array($status, [Approval::APPROVED, Approval::REJECTED], true)) {
            return;
        }

        CompanyContext::forCompany($event->companyId, function () use ($event, $status): void {
            DB::transaction(function () use ($event, $status): void {
                $order = DeliveryOrder::query()->withoutGlobalScopes()
                    ->where('company_id', $event->companyId)
                    ->whereKey((int) ($event->payload['approvable_id'] ?? 0))
                    ->lockForUpdate()->first();

                if ($order === null || $order->status !== DeliveryOrderStatus::SUPERVISOR_PENDING) {
                    return;
                }

                $status === Approval::APPROVED
                    ? $this->orders->markSupervisorApproved($order)
                    : $this->orders->stop($order, 'rejected', null, null);
            });
        });
    }
}
