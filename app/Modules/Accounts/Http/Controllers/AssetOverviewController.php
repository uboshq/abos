<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Dashboard\FixedAssetDashboard;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Services\AssetTaxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ স্থায়ী সম্পদের ড্যাশবোর্ড আর করের অবচয়ের পাতা (স্থায়ী সম্পদ ধাপ ৫)।
 *
 * ⓘ ড্যাশবোর্ড ইঞ্জিনের একই পর্দায় আঁকা ([[FixedAssetDashboard]]); করের হিসাব বছর ধরে, হার শ্রেণিতে ([[AssetTaxService]])।
 */
class AssetOverviewController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.asset.view', only: ['dashboard', 'tax']),
            new Middleware('can:accounts.asset.manage', only: ['taxRun']),
        ];
    }

    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $dashboard = FixedAssetDashboard::dashboard();

        // ⓘ চাবিহীন সংখ্যা ঢাকা আর চাবিহীন টাইল বাদ — ইঞ্জিনের নিয়মেই
        $dashboard = new DashboardDefinition(
            title: $dashboard->title,
            subtitle: $dashboard->subtitle,
            stats: array_values(array_filter($dashboard->stats, fn ($s) => $s->permission === null || $user->can($s->permission))),
            panels: $dashboard->panels,
            listings: $dashboard->listings,
            tiles: array_values(array_filter($dashboard->tiles, fn ($t) => $user->can($t->permission))),
        );

        return view(config('abos.dashboards_v2') ? 'dashboard.module-v2' : 'dashboard.module', [
            'menu' => $this->menu->forUser($user),
            'module' => 'accounts',
            'dashboard' => $dashboard,
        ]);
    }

    public function tax(Request $request, AssetTaxService $tax): View
    {
        [$start, $end] = $tax->yearOf($request->query('on', Carbon::today()->toDateString()));

        return view('accounts::asset.tax', [
            'menu' => $this->menu->forUser($request->user()),
            'start' => $start,
            'end' => $end,
            'categories' => AssetCategory::query()->orderBy('code')->get(),
        ]);
    }

    public function taxRun(Request $request, AssetTaxService $tax): RedirectResponse
    {
        $data = $request->validate(['on' => ['required', 'date']]);
        $done = $tax->run($data['on']);

        return redirect()->route('accounts.report.show', ['slug' => 'asset-book-vs-tax', 'from' => $done['year_end'], 'to' => $done['year_end']])
            ->with('status', __('accounts::asset_report.tax_done', ['count' => $done['assets'], 'year' => $done['year_end']]));
    }
}
