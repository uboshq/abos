<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * হাতে থাকা মজুদ — সেলসম্যান যা দেখে অর্ডার নেন।
 *
 * ── ⚠️ এই সংখ্যাটা পুরনো, আর সেটা লুকানো যাবে না ────────────────────
 * অফলাইনে দেখানো মজুদ **সবসময়ই শেষ সিঙ্কের মুহূর্তের**। এর মধ্যে গুদাম
 * থেকে মাল বেরিয়ে যেতে পারে, অন্য সেলসম্যান বেচে দিতে পারেন।
 *
 * তাই রেকর্ডের সাথে `updatedAt` যায়, আর সেটা ফোনের ক্যাশে বসে থাকে
 * ([[ReferenceCache::updatedAt()]]) — **যাতে পর্দা "৩ ঘণ্টা আগের মজুদ"
 * লিখতে পারে**, আজকের সংখ্যা বলে চালিয়ে না দেয়।
 *
 * মালিককে এটা বলা হয়েছিল যখন তিনি পাঁচটা জিনিসের তালিকা দেন, আর
 * তিনি মজুদটাও রাখতে বলেছেন। সিদ্ধান্তটা তাঁর; আমাদের কাজ সংখ্যাটার
 * বয়স গোপন না করা।
 *
 * ── অর্ডার আটকানোর সিদ্ধান্ত ফোন নেয় না ────────────────────────────
 * এই সংখ্যা দেখে ফোন কোনো অর্ডার **আটকায় না**। মাল আছে কি না তার
 * শেষ কথা সার্ভারের, সিঙ্কের মুহূর্তে — ঠিক যেভাবে ওয়েব থেকে অর্ডার
 * নিশ্চিত করলে হয়। ফোনে আটকালে দুইটা আলাদা উত্তর তৈরি হত, আর পুরনো
 * সংখ্যার ভিত্তিতে একটা বৈধ অর্ডার হারিয়ে যেত।
 *
 * ── ওয়াটারমার্ক খতিয়ান থেকে, পণ্যের সারি থেকে নয় ───────────────────
 * মজুদ `products`-এ লেখা থাকে না; সেটা `stock_movements`-এর যোগফল।
 * তাই পণ্যের `updated_at` দেখলে সংখ্যাটা **নীরবে পুরনো** হয়ে যেত —
 * একই ফাঁদ [[CustomerDueSync]]-এর বকেয়ায়, আর একই সমাধান।
 */
final class StockOnHandSync implements SyncsToDevices
{
    public static function module(): string
    {
        return 'inventory';
    }

    public static function entityType(): string
    {
        return 'StockOnHand';
    }

    public static function requiredPermission(): ?string
    {
        return 'inventory.stock.view';
    }

    /**
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        /*
         * পণ্য ধরে যোগফল, আর সেই সাথে শেষ নড়াচড়ার সময়।
         *
         * ── কেন [[StockService::statesForAll()]] ব্যবহার করা হয়নি ───
         * ওটা **সব** পণ্যের অবস্থা একসাথে গোনে, পাতা-ভাগ ছাড়া, আর
         * শেষ নড়াচড়ার সময় ফেরত দেয় না। ডেল্টা-সিঙ্কে দুইটাই লাগে,
         * তাই এখানে নিজের কোয়েরি — কিন্তু **অঙ্কটা হুবহু একই**
         * (floor − reserved − hold), যাতে ফোনের সংখ্যা আর পর্দার সংখ্যা
         * দুই রকম না হয়।
         *
         * ⚠️ `statesForAll()`-এর সংজ্ঞা বদলালে এটাও বদলাতে হবে। দুইটা
         * জায়গায় একই অঙ্ক — জেনেবুঝে, কারণ বিকল্পটা ছিল ওই পদ্ধতিটা
         * বদলানো, আর ওটা পাঁচটা পর্দা ব্যবহার করে।
         */
        $rows = StockMovement::query()
            ->groupBy('product_id')
            ->selectRaw('
                product_id,
                COALESCE(SUM(floor_change), 0) as floor,
                COALESCE(SUM(reserved_change), 0) as reserved,
                COALESCE(SUM(hold_change), 0) as hold,
                COALESCE(SUM(free_change), 0) as free,
                COALESCE(SUM(free_reserved_change), 0) as free_reserved,
                MAX(created_at) as moved_at
            ')
            ->when($since !== null, fn ($q) => $q->havingRaw('MAX(created_at) > ?', [$since]))
            ->orderByRaw('MAX(created_at)')
            ->orderBy('product_id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /*
         * পণ্যের বাইরের কী একবারে — সারি প্রতি একটা কোয়েরি নয়।
         *
         * ফোন কখনো ভেতরের ক্রমিক `id` দেখে না, তাই `public_id` লাগবেই;
         * লুপের ভেতরে খুঁজলে পাঁচশো সারিতে পাঁচশো কোয়েরি হত।
         */
        $publicIds = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->all())
            ->pluck('public_id', 'id');

        $records = [];

        foreach ($rows as $row) {
            $publicId = $publicIds[(int) $row->product_id] ?? null;

            /*
             * পণ্যটা নরম-মোছা বা অন্য কোম্পানির — সারিটা বাদ।
             *
             * নীরবে বাদ দেওয়া এখানে ঠিক: চলাচলের সারি থেকে যায় (শুধু
             * যোগের খাতা), কিন্তু পণ্যটা আর এই কোম্পানির তালিকায় নেই।
             */
            if ($publicId === null) {
                continue;
            }

            $floor = (string) $row->floor;
            $available = bcsub(bcsub($floor, (string) $row->reserved, 4), (string) $row->hold, 4);

            $records[] = new SyncRecord(
                entityType: self::entityType(),
                entityId: (string) $publicId,
                payload: [
                    'productId' => (string) $publicId,

                    /*
                     * তিনটাই যায়, শুধু "available" নয়।
                     *
                     * সেলসম্যান যদি দেখেন তাকে ১০০ আছে অথচ বেচা যায়
                     * ৪০, তাঁর জানার অধিকার আছে বাকি ৬০ কোথায় —
                     * অর্ডারে ধরা, না আটকানো। শুধু একটা সংখ্যা পাঠালে
                     * তিনি ধরে নিতেন মাল নেই, আর দোকানে "স্টক নাই"
                     * বলে ফিরে আসতেন।
                     */
                    'floor' => $floor,
                    'reserved' => (string) $row->reserved,
                    'hold' => (string) $row->hold,
                    'available' => $available,
                    'freeAvailable' => bcsub((string) $row->free, (string) $row->free_reserved, 4),
                ],
                updatedAt: Carbon::parse((string) $row->moved_at),
            );
        }

        return $records;
    }

    public function acceptsPush(): bool
    {
        return false;
    }

    /**
     * মজুদ ফোন থেকে বদলানো যায় না।
     *
     * মজুদ কোনো নথি নয়, চলাচলের **যোগফল** — বদলাতে হলে একটা সমন্বয়
     * লাগে, আর সমন্বয় খতিয়ানে দাখিলা বসায় ([[StockAdjustmentService]])।
     * সেটা নেট ছাড়া করা যায় না, আর ২ সেপ্টেম্বর ২০২৬ থেকে বন্ধ মাসেও
     * নয় ([[StockService::move()]]-এর `assertOpen()`)।
     */
    public function apply(User $user, PushedChange $change): string
    {
        throw new SyncRejection(__('sync.not_allowed_offline', ['type' => self::entityType()]));
    }
}
