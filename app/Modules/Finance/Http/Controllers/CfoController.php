<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\CashForecast;
use App\Modules\Finance\Services\CfoFigures;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * CFO ড্যাশবোর্ড — মানচিত্র §১। এক পাতায়: টাকা কোথায়, কে কত দেবে, কাকে
 * কত দিতে হবে, তারল্য কেমন, আর সামনের ৩০ দিনে হাতে কত থাকবে।
 */
class CfoController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CfoFigures $figures,
        private readonly CashForecast $forecast,
        private readonly BudgetService $budgets,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:finance.forecast.view', only: ['index'])];
    }

    public function index(Request $request): View
    {
        $forecast = $this->forecast->build();

        return view('finance::cfo.index', [
            'menu' => $this->menu->forUser($request->user()),
            'f' => $this->figures->figures(),
            // ⓘ ৩০ দিনের শেষে হাতে কত — পূর্বাভাসের দ্বিতীয় ঝুড়ি
            'in30' => $forecast['rows'][1]['closing'] ?? $forecast['opening'],
            'budget' => $this->budgets->monthStatus(),
        ]);
    }
}
