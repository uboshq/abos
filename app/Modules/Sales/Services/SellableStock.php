<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\RecipeBook;
use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Support\Facades\DB;

/**
 * "এই পণ্যের কতটা এখন বেচা যায়" — একটাই উত্তর, সব পর্দার জন্য।
 *
 * ── ⚠️ কেন এটা একটা সেবা, আর কন্ট্রোলারে নয় ──────────────────────────
 * সংখ্যাটার সূত্রটা তুচ্ছ নয়: মেঝের মাল **বাদ** সংরক্ষিত **বাদ** আটকে
 * রাখা, আর খাবারের ক্ষেত্রে তার উপর রেসিপির হিসাব
 * ([[RecipeBook::sellableQty()]])।
 *
 * ⛔ এটা দুই জায়গায় দুইবার লিখলে একদিন দুইটা আলাদা হয়ে যেত, আর তখন
 * কাউন্টারের পর্দা আর অর্ডারের পর্দা **একই পণ্যের পাশে দুইটা সংখ্যা**
 * দেখাত। ⓘ কাউন্টারের কোডেই ঠিক এই ভয়টা লেখা আছে ("দুই কাউন্টারে একই
 * খাবারের পাশে দুইটা সংখ্যা বসে না")।
 *
 * ── ⓘ কেন ২১ সেপ্টেম্বর ২০২৬-এ এটা আলাদা হলো ─────────────────────────
 * মালিক অর্ডারের ফর্মে মজুদের ইঙ্গিত চেয়েছেন। সূত্রটা তখন কাউন্টারের
 * কন্ট্রোলারের ভিতরে একটা ব্যক্তিগত পদ্ধতিতে বসে ছিল, অর্থাৎ অর্ডারের
 * পর্দার কাছে পৌঁছানোর কোনো উপায় ছিল না — নকল করা ছাড়া।
 *
 * ⚠️ এটা কিছু **আটকায় না**। অর্ডার ভবিষ্যতের কাগজ: আজ মজুদ নেই মানে
 * কাল মাল আসবে না, এমন নয়। সংখ্যাটা কেবল জানানোর জন্য।
 */
final class SellableStock
{
    public function __construct(private readonly RecipeBook $recipes) {}

    /**
     * পণ্য → এখন কতটা বেচা যায়।
     *
     * ⓘ চাবিগুলো স্ট্রিং, কারণ এটা সোজা ব্রাউজারে যায় আর JSON-এ বস্তুর
     * চাবি সবসময় স্ট্রিং — `@js()` ঘুরে এসে `123` আর `'123'` এক না হলে
     * সারিটার পাশে সংখ্যাটা কখনো দেখাত না।
     *
     * @param  list<int>|null  $productIds  null হলে সব সক্রিয় পণ্য
     * @return array<string, string>
     */
    public function byProduct(?int $warehouseId = null, ?array $productIds = null): array
    {
        $sum = fn (string $column) => DB::table('inv_stock_movements')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('product_id', 'inv_products.id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId));

        $rows = Product::query()
            ->active()
            ->select('inv_products.id')
            // ⓘ মজুদের একটাই সূত্র — মেয়াদ পেরোনো লট বাদ ([[StockService::availableSql()]], মজুদ M27)
            ->selectSub($sum(StockService::availableSql()), 'available_total')
            ->when($productIds !== null, fn ($q) => $q->whereIn('inv_products.id', $productIds))
            ->get();

        $available = [];

        foreach ($rows as $row) {
            /*
             * ⓘ মেঝের মাল বাদ সংরক্ষিত বাদ আটকে রাখা বাদ মেয়াদ পেরোনো লট — মজুদের সূত্রে
             * ([[StockService::availableSql()]]), তারপর খাবারের নিজের উত্তর।
             */
            $onFloor = (string) $row->available_total;

            $available[(string) $row->id] = (string) $this->recipes->sellableQty(
                (int) $row->id,
                $onFloor,
                $warehouseId,
            );
        }

        return $available;
    }
}
