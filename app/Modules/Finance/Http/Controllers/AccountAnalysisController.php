<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Services\AccountAnalysis;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * খাত বিশ্লেষণ — মানচিত্র §৪। শিরোনাম · বর্ণনা → খাত আর সময় বাছা →
 * মাস ধরে · কাগজের ধরন ধরে · পক্ষ ধরে।
 *
 * ⓘ অনুমতি খতিয়ানের (`accounts.report`): একই দাখিলা, কেবল অন্যভাবে
 * গোছানো — যিনি খতিয়ান দেখতে পারেন না, তিনি এটাও না।
 */
class AccountAnalysisController extends Controller implements HasMiddleware
{
    /** @var list<string> */
    public const PERIODS = ['this_year', 'last_year', 'last_12', 'custom'];

    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report')];
    }

    public function index(Request $request, AccountAnalysis $analysis): View
    {
        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : 'this_year';

        [$from, $to] = $this->range($period, $request);

        $account = $request->integer('account_id') > 0
            ? Account::query()->find($request->integer('account_id'))
            : null;

        return view('finance::account-analysis.index', [
            'menu' => $this->menu->forUser($request->user()),
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'account' => $account,
            /*
             * ⛔ দল বাদ — ২১ সেপ্টেম্বর ২০২৬। তালিকায় দল থাকলে কেউ
             * বেছে ফেলতেন, আর দলের নিজের কোনো সারি নেই বলে পর্দা
             * খালি দেখাত — বা ভেঙে পড়ত।
             */
            'accounts' => Account::query()
                ->postable()
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
                ->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])
                ->all(),
            'report' => $account !== null ? $analysis->of($account, $from, $to) : null,
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function range(string $period, Request $request): array
    {
        $today = Carbon::today();

        return match ($period) {
            'last_year' => [$today->copy()->subYear()->startOfYear()->toDateString(),
                $today->copy()->subYear()->endOfYear()->toDateString()],
            'last_12' => [$today->copy()->subMonthsNoOverflow(11)->startOfMonth()->toDateString(), $today->toDateString()],
            'custom' => [
                $this->date($request->query('from')) ?? $today->copy()->startOfYear()->toDateString(),
                $this->date($request->query('to')) ?? $today->toDateString(),
            ],
            default => [$today->copy()->startOfYear()->toDateString(), $today->toDateString()],
        };
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
