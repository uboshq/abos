<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Executive\Services\Figures;
use App\Modules\Executive\Services\Snapshots;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * আগের দিনের হিসাব — "গত মাসের এই দিনে বাকি কত ছিল?"
 *
 * ⓘ সব সংখ্যা রাতের হিসাব থেকে ([[Snapshots]]) — ঐ রাতে পর্দায় যা ছিল, আজকের খাতা থেকে নতুন করে
 * গোনা নয়। ঐ দিনের হিসাব না থাকলে পাতা সেটাই বলে, আন্দাজ করে না।
 */
final class HistoryController extends Controller implements HasMiddleware
{
    /** ধারা কত দিনের */
    private const DAYS = 90;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly Snapshots $snapshots,
        private readonly CompanyLens $lens,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:executive.view')];
    }

    public function show(Request $request): View
    {
        $user = $request->user();

        // ⓘ না বললে গত মাসের আজকের দিন — মালিকের প্রশ্নটাই এটা
        try {
            $date = Carbon::parse((string) $request->query('date', ''))->toDateString();
        } catch (Throwable) {
            $date = null;
        }

        $date = $request->filled('date') && $date !== null && $date <= Carbon::today()->toDateString()
            ? $date
            : Carbon::today()->subMonthNoOverflow()->toDateString();

        $figure = in_array($request->query('figure'), Figures::KEYS, true) ? (string) $request->query('figure') : Figures::RECEIVABLE;

        $header = $this->lens->headerBranch($user);
        $current = (int) ($user->current_company_id ?? 0);
        $companies = array_map(
            fn (array $c) => [...$c, 'only' => $c['id'] === $current ? $header : null],
            $this->lens->companies($user),
        );

        return view('executive::history', [
            'menu' => $this->menu->forUser($user),
            'date' => $date,
            'figure' => $figure,
            'rows' => $this->snapshots->on($user, $companies, $date),
            'series' => $this->snapshots->series($user, $companies, $figure,
                Carbon::parse($date)->subDays(self::DAYS - 1)->toDateString(), $date),
            // ⓘ পাশে গত মাসের একই দিন — একই রাতের হিসাব থেকে
            'monthBefore' => $this->snapshots->series($user, $companies, $figure,
                Carbon::parse($date)->subDays(self::DAYS - 1)->subMonthNoOverflow()->toDateString(),
                Carbon::parse($date)->subMonthNoOverflow()->toDateString()),
        ]);
    }
}
