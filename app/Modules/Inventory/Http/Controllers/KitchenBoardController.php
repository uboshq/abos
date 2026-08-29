<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * এখন আর কী কী বানানো যাবে — রান্নাঘরের বোর্ড।
 *
 * ── কেন এই পর্দাটা, ২৯ আগস্ট ২০২৬ ────────────────────────────────────
 * রেস্টুরেন্টের কাজের ক্রম ঠিক করা আছে: রেসিপি → **রিয়েল-টাইম** → POS
 * → কিচেন ডিসপ্লে। রেসিপি হয়ে গেছে; এটা দ্বিতীয় ধাপ।
 *
 * গুদামে কত কেজি চাল আছে সেটা কাউন্টারের প্রশ্ন নয়। কাউন্টারের একটাই
 * প্রশ্ন: **আর কয় প্লেট বেচা যাবে**। ওটা না জানলে অর্ডার নেওয়া হয়,
 * টাকা নেওয়া হয়, তারপর রান্নাঘর বলে "শেষ" — আর সামনে দাঁড়ানো মানুষকে
 * টাকা ফেরত দিতে হয়।
 *
 * ── কেন পোলিং, ওয়েবসকেট নয় ──────────────────────────────────────────
 * "রিয়েল-টাইম" শুনলেই ওয়েবসকেট মনে আসে, আর ওটা এখানে ভুল উত্তর হত:
 * একটা নতুন সার্ভার, একটা নতুন পোর্ট, নতুন করে ব্যর্থ হওয়ার একটা
 * জায়গা — একটা ডিপোর জন্য, যেখানে সংখ্যাটা মিনিটে কয়েকবার বদলায়।
 *
 * বিশ সেকেন্ডের পোলিং কাউন্টারের জন্য যথেষ্ট রিয়েল-টাইম, আর ইন্টারনেট
 * এক মিনিট গেলে পাতাটা নিজে থেকেই আবার ধরে নেয়। যেদিন সত্যিই সাব-সেকেন্ড
 * লাগবে, সেদিন এই একই হিসাবটার উপর ওয়েবসকেট বসানো যাবে — হিসাবটা
 * [[RecipeService::portionsPossible()]]-এ, পর্দায় নয়।
 */
class KitchenBoardController extends Controller implements HasMiddleware
{
    /**
     * বোর্ডটা রেসিপির অনুমতিতেই চলে — নিজের কোনো অনুমতি নেই।
     *
     * ── কেন নতুন একটা অনুমতি বানানো হয়নি ────────────────────────────
     * এই পর্দা নতুন কোনো তথ্য দেখায় না: রেসিপি আর স্টক, দুইটাই যিনি
     * দেখতে পারেন তাঁর জন্য এটা কেবল ওই দুইটার যোগফল। আলাদা অনুমতি
     * রাখলে কাউকে "বোর্ড দেখার" অনুমতি দিয়ে রেসিপি লুকানো যেত — আর
     * তাতে কিছুই লুকাত না, কারণ বোর্ডেই সব লেখা।
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.recipe.view'),
        ];
    }

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly RecipeService $recipes,
    ) {}

    public function index(Request $request): View
    {
        return view('inventory::kitchen.index', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouses' => Warehouse::query()->orderBy('name_en')->get(),
            'warehouse' => $this->warehouse($request),
            'dishes' => $this->dishes($request),
        ]);
    }

    /**
     * একই হিসাব, JSON-এ — পাতাটা এটাই বারবার ডাকে।
     *
     * ── কেন আলাদা একটা ঠিকানা, আর কেন API নয় ────────────────────────
     * এটা একই পর্দার একই প্রশ্ন, কেবল আবার জিজ্ঞেস করা। তাই একই
     * সেশন, একই অনুমতি — টোকেন বা `/api/v1` লাগে না। একটা পাতা
     * নিজের সংখ্যাটা আবার আনার জন্য bearer টোকেন বানানো মানে XSS-এর
     * সামনে একটা সত্যিকারের টোকেন রেখে দেওয়া, কোনো লাভ ছাড়াই।
     */
    public function refresh(Request $request): JsonResponse
    {
        return response()->json([
            'at' => now()->format('H:i:s'),
            'dishes' => array_map(
                static fn (array $d): array => [
                    'id' => $d['recipe']->id,
                    'portions' => (int) $d['portions'],
                    'limiting' => $d['limiting']?->name(),
                ],
                $this->dishes($request),
            ),
        ]);
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $id = (int) $request->query('warehouse');

        return $id > 0
            ? Warehouse::query()->find($id)
            : Warehouse::query()->where('is_default', true)->first();
    }

    /**
     * প্রতিটা সচল রেসিপির জন্য: কয় প্লেট, আর কোনটা আটকাচ্ছে।
     *
     * ── ক্রমটা ইচ্ছাকৃত: শূন্যগুলো আগে ───────────────────────────────
     * বোর্ডটা দেখা হয় এক নজরে, আর যেটা **শেষ** সেটাই সবচেয়ে জরুরি
     * খবর — ওটা জানার আগেই অর্ডার নেওয়া হয়ে যায়। বর্ণানুক্রমে সাজালে
     * শেষ হয়ে যাওয়া পদটা তালিকার মাঝখানে হারিয়ে যেত।
     *
     * @return list<array{recipe: Recipe, portions: string, limiting: ?Product}>
     */
    private function dishes(Request $request): array
    {
        $warehouse = $this->warehouse($request);

        $rows = Recipe::query()
            ->with(['product', 'lines.product'])
            ->where('is_active', true)
            ->get()
            ->map(fn (Recipe $recipe): array => [
                'recipe' => $recipe,
                ...$this->recipes->portionsPossible($recipe, $warehouse),
            ])
            ->sortBy([
                fn (array $a, array $b) => (int) $a['portions'] <=> (int) $b['portions'],
                fn (array $a, array $b) => $a['recipe']->product?->name() <=> $b['recipe']->product?->name(),
            ])
            ->values()
            ->all();

        return $rows;
    }
}
