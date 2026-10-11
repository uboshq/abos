<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Executive\Reports\ExecutiveReports;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Executive\Services\Rankings;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * বিশ্লেষণ — সব কোম্পানি মিলিয়ে সেরা দশ, লোকসানি ক্রেতা, কম মার্জিনের পণ্য, বাকির বয়স, অচল মাল।
 *
 * ⓘ প্রতিটা তালিকা একটা কেন্দ্রীয় রিপোর্টের নিজের সারি ([[Rankings]]); সারিতে চাপলে ঐ ক্রেতা বা
 * পণ্যের খাতা, ঐ কোম্পানিতে বসে।
 */
final class AnalysisController extends Controller implements HasMiddleware
{
    public const MONTH = 'month';

    public const QUARTER = 'three_months';

    public const YEAR = 'year';

    /** @var list<string> */
    public const PERIODS = [self::MONTH, self::QUARTER, self::YEAR];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly Rankings $rankings,
        private readonly CompanyLens $lens,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:executive.view')];
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : self::MONTH;

        $range = [
            'from' => match ($period) {
                self::QUARTER => Carbon::today()->startOfMonth()->subMonths(2)->toDateString(),
                self::YEAR => Carbon::today()->startOfYear()->toDateString(),
                default => Carbon::today()->startOfMonth()->toDateString(),
            },
            'to' => Carbon::today()->toDateString(),
        ];

        $header = $this->lens->headerBranch($user);
        $current = (int) ($user->current_company_id ?? 0);
        $companies = array_map(
            fn (array $c) => [...$c, 'only' => $c['id'] === $current ? $header : null],
            $this->lens->companies($user),
        );

        return view('executive::analysis', [
            'menu' => $this->menu->forUser($user),
            'period' => $period,
            'range' => $range,
            'many' => count($companies) > 1,
            'lists' => [
                'customers' => $this->rankings->top($user, $companies, 'sales.by_customer', 'total', 10, $range),
                'products' => $this->rankings->top($user, $companies, 'sales.by_product', 'revenue', 10, $range),
                'areas' => $this->rankings->top($user, $companies, 'sales.by_route', 'sales', 10, $range),
                'salespeople' => $this->rankings->top($user, $companies, 'sales.by_salesperson', 'net_sales', 10, $range),
                // ⓘ লোকসান আগে — রিপোর্টের নিজের ক্রম; খরচের চাবি না থাকলে লাভের ঘর আসেই না
                'losing' => $this->rankings->top($user, $companies, ExecutiveReports::PROFIT_BY_CUSTOMER, 'gross_profit', 10, $range, ascending: true),
                'thin' => $this->rankings->top($user, $companies, 'sales.by_product', 'margin_percent', 10, $range, ascending: true),
                'dead' => $this->rankings->top($user, $companies, 'inventory.slow_dead', 'value', 10),
            ],
            'ageing' => $this->rankings->totals($user, $companies, 'customer.ageing',
                ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90', 'outstanding'], ['to' => $range['to']]),
            'canSeeCost' => $user->can(ExecutiveReports::COST_KEY),
        ]);
    }
}
