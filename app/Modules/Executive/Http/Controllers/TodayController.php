<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Series;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Executive\Services\Alerts;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Figures;
use App\Modules\Executive\Services\Rankings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * "আজ" — মালিকের এক পর্দা: আটটা সংখ্যা, কোম্পানি × শাখার ছক, সতর্কতা, সই, ধারা।
 *
 * ⓘ পাতা কেবল সাজায় — প্রতিটা সংখ্যা [[Board]]/[[Alerts]]/[[Rankings]] থেকে, আর
 * তারা মডিউলের নিজের সংজ্ঞা থেকে। ⭐ গোটা পাতা ১৯২০×১০৮০-এ, পাতা সরে না।
 */
final class TodayController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly Board $board,
        private readonly Alerts $alerts,
        private readonly Rankings $rankings,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:executive.view')];
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        $companyId = $request->integer('company') ?: null;
        $branchId = $request->integer('branch') ?: null;

        $board = $this->board->build($user, (string) $request->query('period', Figures::TODAY), $companyId, $branchId);
        $companies = array_map(fn (array $c) => ['id' => $c['id'], 'name' => $c['name'], 'only' => $c['only']], $board['companies']);

        $month = ['from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => Carbon::today()->toDateString()];

        return view('executive::today', [
            'menu' => $this->menu->forUser($user),
            'board' => $board,
            'alerts' => $this->alerts->all($user, $companies),
            'waiting' => $this->alerts->waiting($user, $companies, 6),
            'topCustomers' => $this->rankings->top($user, $companies, 'sales.by_customer', 'total', 5, $month),
            'topProducts' => $this->rankings->top($user, $companies, 'sales.by_product', 'revenue', 5, $month),
            'trend' => $this->series($board['trend']),
            'filters' => ['company' => $companyId, 'branch' => $branchId, 'period' => $board['period']],
        ]);
    }

    /** "এখনই নতুন করে" — মানুষটার নিজের ক্যাশ বাসি, অন্যের নয় */
    public function refresh(Request $request): RedirectResponse
    {
        $this->board->refresh($request->user());

        return back()->with('saved', __('executive::today.refreshed'));
    }

    /**
     * @param  list<array{label: string, date: string, sales: string, collections: string}>  $trend
     */
    private function series(array $trend): ?Series
    {
        if ($trend === []) {
            return null;
        }

        return new Series(
            label: __('executive::today.trend_title', ['days' => Board::TREND_DAYS]),
            points: array_map(fn (array $p) => ['label' => $p['label'], 'first' => $p['sales'], 'second' => $p['collections']], $trend),
            firstLabel: __('executive::figure.sales'),
            secondLabel: __('executive::figure.collections'),
            chart: 'line',
            range: DateRange::label(Carbon::parse($trend[0]['date']), Carbon::parse($trend[count($trend) - 1]['date'])),
        );
    }
}
