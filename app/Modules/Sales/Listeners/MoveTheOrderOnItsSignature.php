<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * সুপারভাইজারের সিদ্ধান্তে বিক্রয় আদেশ এগোয় বা থামে — নতুন ধারা (নকশা "DO বিক্রয় আদেশে মেশানো" §৩.১, ধাপ ৩)।
 *
 * ⓘ শেষ স্তরের সই (`approved`) → `approved` আর abos-86-এর সংকেত ([[SalesOrderApproved]]); ফেরত (`rejected`) →
 * `rejected` আর বাতিলের সংকেত ([[SalesOrderCancelled]])। ⓘ DO-র [[MoveTheDeliveryOrderOnItsSignature]]-এর ছাঁচ।
 *
 * ⛔ কেবল `awaiting_approval`-এ থাকা আদেশ — দুইবার আসা সংকেতে কিছু হয় না। ⚠️ আজকের নিয়মের (`ledger`) আদেশও একই
 * `order` ছকে সই নেয় ([[DocumentApproval::assertClear()]]), কিন্তু সে সই পেয়েও খসড়া থাকে আর লেখক আবার "নিশ্চিত" চাপেন —
 * এই শ্রোতা তাকে ছোঁয় না।
 */
final class MoveTheOrderOnItsSignature
{
    public function __construct(private readonly SalesOrderService $orders) {}

    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['module'] ?? null) !== 'sales'
            || ($event->payload['action'] ?? null) !== SalesOrderService::APPROVAL_ACTION
            || ($event->payload['approvable_type'] ?? null) !== SalesOrder::class) {
            return;
        }

        $status = (string) ($event->payload['status'] ?? '');

        if (! in_array($status, [Approval::APPROVED, Approval::REJECTED], true)) {
            return;
        }

        CompanyContext::forCompany($event->companyId, function () use ($event, $status): void {
            DB::transaction(function () use ($event, $status): void {
                $order = SalesOrder::query()->withoutGlobalScopes()
                    ->where('company_id', $event->companyId)
                    ->whereKey((int) ($event->payload['approvable_id'] ?? 0))
                    ->lockForUpdate()->first();

                if ($order === null || $order->status !== SalesOrderStatus::AWAITING_APPROVAL) {
                    return;
                }

                $status === Approval::APPROVED
                    ? $this->orders->markApproved($order)
                    : $this->orders->reject($order, null, null);
            });
        });
    }
}
