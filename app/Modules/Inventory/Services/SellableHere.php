<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ নতুন কাগজের লাইনে পণ্যটা চলে কি না — সক্রিয়, আর কাগজের শাখায় বিক্রি হয় — Inventory অডিট ম২৩, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ "কোন শাখায় বিক্রি হয়" নিয়ম ([[Product::scopeSoldInViewedBranch()]]) আর নিষ্ক্রিয় পণ্য বাদ দেওয়া — দুটোই কেবল
 * পর্দার পিকারে ছিল। ফোনের কাউন্টার, পোর্টাল বা সরাসরি অনুরোধ পণ্যের id পাঠালেই লায়ন শাখার পণ্য সুপার শাখার
 * চালানে, আর বন্ধ করা পণ্য নতুন বিলে বসত। ⓘ দেয়ালটা এখন সেবায়, যেখানে প্রতিটা দরজা আসে।
 *
 * ⓘ কেবল **নতুন** পছন্দে: আগের কাগজ থেকে আসা লাইন (আদেশ থেকে চালান, চালান থেকে বিল) একবার যাচাই হয়ে গেছে, আর
 * পণ্যটা পরে বন্ধ হলেও সেই কাগজ শেষ করা যায়; ফেরতও এই দেয়ালের বাইরে।
 */
final class SellableHere
{
    public function assert(Product $product, ?int $branchId, string $field = 'lines'): void
    {
        if (! $product->is_active) {
            throw ValidationException::withMessages([
                $field => __('inventory::validation.product_inactive_on_paper', ['product' => $product->name()]),
            ]);
        }

        if ($branchId === null) {
            return;
        }

        // ⓘ শাখার সারি না থাকলে পণ্যটা সব শাখার — পিকারের একই নিয়ম
        $branches = DB::table('inv_product_branches')->where('company_id', $product->company_id)->where('product_id', $product->id)->pluck('branch_id')->map(fn ($id) => (int) $id);

        if ($branches->isNotEmpty() && ! $branches->contains($branchId)) {
            throw ValidationException::withMessages([
                $field => __('inventory::validation.product_not_sold_in_branch', ['product' => $product->name()]),
            ]);
        }
    }
}
