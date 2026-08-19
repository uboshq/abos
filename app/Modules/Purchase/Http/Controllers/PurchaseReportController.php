<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\View\View;

/**
 * ক্রয়ের তিনটা রিপোর্ট, হিসাবের পর্দার ভিউ দিয়েই।
 *
 * ভিউটা ReportDefinition ছাড়া আর কিছু জানে না, তাই নতুন ভিউ লেখার মানে
 * হত একই টেবিল আবার লেখা (সেকশন ১৯.৮)।
 */
class PurchaseReportController extends Controller implements HasMiddleware
{
    /**
     * slug → [রিপোর্টের কী, যে চাবি লাগে]।
     *
     * ── চাবিটা এখানে কেন, রুটে নয় ────────────────────────────
     * আগে রুটটা সব স্লাগের জন্য একটাই চাবি চাইত: `purchase.report`।
     * তাতে নিষ্পত্তির সারিটা মেনুতে চাইত `purchase.settlement.view`, আর
     * রুট চাইত `purchase.report` — যাঁর একটা আছে অন্যটা নেই, তিনি
     * রোজ নিজের পর্দায় সারিটা দেখতেন আর ক্লিক করলে ৪০৩ পেতেন।
     * ধরেছে `TheMenuAsksWhatTheRouteAsksTest`।
     *
     * এখানে রাখার কারণ, রিপোর্টগুলো একটাই রুট ভাগ করে —
     * রুটে বসানো মানে প্রতিটা রিপোর্টের জন্য আলাদা রুট।
     *
     * @var array<string, array{key: string, permission: string}>
     */
    private const SLUGS = [
        'pending-orders' => ['key' => 'purchase.pending_orders', 'permission' => 'purchase.report'],
        'uninvoiced' => ['key' => 'purchase.uninvoiced', 'permission' => 'purchase.report'],
        'by-supplier' => ['key' => 'purchase.by_supplier', 'permission' => 'purchase.report'],

        /*
         * দুইটাই ক্রয়মূল্য ও মার্জিন খুলে দেখায়, তাই নিজের চাবি।
         */
        'settlement' => ['key' => 'purchase.settlement', 'permission' => 'purchase.settlement.view'],
        'return-on-capital' => ['key' => 'purchase.return_on_capital', 'permission' => 'purchase.settlement.view'],
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        /*
         * রুটে সবার জন্য একটা চাবি নেই — প্রতিটা স্লাগ নিজেরটা
         * চায়, `show()`-এ। `auth` রুট-গোষ্ঠীতেই আছে।
         */
        return [];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $this->authorize(self::SLUGS[$slug]['permission']);

        $key = self::SLUGS[$slug]['key'];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            $request->only(['from', 'to', 'branch_id', 'top', 'compare']),
            page: max(1, (int) $request->query('page', 1)),
        );

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => $definition->hasFilter('branch')
                ? Branch::query()->active()->orderBy('name_en')->get()
                : collect(),
            'accounts' => collect(),
        ]);
    }
}
