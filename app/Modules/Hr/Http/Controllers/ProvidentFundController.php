<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\ProvidentFund;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ ভবিষ্য তহবিলের প্রস্তাবিত বদল — প্রতিজনের আজকের আর প্রস্তাবিত কর্মী-ভাগ, আর একটা তারিখ থেকে বসানো (a6, ২১ অক্টোবর ২০২৬;
 * [[ProvidentFund::proposal()]], [[ProvidentFund::apply()]])।
 *
 * ⛔ পুরো পাতাটাই টাকা — দেখা `hr.salary.view`, বসানো `hr.salary.manage` (বেতনের কাঠামোর একই দুই চাবি)।
 */
class ProvidentFundController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ProvidentFund $fund,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.salary.view'),
            new Middleware('can:hr.salary.manage', only: ['store', 'enrol']),
        ];
    }

    public function proposal(Request $request): View
    {
        $on = $this->dateFrom($request);

        return view('hr::pf.proposal', [
            'menu' => $this->menu->forUser($request->user()),
            'on' => $on,
            'enabled' => $this->fund->enabled(),
            'head' => $this->fund->fundHead(),
            'employeePercent' => $this->fund->employeePercent(),
            'employerPercent' => $this->fund->employerPercent(),
            'rows' => collect($this->fund->proposal($on, $request->user())),
            // ⭐ সদস্য নন, অথচ এক বছরের চাকরি — আলাদা তালিকা, আলাদা "তহবিলে যোগ" (BLA ধারা ২৬৪; fe, দফা ১)
            'candidates' => collect($this->fund->eligibleNonMembers($on, $request->user())),
        ]);
    }

    /** ⭐ একজনকে তহবিলে যোগ — বেছে নেওয়া তারিখ থেকে; নিজে থেকে কেউ যোগ হন না */
    public function enrol(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('viewSalary', $employee);

        $data = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d']]);
        $from = Carbon::createFromFormat('Y-m-d', $data['effective_from'])->startOfDay();

        $waiting = $this->fund->enrol($employee, $from, $request->user());

        return redirect()
            ->route('hr.pf.proposal', ['on' => $from->toDateString()])
            ->with('saved', __($waiting === null ? 'hr::message.pf_enrol_done' : 'hr::message.pf_enrol_waiting', ['name' => $employee->label()]));
    }

    /** ⭐ "বসান" — বেছে নেওয়া তারিখ থেকে, কেবল যাঁদের হার আলাদা; ফল এক লাইনে, বাদ পড়াদের কারণসহ */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        $from = Carbon::createFromFormat('Y-m-d', $data['effective_from'])->startOfDay();
        $result = $this->fund->apply($from, $request->user());

        return redirect()
            ->route('hr.pf.proposal', ['on' => $from->toDateString()])
            ->with('saved', __('hr::message.pf_applied', [
                'applied' => $result['applied'],
                'waiting' => $result['waiting'],
                'skipped' => count($result['skipped']),
            ]))
            ->with('pf_skipped', collect($result['skipped'])
                ->map(fn (array $row) => $row['employee']->label().' — '.$row['reason'])->all());
    }

    /** ⓘ ঠিকানার `?on=` — ভুল লেখা বা খালি হলে পরের মাসের প্রথম দিন (হার সাধারণত মাসের শুরু থেকে বদলায়) */
    private function dateFrom(Request $request): Carbon
    {
        $raw = (string) $request->query('on');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && strtotime($raw) !== false
            ? Carbon::createFromFormat('Y-m-d', $raw)->startOfDay()
            : now()->addMonthNoOverflow()->startOfMonth();
    }
}
