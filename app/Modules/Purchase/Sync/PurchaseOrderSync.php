<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseOrder;
use Illuminate\Support\Carbon;

/**
 * ⭐ কী কী মাল আসার কথা — গুদামের যন্ত্রে। ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ ক্রয় মডিউলে একটাও sync হ্যান্ডলার ছিল না ──────────────────────
 * ফোন ও হাতযন্ত্র পেত পণ্য, মজুদ, ক্রেতা, বকেয়া, বিক্রয়াদেশ, আদায়,
 * হাজিরা — ⓘ কিন্তু **কী আসার কথা** সেটা নয়। ⚠️ ফলে গুদামের লোক
 * ট্রাকের পাশে দাঁড়িয়ে কাগজ খুঁজতেন, আর কাগজ না পেলে যা নামছে তা-ই
 * বুঝে নিতেন।
 *
 * ── ⚠️ কেন কেবল **টানা** যায়, ঠেলা নয় ────────────────────────────────
 * ⛔ যন্ত্র থেকে আদেশ বানাতে দিলে দর, ছাড়, কর আর অনুমোদনের গোটা ছকটা
 * অফলাইনে চলে যেত — আর দর ঠিক করাটা ক্রয় বিভাগের কাজ, গুদামের নয়।
 * ⓘ মাল **বুঝে নেওয়া** যন্ত্র থেকে করা যায় কি না, সেটা আলাদা প্রশ্ন
 * আর আলাদা হ্যান্ডলার — ওখানে খতিয়ানও নড়ে।
 *
 * ── ⭐ কত এসেছে, সেটা **পাঠানো হয় না** — ইচ্ছাকৃতভাবে ─────────────────
 * ⓘ সংখ্যাটা আদেশের সারিতে লেখা থাকে না; ওটা চালানের সারিগুলোর যোগফল
 * ([[PurchaseOrderLine::receivedQty()]])। ⛔ পাঠালে ওয়াটারমার্কটা
 * মিথ্যা হত: চালান নিশ্চিত হলে আদেশের `updated_at` নড়ে না, তাই
 * ডেল্টা-সিঙ্ক ঐ বদলটা কোনোদিন পাঠাত না — আর যন্ত্রে সংখ্যাটা
 * **নীরবে পুরনো** হয়ে থাকত।
 *
 * ⚠️ একই ফাঁদে [[CustomerDueSync]] বকেয়ায় আর [[StockOnHandSync]]
 * মজুদে পড়েছিল, আর দুই জায়গাতেই সমাধানটা ছিল খতিয়ান থেকে ওয়াটারমার্ক
 * নেওয়া। ⓘ এখানে তৃতীয় পথটা বেছে নেওয়া হয়েছে — **সংখ্যাটা না
 * পাঠানো** — কারণ যন্ত্রের প্রশ্নটা *"কী আসার কথা"*, *"কতটা এসে গেছে"*
 * নয়; আর অর্ধেক পুরনো সংখ্যা পাঠানোর চেয়ে না পাঠানো সৎ।
 */
final class PurchaseOrderSync implements SyncsToDevices
{
    public static function module(): string
    {
        return 'purchase';
    }

    public static function entityType(): string
    {
        return 'PurchaseOrder';
    }

    public static function requiredPermission(): ?string
    {
        return 'purchase.order.view';
    }

    /**
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        /*
         * ⓘ কেবল নিশ্চিত আদেশ — ⛔ খসড়া মানে কেউ এখনো সরবরাহকারীকে
         * বলেনি, আর বাতিল মানে বলা কথাটা তুলে নেওয়া হয়েছে। ⚠️ দুইটার
         * কোনোটাই গুদামের লোকের অপেক্ষা করার মতো জিনিস নয়।
         *
         * ⚠️ বন্ধ (`CLOSED`) আদেশও যায়: ⓘ আগে পাঠানো একটা সারি বন্ধ
         * হয়ে গেলে যন্ত্রকে সেটা **জানাতে** হয়, নাহলে ওটা চিরকাল
         * "আসার কথা" তালিকায় বসে থাকত। ⛔ ছেঁকে বাদ দেওয়া আর মুছে
         * ফেলা এক জিনিস নয় — ছাঁকনি নীরব।
         */
        $orders = PurchaseOrder::query()
            ->with(['supplier', 'lines.product'])
            ->whereIn('status', [
                DocumentStatus::CONFIRMED,
                DocumentStatus::CLOSED,
                DocumentStatus::CANCELLED,
            ])
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $records = [];

        foreach ($orders as $order) {
            $records[] = new SyncRecord(
                entityType: self::entityType(),
                entityId: (string) $order->public_id,
                payload: [
                    'documentNo' => (string) $order->document_no,
                    'supplierName' => (string) ($order->supplier?->name() ?? ''),
                    'orderedOn' => $order->trx_date?->toDateString(),
                    'expectedOn' => $order->expected_on?->toDateString(),

                    /*
                     * ⓘ অবস্থাটা যায়, কারণ যন্ত্রকে সারিটা **সরাতেও**
                     * হয় — বাতিল বা বন্ধ হলে ওটা আর অপেক্ষার তালিকায়
                     * থাকার কথা নয়।
                     */
                    'status' => (string) $order->status,

                    'lines' => $order->lines->map(fn ($line) => [
                        'productId' => (string) ($line->product?->public_id ?? ''),
                        'productName' => (string) ($line->product?->name() ?? ''),
                        'orderedQty' => (string) $line->ordered_qty,
                    ])->values()->all(),
                ],

                /*
                 * ⚠️ ওয়াটারমার্ক আদেশের নিজের `updated_at` — আর সেটা
                 * এখানে **সত্যি**, কারণ উপরের payload-এ এমন কিছু নেই
                 * যা আদেশের বাইরে বদলায়। ⓘ কত এসেছে সেটা ঢোকানোর দিন
                 * এই লাইনটাই প্রথমে মিথ্যা হয়ে যেত।
                 */
                updatedAt: $order->updated_at,
            );
        }

        return $records;
    }

    public function acceptsPush(): bool
    {
        return false;
    }

    /**
     * আদেশ যন্ত্র থেকে বানানো বা বদলানো যায় না।
     *
     * ⛔ দর, ছাড়, কর আর অনুমোদনের ছক — চারটাই আদেশের সাথে বাঁধা, আর
     * চারটাই ক্রয় বিভাগের সিদ্ধান্ত। ⚠️ অফলাইনে বসানো একটা দর পরে
     * সংশোধন করার কোনো নীরব উপায় নেই: আদেশটা ততক্ষণে সরবরাহকারীর কাছে
     * চলে গেছে।
     */
    public function apply(User $user, PushedChange $change): string
    {
        throw new SyncRejection(__('sync.not_allowed_offline', ['type' => self::entityType()]));
    }
}
