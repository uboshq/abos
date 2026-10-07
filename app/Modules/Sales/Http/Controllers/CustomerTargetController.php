<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\CustomerTargetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ডিলারের মাসিক আদায়ের লক্ষ্য — বসানো আর মেলানো। মালিক, ৩ অক্টোবর ২০২৬ ([[CustomerTargetService]])।
 *
 * ⓘ একটা ফর্মে মাসের সব ডিলার — মাসের শুরুতে একবারে বসানো; অনেক হলে ইমপোর্ট (`customer_target`)।
 */
class CustomerTargetController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly CustomerTargetService $targets,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.customer_target.view', only: ['index']),
            new Middleware('can:sales.customer_target.manage', only: ['store']),
        ];
    }

    public function index(Request $request): View
    {
        $month = $this->targets->readMonth($request->query('month'));

        return view('sales::customer-target.index', [
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
            'target.*.amount' => ['nullable', 'numeric', 'min:0'],
            'target.*.closes_on' => ['nullable', 'date'],
        ]);

        $month = $this->targets->readMonth($data['month']);

        $this->targets->setForMonth($month, $data['target'] ?? []);

        return redirect()
            ->route('sales.customer_target.index', ['month' => $month->format('Y-m')])
            ->with('saved', __('sales::customer_target.saved'));
    }
}
