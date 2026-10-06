<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Events\SalesOrderApproved;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ অনুমোদিত বিক্রয় আদেশের মাল ধরা, তারপর নিশ্চিত — SO+DO মেশানোর নকশা, ধাপ ৪ (abos-bb-র 85a1846f, ৪ অক্টোবর ২০২৬)।
 *
 * ⓘ কেবল নতুন ধারার আদেশ (`hold_mode = holds`, সুইচ `sales.orders_replace_do`)। সেখানে মাল ধরা হয় সই শেষ হওয়ার পরে,
 * জমার সময় নয়। ধরা বসে মজুদের খাতায়, আদেশের নিজের উৎসে (`sales_order`)। তাই চালান আর বাতিল ঠিক এটাই পড়ে ছাড়ে
 * ([[SalesOrderService::heldByThisOrder()]], [[DeliveryChallanService::ownShare()]])।
 *
 * ⓘ যতটা পাওয়া যায় ততটাই ধরা হয়, বাকিটা ব্যাক অর্ডার: আদেশ − ধরা − ডেলিভার। ⛔ পাওয়া-না-যাওয়ায় আদেশ আটকায় না;
 * সই হয়ে গেছে, আর মাল এলে চালানে যাবে।
 *
 * ⓘ তালার ক্রম আগে ক্রেতা, তারপর আদেশ — DO-র বাকির যাচাইয়ের একই ক্রম, তাই দুই পথ একে অন্যকে আটকে রাখে না।
 */
final class HoldTheStockForTheApprovedOrder
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SalesOrderService $orders,
    ) {}

    public function handle(SalesOrderApproved $event): void
    {
        $id = (int) ($event->payload['sales_order_id'] ?? 0);
        $order = SalesOrder::query()->find($id);

        if ($order === null || $order->status !== SalesOrderStatus::APPROVED) {
            return;
        }

        DB::transaction(function () use ($order): void {
            Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first();
            $locked = SalesOrder::query()->with(['lines.product', 'warehouse'])->whereKey($order->id)->lockForUpdate()->first();

            // ⓘ দ্বিতীয়বার ঘটনা এলে, বা মাঝে কেউ বাতিল করলে — কিছুই নয়
            if ($locked === null || $locked->status !== SalesOrderStatus::APPROVED) {
                return;
            }

            if ($locked->hold_mode === SalesOrderStatus::HOLD_HOLDS && $locked->warehouse !== null) {
                // ⛔ গোনার আগে পণ্যের সারিতে তালা — দুটো একসাথে ধরলে দুটোই একই খালি মাল দেখত (অডিট ম১৮, [[StockLock]])
                \App\Modules\Sales\Support\StockLock::products($locked->lines->pluck('product_id'));

                foreach ($locked->lines as $line) {
                    $wanted = bcadd((string) $line->ordered_qty, '0', 4);

                    if ($line->product === null || bccomp($wanted, '0', 4) <= 0) {
                        continue;
                    }

                    $available = $this->stock->availableQty($line->product, $locked->warehouse);
                    $held = bccomp($available, $wanted, 4) >= 0 ? $wanted : (bccomp($available, '0', 4) > 0 ? $available : '0');

                    if (bccomp($held, '0', 4) <= 0) {
                        continue;
                    }

                    $this->stock->move(
                        product: $line->product,
                        warehouse: $locked->warehouse,
                        sourceType: SalesOrder::STOCK_SOURCE,
                        sourceId: (int) $locked->id,
                        reserved: $held,
                        date: $locked->trx_date,
                        documentNo: $locked->document_no,
                    );
                }
            }

            // ⓘ জমার সময়ের বাকির সতর্কতা যেমন ছিল তেমনই থাকে — এখানে নতুন কিছু লেখা হয় না
            $this->orders->markConfirmed($locked, $locked->credit_warnings);
        });
    }
}
