<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\FinalSettlement;
use App\Modules\Hr\Models\GratuityProvision;
use App\Modules\Hr\Services\Gratuity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ গ্র্যাচুইটির রিপোর্ট — একটা দিনে প্রতিজনের গোনা বছর, দিন, শেষ মূল আর দায়, নিচে যোগ; আর মাসের সঞ্চিতি বসানো (fe, ২১ অক্টোবর
 * ২০২৬, দফা ৮; [[Gratuity]])। ⛔ পুরোটাই টাকা — দেখা বেতনের চাবিতে, বসানো বেতনের রান চালানোর চাবিতে।
 */
class GratuityController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly Gratuity $gratuity,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.salary.view'),
            new Middleware('can:hr.payroll.manage', only: ['store']),
        ];
    }

    public function report(Request $request): View
    {
        $raw = (string) $request->query('on');
        $on = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && strtotime($raw) !== false
            ? Carbon::createFromFormat('Y-m-d', $raw)->startOfDay()
            : now()->subMonthNoOverflow()->endOfMonth()->startOfDay();

        $rows = collect($this->gratuity->rows($on, $request->user()));

        return view('hr::gratuity.report', [
            'menu' => $this->menu->forUser($request->user()),
            'on' => $on,
            'rows' => $rows,
            'total' => $rows->reduce(fn ($c, $r) => bcadd($c, (string) $r['obligation'], 4), '0'),
            // ⓘ খাতার ২১৩৪-এর জের — সঞ্চিতির যোগ − চূড়ান্ত পাওনায় মেটানো গ্র্যাচুইটি ([[FinalSettlements]]), এই কোম্পানির
            'booked' => bcsub(
                (string) GratuityProvision::query()->where('company_id', CompanyContext::id())->whereDate('month', '<=', $on->toDateString())->sum('amount'),
                (string) FinalSettlement::query()->where('company_id', CompanyContext::id())->where('status', FinalSettlement::APPROVED)
                    ->whereDate('last_day', '<=', $on->toDateString())->sum('gratuity'),
                4,
            ),
            'enabled' => $this->gratuity->enabled(),
        ]);
    }

    /** ⭐ "মাসের সঞ্চিতি বসান" — হাতে, যেকোনো মাস; একবারই বসে */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $month = Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->startOfMonth();

        $made = $this->gratuity->accrue($month);

        return redirect()->route('hr.gratuity.report', ['on' => $month->copy()->endOfMonth()->toDateString()])
            ->with('saved', __($made === [] ? 'hr::message.gratuity_nothing' : 'hr::message.gratuity_booked', ['month' => $month->format('M Y')]));
    }
}
