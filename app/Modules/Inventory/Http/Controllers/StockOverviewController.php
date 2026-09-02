<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মজুদের ড্যাশবোর্ড — এক নজরে গুদাম।
 *
 * ── কেন হোম পর্দাই যথেষ্ট ছিল না ────────────────────────────────────
 * হোম পর্দা ([[WorkspaceController::dashboard()]]) সব মডিউলের **করণীয়**
 * এক জায়গায় রাখে — "ছয়টা চালান মেয়াদ পেরিয়েছে" ধরনের। ওটা দিনের
 * শুরুর পর্দা।
 *
 * গুদামের লোকের প্রশ্ন আলাদা, আর সেটা সারাদিনের: **কত মাল আছে, তার
 * কতটা সত্যিই বেচা যাবে, কী ফুরিয়ে আসছে, আর আজ কী কী নড়ল।** ওই চারটা
 * প্রশ্নের উত্তর আজ চারটা আলাদা পর্দায় ছড়ানো ছিল।
 *
 * ── প্রতিটা সংখ্যা [[StockFacts]] থেকে ───────────────────────────────
 * কন্ট্রোলারে একটাও হিসাব নেই, ইচ্ছাকৃতভাবে। এখানে একটা `SUM` লিখলে
 * সেটা হত ওই সংখ্যার **দ্বিতীয় সংজ্ঞা**, আর একদিন স্টক পর্দার সাথে
 * মিলত না — ঠিক যে ভুলটা বিক্রয়ে একবার ঘটেছিল ([[SalesMetrics]]-এর
 * মন্তব্য দেখুন)।
 */
class StockOverviewController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly StockFacts $facts,
    ) {}

    /**
     * ── কেন `inventory.stock.view`, নতুন কোনো অনুমতি নয় ──────────────
     * এই পর্দাটা নতুন কিছু দেখায় না; স্টক পর্দায় যা আছে তারই সারাংশ।
     * আলাদা অনুমতি বানালে একজনকে স্টক দেখার চাবি দেওয়ার পরেও আলাদা
     * করে এটার চাবি দিতে হত, আর কেউ সেটা মনে রাখতেন না — ফলে পর্দাটা
     * কার্যত কারও কাছেই থাকত না।
     */
    public static function middleware(): array
    {
        return [new Middleware('can:inventory.stock.view')];
    }

    public function index(Request $request): View
    {
        $warehouse = $this->warehouseAsked($request);

        return view('inventory::stock.overview', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'warehouse' => $warehouse,

            'states' => $this->facts->states($warehouse?->id),
            'belowReorder' => $this->facts->belowReorder(),
            'outOfStock' => $this->facts->outOfStock(),

            /*
             * ⚠️ `null` হতে পারে, আর পর্দাটা সেটা সামলায়।
             *
             * মজুদের মূল্য একটা খরচের সংখ্যা, তাই [[FieldSecurity]]
             * সেটা `inventory.cost.view`-এর পেছনে রাখে। অনুমতি না
             * থাকলে এখানে শূন্য নয়, **কিছুই না** আসে — শূন্য দেখালে
             * কেউ ভাবতেন গুদাম খালি।
             */
            'value' => $this->facts->value(),

            'movementsToday' => $this->facts->movementsToday(),
            'flow' => $this->facts->monthlyFlow(),
            'lowStock' => $this->facts->lowStock(),
            'recent' => $this->facts->recentMovements(),
        ]);
    }

    /**
     * কোন গুদামের কথা জিজ্ঞেস করা হয়েছে।
     *
     * মানটা কোয়েরি-স্ট্রিং থেকে আসে, তাই যেকোনো কিছু হতে পারে। না
     * মিললে সব গুদাম — পর্দা ভাঙে না, কেবল প্রশ্নটা চওড়া হয়।
     */
    private function warehouseAsked(Request $request): ?Warehouse
    {
        $id = (int) $request->query('warehouse');

        return $id > 0 ? Warehouse::query()->find($id) : null;
    }
}
