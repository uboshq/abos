<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\ProductTargetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * পণ্যের মাসিক বিক্রির লক্ষ্য — বসানো আর মেলানো। মালিক, ২৩ নভেম্বর ২০২৬ ([[ProductTargetService]])।
 *
 * ⓘ একটা ফর্মে মাসের সব পণ্য — মাসের শুরুতে একবারে বসানো; অনেক হলে ইমপোর্ট (`product_target`)।
 * ⓘ চাবি বিক্রয়ের লক্ষ্যমাত্রার দুইটাই (`sales.target.view`, `sales.target.manage`) — নতুন চাবি নয়: লক্ষ্য যিনি বসান তিনি একই মানুষ।
 */
class ProductTargetController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ProductTargetService $targets,
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

        return view('sales::product-target.index', [
            'menu' => $this->menu->forUser($request->user()),
            'month' => $month,
            'rows' => $this->targets->board($month),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date'],
            'target' => ['nullable', 'array'],
            'target.*.qty' => ['nullable', 'numeric', \App\Modules\Sales\Http\Requests\PlainNumber::RULE, 'min:0'],
            'target.*.amount' => ['nullable', 'numeric', \App\Modules\Sales\Http\Requests\PlainNumber::RULE, 'min:0'],
        ]);

        $month = $this->targets->readMonth($data['month']);

        $this->targets->setForMonth($month, $data['target'] ?? []);

        return redirect()
            ->route('sales.product_target.index', ['month' => $month->format('Y-m')])
            ->with('saved', __('sales::product_target.saved'));
    }
}
