<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRejection;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use Illuminate\Support\Carbon;

/**
 * ⭐ ট্রাকের পাশে দাঁড়িয়ে মাল বুঝে নেওয়া — ২৫ সেপ্টেম্বর ২০২৬।
 *
 * ── ⓘ জোড়াটা এখন সম্পূর্ণ ───────────────────────────────────────────
 * [[PurchaseOrderSync]] যন্ত্রে পাঠায় *"কী আসার কথা"*। ⚠️ কিন্তু ফেরার
 * পথ ছিল না: গুদামের লোক গুনে শেষ করে অফিসে গিয়ে আবার সব টাইপ করতেন,
 * আর ঐ দ্বিতীয় টাইপিংটাই ভুলের আসল উৎস।
 *
 * ── ⛔ খসড়া বসে, নিশ্চিত হয় না — আর এটাই সবচেয়ে গুরুত্বপূর্ণ সিদ্ধান্ত ─
 * নিশ্চিত করা মানে **খতিয়ানে দাখিলা আর গুদামে চলাচল**। ⚠️ ঠেলা একদিন
 * হারায়, দুইবার আসে, বা পুরনো ঘড়ি নিয়ে আসে — ⛔ আর *"eventually
 * consistent ledger"* বলে কিছু নেই: খাতা হয় মেলে, নয় মেলে না।
 *
 * ⓘ তাই যন্ত্র কেবল **কী নামল** সেটা লিখে রাখে। সংখ্যাগুলো মেলানো আর
 * নিশ্চিত করা সার্ভারে, একজন মানুষের হাতে — ঠিক যেভাবে ওয়েব থেকে হয়।
 *
 * ── ⚠️ আদেশ ছাড়া গ্রহণ নয় ───────────────────────────────────────────
 * ⓘ যন্ত্র দর জানে না, আর জানার কথাও নয় — দর ক্রয় বিভাগের কথা। ⛔ দর
 * চাইলে গুদামের লোক আন্দাজে একটা সংখ্যা বসাতেন, আর ওটা ক্রয়মূল্য হয়ে
 * স্তরে বসে যেত।
 *
 * ⭐ তাই গ্রহণটা বাঁধা থাকে ঐ আদেশের সাথে যেটা যন্ত্র ইতিমধ্যে টেনে
 * নিয়েছে, আর দর-সরবরাহকারী-গুদাম তিনটাই আসে আদেশ থেকে। ⓘ জোড়াটা তখন
 * বৃত্ত: টানা → গোনা → ঠেলা।
 */
final class GoodsReceiptSync implements SyncsToDevices
{
    public static function module(): string
    {
        return 'purchase';
    }

    public static function entityType(): string
    {
        return 'GoodsReceipt';
    }

    public static function requiredPermission(): ?string
    {
        return 'purchase.receipt.create';
    }

    /**
     * ⓘ যন্ত্রে ফেরত যাওয়ার কিছু নেই।
     *
     * ⚠️ গ্রহণের কাগজ পড়ার দরকার হলে সেটা আলাদা প্রশ্ন, আর তখন
     * ওয়াটারমার্কের ফাঁদটা মাপতে হবে ([[PurchaseOrderSync]]-এর
     * ব্যাখ্যা)। ⛔ আজ না-লাগা জিনিস পাঠানো মানে ফোনের ক্যাশ ভরানো।
     *
     * @return list<\App\Core\Engines\Sync\SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        return [];
    }

    public function acceptsPush(): bool
    {
        return true;
    }

    public function apply(User $user, PushedChange $change): string
    {
        if (! $change->isCreate()) {
            throw SyncRejection::conflict(__('purchase::sync.receipt_edit_needs_network'));
        }

        $payload = $change->payload();

        /*
         * ⓘ বাইরের কী ধরে আদেশটা — ফোন কখনো ভেতরের ক্রমিক `id` দেখে না।
         */
        $order = PurchaseOrder::query()
            ->where('public_id', (string) ($payload['orderId'] ?? ''))
            ->first();

        if ($order === null) {
            throw new SyncRejection(__('purchase::sync.unknown_order'));
        }

        /*
         * ⛔ কেবল নিশ্চিত আদেশের বিপরীতে। ⚠️ খসড়া মানে কেউ এখনো
         * সরবরাহকারীকে কিছু বলেনি, আর বাতিল মানে বলা কথাটা তুলে নেওয়া
         * হয়েছে — ⓘ দুইটার কোনোটারই মাল আসার কথা নয়।
         */
        if ($order->status !== DocumentStatus::CONFIRMED) {
            throw new SyncRejection(__('purchase::sync.order_not_open'));
        }

        $order->loadMissing('lines');

        $lines = [];

        foreach ((array) ($payload['lines'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $product = Product::query()
                ->where('public_id', (string) ($row['productId'] ?? ''))
                ->first();

            if ($product === null) {
                throw new SyncRejection(__('purchase::sync.unknown_product'));
            }

            /*
             * ⭐ দরটা **আদেশ থেকে**, ঠেলা থেকে নয়।
             *
             * ⛔ পেলোডে দর থাকলেও সেটা পড়া হয় না — ⚠️ নাহলে একটা
             * বদলে দেওয়া ঠেলা ক্রয়মূল্য বদলে দিতে পারত, আর ঐ সংখ্যাটা
             * সরাসরি মজুদের স্তরে বসে।
             */
            $onOrder = $order->lines->firstWhere('product_id', $product->id);

            if ($onOrder === null) {
                throw new SyncRejection(__('purchase::sync.product_not_on_order'));
            }

            $qty = (string) ($row['receivedQty'] ?? '0');

            if (! is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                throw new SyncRejection(__('purchase::sync.receipt_needs_qty'));
            }

            $lines[] = [
                'product_id' => $product->id,
                'received_qty' => $qty,
                'rate' => (string) $onOrder->rate,
                'purchase_order_line_id' => $onOrder->id,
            ];
        }

        if ($lines === []) {
            throw new SyncRejection(__('purchase::sync.receipt_needs_lines'));
        }

        $receipt = app(PurchaseReceiptService::class)->create(
            [
                'purchase_order_id' => $order->id,
                'warehouse_id' => $order->warehouse_id,
                'supplier_id' => $order->supplier_id,
                'trx_date' => (string) ($payload['receivedOn'] ?? now()->toDateString()),
                'supplier_challan_no' => $payload['challanNo'] ?? null,

                /*
                 * ⓘ কাগজটা বলে ওটা যন্ত্র থেকে এসেছে — ⚠️ কারণ যিনি
                 * পরে নিশ্চিত করবেন তাঁর জানা দরকার সংখ্যাগুলো কেউ
                 * মিলিয়ে দেখেনি, ট্রাকের পাশে লেখা হয়েছে।
                 */
                'narration' => __('purchase::sync.from_the_handset'),
            ],
            $lines,
        );

        /*
         * ⛔ `confirm()` ডাকা হয় না, আর কোনোদিন হবে না — উপরের ব্যাখ্যা।
         */
        return (string) $receipt->public_id;
    }
}
