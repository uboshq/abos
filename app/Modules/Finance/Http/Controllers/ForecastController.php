<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\CashForecast;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/** নগদের পূর্বাভাস — মানচিত্র §৮। হিসাব [[CashForecast]]-এ। */
class ForecastController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CashForecast $forecast,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:finance.forecast.view', only: ['cash'])];
    }

    public function cash(Request $request): View
    {
        return view('finance::forecast.cash', [
            'menu' => $this->menu->forUser($request->user()),
            'forecast' => $this->forecast->build(),
        ]);
    }
}
