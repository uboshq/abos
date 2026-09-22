<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Core\Security\FieldSecurity;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Support\Carbon;

/**
 * পণ্যের তালিকা, ফোনে — দাম সহ।
 *
 * ── ⚠️ দামটা এখানে কেন, আলাদা হ্যান্ডলারে নয় ────────────────────────
 * মালিক পাঁচটা জিনিস অফলাইনে চেয়েছিলেন, আর "পণ্যের দাম" ছিল আলাদা একটা
 * সারি। মেপে দেখা গেল **ABOS-এ পণ্যের দাম একটা আলাদা জিনিস নয়** —
 * `mdm_price_lists` টেবিলে কেবল দামের তালিকার *নাম* আছে, পণ্যপ্রতি কোনো
 * দর নেই; আসল দরটা পণ্যের নিজের সারিতে, `sale_price` কলামে।
 *
 * তাই আলাদা একটা `ProductPriceSync` একই টেবিলের একই সারি দ্বিতীয়বার
 * পড়ত, আর ফোনে দুইটা রেকর্ড বসাত যাদের একটা বদলালে অন্যটা পুরনো
 * থাকত। **যা আলাদা জিনিস নয় তাকে আলাদা করলে দুইটা সত্য তৈরি হয়।**
 *
 * গ্রাহক-ভিত্তিক দর (price list per customer) যেদিন সত্যিই আসবে, সেদিন
 * ওটার নিজের হ্যান্ডলার লাগবে — আর তখন সেটা সত্যিই আলাদা জিনিস হবে।
 *
 * ── ⚠️ ক্রয়মূল্য পাঠানো হয় না, যদি না অনুমতি থাকে ──────────────────
 * `purchase_price` [[FieldSecurity]]-র পেছনে (`inventory.cost.view`)।
 * পর্দায় ওটা ২ সেপ্টেম্বর ২০২৬-এ বন্ধ করা হয়েছে — তিন দরজায়: দেখা,
 * সম্পাদনার ফর্ম, আর হাতে বানানো POST।
 *
 * **JSON-এ `@can` বলে কিছু নেই**, তাই এটা এখানে হাতে লিখতে হয়। না
 * লিখলে API-টাই হত ওই তালার চারপাশ দিয়ে যাওয়ার পথ — সেলসম্যান
 * পর্দায় দেখতেন না, অথচ ফোনের সিঙ্কে ক্রয়মূল্য নেমে যেত।
 *
 * ঘরটা **বাদ দেওয়া হয়**, mask পাঠানো হয় না: JSON পার্স করা ক্লায়েন্টের
 * জানার কথা নয় যে `'••••'` মানে "অনুমতি নেই"।
 */
final class ProductSync implements SyncsToDevices
{
    public static function module(): string
    {
        return 'inventory';
    }

    public static function entityType(): string
    {
        return 'Product';
    }

    public static function requiredPermission(): ?string
    {
        return 'inventory.product.view';
    }

    /**
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        /*
         * ⭐ প্যাকগুলোও একসাথে — ধাপ ৭, ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ লুপের ভিতরে ডাকলে পাঁচশো পণ্যের সিঙ্কে পাঁচশো
         * কোয়ারি হত — ফোনের প্রথম সিঙ্কটাই সবচেয়ে বড়।
         */
        $query = Product::query()
            ->with(['unit:id,code,name_en,name_bn', 'packs.unit:id,code,name_en,name_bn'])
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        /*
         * অনুমতিটা একবার দেখা, প্রতি সারিতে নয়।
         *
         * [[FieldSecurity::visible()]] চলতি ব্যবহারকারীর জন্য উত্তর দেয়,
         * আর উত্তরটা পাঁচশো সারিতে একই — লুপের ভেতরে ডাকলে পাঁচশোবার
         * একই প্রশ্ন করা হত।
         */
        $showsCost = FieldSecurity::visible(Product::class, 'purchase_price');

