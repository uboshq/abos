<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Dashboard\SalesOverview;
use App\Modules\Sales\Metrics\SalesPeriod;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বিক্রয়ের পর্দা — কার্ড, ধারা আর ভাগ, বাছা সময়কাল ধরে (NEXUS §৪)।
 *
 * ── কেন দরজার চাবি `sales.report`, নতুন `sales.dashboard.view` নয় ──────
 * পাতাটা একটা প্রতিবেদন — কাগজ তৈরি হয় না, কেবল পড়া হয়; আর বিক্রয়ের
 * বাকি প্রতিবেদনগুলো (গ্রাহক ধরে, পণ্য ধরে, ব্র্যান্ড ধরে) এই চাবিতেই।
 * ⚠️ নতুন চাবি বানালে রোলের ছাঁচ **পুরনো রোলকে চওড়া করে না**
 * ([[role-templates-never-widen-an-existing-role]]), তাই লাইভে কেউই পাতাটা
 * পেতেন না যতক্ষণ না কেউ হাতে দিতেন — আর ভেতরের প্রতিটা কার্ড তো
 * নিজের চাবি আলাদা করেই দেখে ([[SalesOverview]])।
 */
class SalesOverviewController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SalesOverview $overview,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.report')];
    }

    public function index(Request $request): View
    {
        $period = SalesPeriod::fromQuery($request->query());
        $board = $this->overview->for($request->user(), $period);

        return view('sales::overview.index', [
            'menu' => $this->menu->forUser($request->user()),
            'period' => $period,
            'cards' => $board['cards'],
            'panels' => $board['panels'],
        ]);
    }
}
