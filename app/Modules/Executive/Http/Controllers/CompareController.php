<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\Trend;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Executive\Services\Comparison;
use App\Modules\Executive\Services\Figures;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * তুলনা — কোম্পানি বনাম কোম্পানি, শাখা বনাম শাখা, আর নিচে একটা সংখ্যার ধারা।
 *
 * ⓘ ধারাটা [[Trend]] — দিন/সপ্তাহ/মাস/ত্রৈমাসিক/বছর ধরে, প্রতিটা খোপে মডিউলের নিজের সংখ্যা।
 */
final class CompareController extends Controller implements HasMiddleware
{
    /** ধারার পরিসর — খোপ ধরে কতটা পেছনে, যাতে খোপের সংখ্যা পর্দায় ধরে */
    private const REACH = [
        Trend::DAILY => 30,
        Trend::WEEKLY => 7 * 12,
        Trend::MONTHLY => 365,
        Trend::QUARTERLY => 365 * 2,
        Trend::YEARLY => 365 * 5,
    ];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly Comparison $comparison,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:executive.view')];
    }

    public function show(Request $request): View
    {
        $user = $request->user();

        $result = $this->comparison->build(
            $user,
            (string) $request->query('mode', Comparison::COMPANIES),
            $request->integer('company') ?: null,
            (string) $request->query('against', Trend::PREVIOUS_MONTH),
        );

        $grain = in_array($request->query('grain'), Trend::GRAINS, true) ? (string) $request->query('grain') : Trend::MONTHLY;
        $figure = in_array($request->query('figure'), Figures::COMPARED, true) ? (string) $request->query('figure') : Figures::SALES;

        // ⓘ জের-এর সংখ্যার ধারা হয় না (খোপে খোপে "আজ" একই) — তখন বিক্রি
        $figure = Figures::definition($figure)['period'] ? $figure : Figures::SALES;
        $from = Carbon::today()->subDays(self::REACH[$grain] - 1)->toDateString();

        return view('executive::compare', [
            'menu' => $this->menu->forUser($user),
            'result' => $result,
            'grain' => $grain,
            'figure' => $figure,
            'trend' => $this->comparison->trend($user, $result['rows'], $figure, $grain, $from, Carbon::today()->toDateString()),
            // ⓘ পাশে গত বছরের একই খোপ — একই নিয়মে ([[Trend::previous()]]), যাতে মৌসুম বাদে বোঝা যায়
            'lastYear' => $this->comparison->trend($user, $result['rows'], $figure, $grain,
                ...array_values(Trend::previous(ReportEngine::COMPARE_LAST_YEAR, $from, Carbon::today()->toDateString()) ?? [])),
        ]);
    }
}
