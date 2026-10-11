<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Support\ScreenAddress;
use App\Http\Controllers\Controller;
use App\Models\SavedView;
use App\Modules\Executive\Services\CompanyLens;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * রিপোর্ট — মালিকের কেন্দ্র থেকে রিপোর্ট সেন্টারের দরজা: সব রিপোর্ট, নিজের সংরক্ষিত দৃশ্য, নির্ধারিত
 * রিপোর্ট আর ফাইল নামানো।
 *
 * ⛔ এখানে কোনো রিপোর্ট নতুন করে বানানো হয় না — কেন্দ্রীয় [[ReportEngine]]-ই সব; এটা কেবল পথ দেখায়।
 * ⓘ সংরক্ষিত দৃশ্য কোম্পানির — অন্য কোম্পানির দৃশ্য খুলতে আগে সেখানে যেতে হয় ([[OpenController]])।
 */
final class ReportsController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CompanyLens $lens,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:executive.view')];
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        $companies = collect($this->lens->companies($user))->keyBy('id');

        $views = SavedView::acrossAllCompanies()
            ->where('user_id', $user->id)
            ->whereIn('company_id', $companies->keys()->all() ?: [0])
            ->orderBy('company_id')
            ->orderBy('name')
            ->get()
            ->map(function (SavedView $view) use ($companies): ?array {
                [$name, $param] = ScreenAddress::split((string) $view->screen);
                $route = Route::getRoutes()->getByName($name);

                if ($route === null || ! ScreenAddress::accepts((string) $view->screen)) {
                    return null;
                }

                parse_str((string) $view->query, $query);
                $params = $param === null ? $query : [$route->parameterNames()[0] => $param, ...$query];

                return [
                    'company_id' => (int) $view->company_id,
                    'company_name' => $companies[(int) $view->company_id]['name'],
                    'name' => (string) $view->name,
                    'route' => $name,
                    'params' => array_filter($params, fn ($v) => is_scalar($v)),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return view('executive::reports', [
            'menu' => $this->menu->forUser($user),
            'companies' => $companies->values()->all(),
            'views' => $views,
            'formats' => ListExport::FORMATS,
            'canSchedule' => $user->can('system_admin.reports.schedule') && Route::has('system_admin.reports.schedule.index'),
        ]);
    }
}