        return $query->get()->map(fn (Product $product) => new SyncRecord(
            entityType: self::entityType(),
            entityId: (string) $product->public_id,
            payload: array_filter([
                'id' => (string) $product->public_id,
                'code' => $product->code,
                'nameEn' => $product->name_en,
                'nameBn' => $product->name_bn,
                'barcode' => $product->barcode,
                'unitCode' => $product->unit?->code,
                'unitNameBn' => $product->unit?->name_bn,

                /*
                 * বিক্রয়মূল্য — অফলাইনে অর্ডার লেখার সময় এটাই দর হিসেবে
                 * বসে। সার্ভারে সিঙ্কের সময় দর-সহনশীলতার নিয়ম
                 * ([[PricingRule]]) আবার মাপে, কারণ ফোনে বসে থাকা দামটা
                 * ততক্ষণে পুরনো হয়ে থাকতে পারে।
                 */
                'salePrice' => (string) $product->sale_price,

                'purchasePrice' => $showsCost ? (string) $product->purchase_price : null,

                /*
                 * ⭐ পণ্যের নিজের প্যাক — কার্টনে কত, আর গায়ে কোন বারকোড।
                 *
                 * ── ⛔ যা ভাঙা ছিল ──────────────────────────────
                 * ফোন পণ্যের **ভিত্তি এককটাই** জানত। ⚠️ তাই অফলাইনে
                 * অর্ডার লেখা যেত কেবল পিসে, আর বিক্রেতা কার্টনে গুনে
                 * মাথায় গুণ করে লিখতেন — আর ভুল হলে সেটা ধরা পড়ত
                 * মাল ডেলিভারির দিন।
                 *
                 * ⓘ বারকোডটাও যায়, কারণ ফোনেই স্ক্যান হয় — ডেস্কটপের
                 * পর্দায় যে মানচিত্রটা যায় ([[SalesOrderController]]), এটা তারই
                 * অফলাইন রূপ।
                 *
                 * ⚠️ নিষ্ক্রিয় সারি বাদ: প্যাকটা বন্ধ করা হলেও ফোনে
                 * রেখে দিলে সেলসম্যান একটা অস্বীকৃত এককে অর্ডার লিখতেন।
                 *
                 * ⛔ ফাঁকা তালিকা `null` হয়ে বাদ পড়ে (`array_filter`), তাই
                 * যে পণ্যের প্যাক নেই তার পেলোয়াড এক বাইটও বাড়ে না।
                 */
                'packs' => $this->packs($product) ?: null,

                'isActive' => (bool) $product->is_active,
            ], fn ($value) => $value !== null),
            updatedAt: $product->updated_at ?? $product->created_at ?? now(),
        ))->all();
    }

    /**
     * পণ্য ফোন থেকে বসানো যায় না — মালিকের সিদ্ধান্ত: নেট ছাড়া শুধু অর্ডার।
     *
     * আর এখানে সেটা আরও স্পষ্ট: একটা নতুন পণ্য মানে একটা কোড, একটা
     * একক, একটা ভ্যাটের হার আর দুইটা দাম — পাঁচটাই অফিসের সিদ্ধান্ত,
     * আর ভুল হলে সেটা প্রতিটা ভবিষ্যৎ বিলে বসে থাকে।
     */
    /**
     * এই পণ্যের প্যাকগুলো, ফোনের জন্য।
     *
     * ⓘ বড়ড় থেকে ছোট — `packs()` সম্পর্কটাই `factor` ধরে
     * সাজানো, তাই ফোনেও কার্টন আগে দেখা যাবে।
     *
     * @return list<array{unitCode: ?string, unitNameBn: ?string, factor: string, barcode: ?string}>
     */
    private function packs(Product $product): array
    {
        return $product->packs
            ->filter(fn (ProductUnit $pack) => (bool) $pack->is_active)
            ->map(fn (ProductUnit $pack) => array_filter([
                'unitCode' => $pack->unit?->code,
                'unitNameBn' => $pack->unit?->name_bn,
                /*
                 * ⓘ `decimal:6`, তাই সরাসরি ঢাললে যায় `"12.000000"`।
                 *
                 * ⚠️ ফোনে ওটা পর্দায় বসত বা সংখ্যায় বদলাতে হত, আর
                 * ডেস্কটপের মানচিত্রে (`PackConversion::barcodesFor()`) যায়
                 * `"12"` — এক জিনিসের দুই রূপ দুই পর্দায়।
                 */
                'factor' => rtrim(rtrim((string) $pack->factor, '0'), '.'),
                'barcode' => $pack->barcode,
            ], fn ($value) => $value !== null))
            ->values()
            ->all();
    }

    /**
     * পণ্য ফোন থেকে বসানো যায় না — মালিকের সিদ্ধান্ত: নেট ছাড়া শুধু অর্ডার।
     *
     * আর এখানে সেটা আরও স্পষ্ট: একটা নতুন পণ্য মানে একটা কোড, একটা
     * একক, একটা ভ্যাটের হার আর দুইটা দাম — পাঁচটাই অফিসের সিদ্ধান্ত,
     * আর ভুল হলে সেটা প্রতিটা ভবিষ্যৎ বিলে বসে থাকে।
     */
    public function acceptsPush(): bool
    {
        return false;
    }

    public function apply(User $user, PushedChange $change): string
    {
        throw new SyncRejection(__('sync.not_allowed_offline', ['type' => self::entityType()]));
    }
}
