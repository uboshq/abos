<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\CashForecast;
use App\Modules\Finance\Services\CfoFigures;
use App\Modules\Finance\Services\HeadTotals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * ⭐ অর্থের চার পাতা, ফোনের নিজের পর্দায় — CFO, নগদের পূর্বাভাস, আয়, বাজেটের মাস (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
 *
 *   GET /api/v1/finance/cfo · /cash-forecast · /income?from=&to= · /budget
 *
 * ⛔ সংখ্যা ফোনে কষা নয় — ওয়েবের পাতার একই সেবা ([[CfoFigures]], [[CashForecast]], [[HeadTotals]], [[BudgetService]]), একই চাবি
 * (`finance.forecast.view`, `finance.income.view`, `finance.budget.view`), দেয়াল সেবার নিজের (দেখার শাখা)। ⓘ কেবল দেখা।
 */
final class FinanceFiguresApiController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.forecast.view', only: ['cfo', 'forecast']),
            new Middleware('can:finance.income.view', only: ['income']),
            new Middleware('can:finance.budget.view', only: ['budget']),
        ];
    }

    /** CFO-র পাতা — ওয়েবের [[CfoController::index()]] */
    public function cfo(CfoFigures $figures, CashForecast $forecast, BudgetService $budgets): JsonResponse
    {
        $f = $figures->figures();
        $cash = $forecast->build();
        $budget = $budgets->monthStatus();
        $money = fn (string $key) => ['label' => __('finance::forecast.'.$key), 'value' => Money::format($f[$key] ?? '0'), 'amount' => (string) ($f[$key] ?? '0')];

        return response()->json([
            'figures' => [
                $money('cash'), $money('bank'), $money('mfs'), $money('receivable'), $money('payable'), $money('loans'),
                ['label' => __('finance::forecast.current_ratio'), 'value' => $f['current_ratio'] ?? __('finance::forecast.no_liabilities'), 'amount' => null],
                ['label' => __('finance::forecast.cash_ratio'), 'value' => $f['cash_ratio'] ?? __('finance::forecast.no_liabilities'), 'amount' => null],
            ],
            'liquidity' => ['code' => (string) $f['liquidity'], 'label' => __('finance::forecast.liquidity_'.$f['liquidity']),
                'rule' => __('finance::forecast.liquidity_rule')],
            'in_30_days' => Money::format((string) ($cash['rows'][1]['closing'] ?? $cash['opening'])),
            'budget' => $budget === null ? null : [
                'budget' => Money::format($budget['budget']),
                'actual' => Money::format($budget['actual']),
                'used_pct' => $budget['used_pct'],
            ],
        ]);
    }

    /** নগদের পূর্বাভাস — ওয়েবের [[ForecastController::cash()]] */
    public function forecast(CashForecast $forecast): JsonResponse
    {
        $built = $forecast->build();

        return response()->json([
            'opening' => Money::format($built['opening']),
            'note' => __('finance::forecast.caveat'),
            'rows' => array_map(fn (array $r) => [
                'when' => __('finance::forecast.bucket_'.$r['bucket']),
                'until' => DateFormat::format($r['until']),
                'receivables' => Money::format($r['receivables']),
                'loans_in' => Money::format($r['loans_in']),
                'payables' => Money::format($r['payables']),
                'loans_out' => Money::format($r['loans_out']),
                'net' => Money::format($r['net']),
                'closing' => Money::format($r['closing']),
                'closing_amount' => (string) $r['closing'],
            ], $built['rows']),
        ]);
    }

    /** আয় — ওয়েবের [[IncomeController::index()]]-এর একই মাথা আর একই সময় (না বললে এই মাস) */
    public function income(Request $request, HeadTotals $heads): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $from = $data['from'] ?? Carbon::now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? Carbon::now()->endOfMonth()->toDateString();

        $rows = $heads->forParent(StandardChart::INCOME, min($from, $to), max($from, $to), debitPositive: false);
        // ⓘ ওয়েবের একই ভাগ ([[IncomeController::split()]]) — বিক্রি (ফেরত বাদ) আর বাকি আয়
        $sales = '0';
        $other = '0';

        foreach ($rows as $r) {
            if (in_array($r['account']->code, StandardChart::NET_SALES, true)) {
                $sales = bcadd($sales, (string) $r['now'], 4);
            } else {
                $other = bcadd($other, (string) $r['now'], 4);
            }
        }

        return response()->json([
            'from' => min($from, $to),
            'to' => max($from, $to),
            'rows' => array_map(fn (array $r) => [
                'head' => $r['account']->label(),
                'now' => Money::format($r['now']),
                'before' => Money::format($r['before']),
            ], $rows),
            'sales' => Money::format($sales),
            'other' => Money::format($other),
            'total' => Money::format(bcadd($sales, $other, 4)),
        ]);
    }

    /** এই মাসের বাজেট — ওয়েবের CFO-পাতার একই ঘর ([[BudgetService::monthStatus()]]) */
    public function budget(BudgetService $budgets): JsonResponse
    {
        $status = $budgets->monthStatus();

        return response()->json(['status' => $status === null ? null : [
            'budget' => Money::format($status['budget']),
            'actual' => Money::format($status['actual']),
            'used_pct' => $status['used_pct'],
        ]]);
    }
}
