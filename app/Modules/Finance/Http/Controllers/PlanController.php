<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Support\FinancePlan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ফিন্যান্স মানচিত্র — তেত্রিশ বিভাগের কোনটা হয়েছে, কোনটা বাকি।
 *
 * ── কেন এই পর্দাটা ───────────────────────────────────────────────────
 * মালিকের কথা: *"সবকিছু আগে ফ্রন্টএন্ডে এন্ট্রি করে ফেল… দেখলে বুঝা
 * যাবে আমি কোন কাজটা করছি আর কোনটা করি নাই, তখন দেখে দেখে ইমপ্লিমেন্ট
 * করবা।"*
 */
class PlanController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:finance.plan.view')];
    }

    public function index(Request $request): View
    {
        return view('finance::plan.index', [
            'menu' => $this->menu->forUser($request->user()),
            'sections' => FinancePlan::sections(),
            'tally' => FinancePlan::tally(),
        ]);
    }
}
