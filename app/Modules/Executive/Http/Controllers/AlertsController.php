<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Executive\Services\Alerts;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\CompanyLens;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * সতর্কতা — সব কোম্পানির, এক ছকে: সারিতে সতর্কতা, কলামে কোম্পানি।
 *
 * ⓘ প্রতিটা সংখ্যা একটা রিপোর্টের সারি গোনা ([[Alerts]]); চাপলে ঠিক সেই তালিকা।
 * ⓘ হেডারে শাখা বাছা থাকলে চলতি কোম্পানির ঘর কেবল সেই শাখার — "আজ" পাতার একই নিয়ম।
 */
final class AlertsController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly Alerts $alerts,
        private readonly CompanyLens $lens,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:executive.view')];
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        $header = $this->lens->headerBranch($user);
        $current = (int) ($user->current_company_id ?? 0);

        $companies = array_map(
            fn (array $c) => [...$c, 'only' => $c['id'] === $current ? $header : null],
            $this->lens->companies($user),
        );

        return view('executive::alerts', [
            'menu' => $this->menu->forUser($user),
            'companies' => $companies,
            'alerts' => $this->alerts->all($user, $companies),
            'waiting' => $this->alerts->waiting($user, $companies, 20),
            'cacheSeconds' => Board::CACHE_SECONDS,
        ]);
    }
}
