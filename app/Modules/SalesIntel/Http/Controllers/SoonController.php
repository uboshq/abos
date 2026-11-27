<?php

declare(strict_types=1);

namespace App\Modules\SalesIntel\Http\Controllers;

use App\Core\Services\MenuBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ "শীঘ্রই আসছে" — সেলস ইন্টেলিজেন্সের প্রতিটা মেনুর পাতা, আসল পর্দা বানানো পর্যন্ত (মালিক, ২৮ নভেম্বর ২০২৬)।
 *
 * ⓘ পাতায় থাকে: পাতার নাম, এটা কী করবে (পরিকল্পনা থেকে), কোন ধাপে আসবে। কিছু লেখে না, কিছু পড়ে না — ডাটাবেস ছোঁয় না।
 */
final class SoonController
{
    /**
     * পাতা → [মেনুর চাবি, ধাপ]। ⓘ ধাপ = পরিকল্পনার ক্রম (০ সংজ্ঞা … ৭ পাইলট)।
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const PAGES = [
        'dashboard' => ['dashboard', 5], 'today' => ['today', 3],
        'geography' => ['geography', 2], 'distributors' => ['distributors', 2], 'outlets' => ['outlets', 2],
        'routes' => ['routes', 2], 'team' => ['team', 2], 'products' => ['products', 2],
        'ims' => ['ims', 3], 'delivery' => ['delivery', 3], 'returns' => ['returns', 3], 'collection' => ['collection', 3],
        'primary' => ['primary', 3], 'distributor-summary' => ['distributor_summary', 5], 'reconcile' => ['reconcile', 3],
        'incentive-schemes' => ['incentive_schemes', 4], 'incentive-calc' => ['incentive_calc', 4],
        'budget' => ['budget', 4], 'targets' => ['targets', 4], 'forecast' => ['forecast', 4],
        'waiting' => ['waiting', 3], 'submitted' => ['submitted', 3],
        'r-daily-ims' => ['r_daily_ims', 5], 'r-delivery' => ['r_delivery', 5], 'r-lifting' => ['r_lifting', 5],
        'r-distributor' => ['r_distributor', 5], 'r-incentive' => ['r_incentive', 5], 'r-collection' => ['r_collection', 5],
        'r-am-daily' => ['r_am_daily', 5], 'r-rsm' => ['r_rsm', 5], 'r-dsm' => ['r_dsm', 5], 'r-national' => ['r_national', 5],
        'r-budget' => ['r_budget', 5], 'r-annual' => ['r_annual', 5],
        'settings' => ['settings', 1], 'api' => ['api', 6], 'abos-link' => ['abos_link', 6], 'import' => ['import', 6],
    ];

    public function __invoke(Request $request, string $page, MenuBuilder $menu): View
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        [$key, $phase] = self::PAGES[$page];

        return view('sales_intel::soon', [
            'menu' => $menu->forUser($request->user()),
            'title' => __('sales_intel::menu.'.$key),
            'what' => __('sales_intel::soon.'.$key),
            'phase' => $phase,
        ]);
    }
}
