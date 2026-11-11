<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\WorkingCapital;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ চলতি মূলধনের পুরো পাতা — অর্থের ড্যাশবোর্ডের ছোট ঘরের "পুরো পাতা →" (মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL)।
 *
 * ⓘ আগে ড্যাশবোর্ডের নিচে বসা বড় ঘরটাই (তারিখ, 6/12 মাসের গড়, waterfall — নমুনা TWYacgufwfzT9ZqR1eqvaW) এখানে নিজের পাতায়।
 * ⛔ কেবল মালিকের চাবি (finance.working_capital.view; মালিক, 10 নভেম্বর 2026 সন্ধ্যা)।
 */
class WorkingCapitalController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly WorkingCapital $workingCapital,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:finance.working_capital.view')];
    }

    public function show(Request $request): View
    {
        return view('finance::working-capital.show', [
            'menu' => $this->menu->forUser($request->user()),
            'wc' => $this->workingCapital->on(self::date($request), (int) $request->query('wc_span', '6')),
        ]);
    }

    /** চলতি মূলধনের তারিখ — ঠিকানার `wc_date`; না থাকলে, ভুল হলে বা আগামী দিনের হলে আজ (ভবিষ্যতের খাতা নেই)। */
    public static function date(Request $request): Carbon
    {
        $today = Carbon::today();
        $asked = $request->query('wc_date');

        if (! is_string($asked) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked) !== 1) {
            return $today;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $asked)->startOfDay();
        } catch (\Throwable) {
            return $today;
        }

        return $date->greaterThan($today) ? $today : $date;
    }
}
