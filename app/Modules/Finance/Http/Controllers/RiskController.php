<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\RiskBoard;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ঝুঁকির ড্যাশবোর্ড — মানচিত্র §১। কোন জিনিসগুলো আজ দেখা দরকার।
 *
 * ⓘ কেবল পড়ার পর্দা, আর প্রতিটা সংখ্যা আগে থেকেই কোথাও গোনা হয়
 * ([[RiskBoard]])।
 */
class RiskController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly RiskBoard $risks,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:finance.forecast.view', only: ['index'])];
    }

    public function index(Request $request): View
    {
        return view('finance::risk.index', [
            'menu' => $this->menu->forUser($request->user()),
            'risks' => $this->risks->risks(),
        ]);
    }
}
