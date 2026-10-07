<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Services\PromotionDesk;
use App\Modules\Promotion\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * বিক্রয়ের পর্দা যে প্রশ্নটা করে: *"এই সারিতে কোন অফার খাটে?"* — §১০।
 *
 * ── ⭐ কেন দরজাটা এই মডিউলে, বিক্রয়ে নয় ────────────────────────────
 * ⓘ স্পেক §২২: হিসাবটা কখনো বিক্রয়ের পর্দার ভিতরে নয়। ⚠️ চারটা পর্দা
 * (বিল, সরাসরি বিক্রয়, আদেশ, কাউন্টার) একই ঠিকানা ডাকে, আর উত্তরটা
 * একই ইঞ্জিন থেকে আসে। ⛔ প্রতিটা পর্দার নিজের দরজা হলে একদিন একটায়
 * নিয়ম বদলাত আর বাকিগুলোয় নয়।
 *
 * ── ⚠️ যাচাই নিজে হাতে, `$request->validate()` দিয়ে নয় ───────────────
 * ⓘ এই অ্যাপে JSON উত্তর আসে কেবল `api/*` পথে। ⛔ এখানে `validate()`
 * ছুঁড়লে উত্তরটা ৪২২ নয় — ৩০২, হোমে রিডাইরেক্ট। ⚠️ fetch রিডাইরেক্ট
 * অনুসরণ করে একটা ২০০ HTML পায়, `json()` ছোঁড়ে, আর পর্দা নীরবে ধরে নেয়
 * *"কোনো অফার নেই"*। ⓘ কাউন্টারের ফ্রি-সীমার দরজায় ঠিক এটা ধরা পড়েছিল।
 */
final class PromotionSuggestController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PromotionDesk $desk) {}

    /**
     * ⓘ `promotion.apply` — বিক্রয়কর্মীর রোজকার চাবি।
     *
     * ⚠️ `view` নয়: ⛔ তালিকার পর্দা দেখা আর বিলে অফার খোঁজা আলাদা কাজ।
     * একজন হিসাবরক্ষক তালিকা দেখতে পারেন অথচ বিল কাটেন না।
     */
    public static function middleware(): array
    {
        return [new Middleware('can:promotion.apply')];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $check = Validator::make($request->all(), [
            'product_id' => ['required', 'integer', Rule::exists('inv_products', 'id')],
            'qty' => ['required', 'numeric', Decimal::RULE, 'gt:0'],
            'value' => ['required', 'numeric', Decimal::RULE, 'gte:0'],
            'customer_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if ($check->fails()) {
            return response()->json(['message' => $check->errors()->first()], 422);
        }

        $data = $check->validated();

        /*
         * ⛔ অন্য কোম্পানির পণ্য — কোম্পানির ছাঁকনি দিয়েই।
         *
         * ⓘ `Rule::exists` গ্লোবাল স্কোপ মানে না — সে কাঁচা কোয়েরি চালায়,
         * Eloquent নয়। ⚠️ (মন্তব্যে ঐ ফাংশনের নামটা লেখা হয়নি ইচ্ছা করে:
         * `EveryRawQueryNamesItsCompany` একবার একটা মন্তব্যের লেখা ধরেই
         * মিথ্যা লাল হয়েছিল।)
         * ⚠️ তাই এখানে Eloquent দিয়ে আবার খোঁজা: [[BelongsToCompany]]
         * অন্য কোম্পানির পণ্য লুকিয়ে রাখে, আর তখন `null` আসে।
         */
        $product = Product::query()->find($data['product_id']);

        if ($product === null) {
            return response()->json(['message' => __('promotion::validation.product_not_yours')], 422);
        }

        /*
         * ⓘ পণ্যের শ্রেণি ও ব্র্যান্ড সারির সাথে পাঠানো — সুযোগের ছাঁকনি
         * ওগুলো ধরে মেলায়। ⚠️ না পাঠালে *"এই ব্র্যান্ডে ৩% ছাড়"* অফার
         * কোনোদিন খাটত না, কারণ সারিটা ব্র্যান্ড জানত না।
         */
        $line = [
            'product_id' => $product->id,
            'category_id' => $product->category_id ?? null,
            'brand_id' => $product->brand_id ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'qty' => (string) $data['qty'],
            'value' => (string) $data['value'],
        ];

        $found = $this->desk->suggest($line);

        return response()->json([
            'data' => [
                'eligible' => $found['eligible']->map(fn (array $row) => [
                    'code' => $row['promotion']->code,
                    'name' => $row['promotion']->name(),
                    'benefit' => $row['benefit']->kind->label(),
                    'amount' => (string) $row['benefit']->amount,
                    'worth' => $row['worth'],
                    'ends_on' => $row['promotion']->ends_on->toDateString(),
                ])->values(),

                /*
                 * ⭐ *"আর ৮ কার্টন নিলে"* — §১০।
                 *
                 * ⛔ সিস্টেম নিজে কিছু বসায় না — কেবল জানায়। ⓘ বসানো
                 * মানুষের চাপা বোতামে, আর তখন ইঞ্জিন আবার জিজ্ঞেস করে।
                 */
                'almost' => $found['almost']->map(fn (array $row) => [
                    'code' => $row['promotion']->code,
                    'name' => $row['promotion']->name(),
                    'short_by' => $row['short_by'],
                ])->values(),
            ],
        ]);
    }
}
