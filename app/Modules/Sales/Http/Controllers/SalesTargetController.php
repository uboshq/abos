<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\SalesTargetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * লক্ষ্যমাত্রা — বসানো ও মেলানো, একই পর্দায়।
 *
 * ── কেন দুইটা আলাদা পর্দা নয় ────────────────────────────────────────
 * মালিক মাসের শুরুতে টার্গেট বসান আর মাসজুড়ে ওটাই দেখতে আসেন। আলাদা
 * করলে "দেখার" পর্দায় দাঁড়িয়ে সংখ্যাটা বদলাতে গেলে অন্য পর্দায় যেতে
 * হত, আর ওখানে গিয়ে আবার মাস বাছতে হত।
 */
class SalesTargetController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SalesTargetService $targets,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.target.view', only: ['index']),
            new Middleware('can:sales.target.manage', only: ['store']),
        ];
    }

    public function index(Request $request): View
    {
        $month = $this->targets->readMonth($request->query('month'));

        return view('sales::target.index', [
            'menu' => $this->menu->forUser($request->user()),
            'month' => $month,
            'rows' => $this->targets->scoreboard($month),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date'],
            'amount' => ['nullable', 'array'],
            'amount.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $month = $this->targets->readMonth($data['month']);

        $this->targets->assertMonthIsSane($month);

        $this->targets->setForMonth($month, $data['amount'] ?? []);

        return redirect()
            ->route('sales.target.index', ['month' => $month->format('Y-m')])
            ->with('saved', __('sales::message.targets_saved'));
    }
}
