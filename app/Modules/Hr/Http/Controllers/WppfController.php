<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\FinancialYear;
use App\Modules\Hr\Services\WppfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল — বছরের হিসাব আর "সই দিন" (শ্রম আইন ২০০৬, অধ্যায় ১৫; a6, ২১ অক্টোবর ২০২৬; [[WppfService]])।
 *
 * ⛔ কোম্পানির সুইচ বন্ধ থাকলে দুই দরজাই ৪০৪ — মালিকের কাছেও; মেনুর সারিও একই সুইচে ঢাকা (module.php-র `setting`)।
 * ⛔ শাখায় সীমিত মানুষ নন — মুনাফাটা গোটা কোম্পানির, আধা হিসাব দেখানো মানে ভুল তহবিল।
 */
class WppfController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly WppfService $wppf,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.wppf.view', only: ['show']),
            new Middleware('can:hr.wppf.manage', only: ['provision']),
        ];
    }

    public function show(Request $request): View
    {
        $this->guard($request);

        $years = FinancialYear::query()->orderByDesc('starts_on')->get();
        $chosen = $request->filled('year')
            ? $years->firstWhere('id', (int) $request->query('year'))
            : (FinancialYear::forDate(now()) ?? $years->first());

        abort_if($chosen === null, 404);

        return view('hr::wppf.show', [
            'menu' => $this->menu->forUser($request->user()),
            'years' => $years,
            'year' => $chosen,
            'figures' => $this->wppf->figuresFor($chosen),
            'provision' => $this->wppf->provisionFor($chosen),
        ]);
    }

    public function provision(Request $request, int $year): RedirectResponse
    {
        $this->guard($request);

        $chosen = FinancialYear::query()->findOrFail($year);
        $this->wppf->provision($chosen, $request->user());

        return redirect()->route('hr.wppf.show', ['year' => $chosen->id])->with('saved', __('hr::wppf.posted'));
    }

    private function guard(Request $request): void
    {
        abort_unless($this->wppf->enabled(), 404);
        abort_unless($this->wppf->seesTheWholeCompany($request->user()), 403);
    }
}
