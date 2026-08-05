<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\SalaryStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * একজন কর্মীর বেতনের কাঠামো — তারিখ ধরে।
 *
 * পর্দাটা দুই ভাগে: উপরে এখন কার্যকর অঙ্কগুলো, নিচে নতুন তারিখ থেকে
 * নতুন অঙ্ক বসানোর ফর্ম। পুরনো সারি সম্পাদনার কোনো পথ নেই — বেতন
 * বাড়ানো মানে নতুন সারি, নাহলে গত মাসের বেতনশিট বদলে যেত।
 */
class SalaryStructureController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SalaryStructureService $salaries,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.salary.view', only: ['edit']),
            new Middleware('can:hr.salary.manage', only: ['store']),
        ];
    }

    public function edit(Request $request, Employee $employee): View
    {
        $on = $this->asOf($request);

        return view('hr::employee.salary', [
            'menu' => $this->menu->forUser($request->user()),
            'employee' => $employee,
            'heads' => SalaryHead::query()->active()
                ->orderBy('kind')->orderBy('sort_order')->orderBy('code')->get(),
            'components' => $this->salaries->componentsOn($employee, $on),
            'totals' => $this->salaries->totalsOn($employee, $on),
            'history' => $employee->structures()->with('salaryHead', 'creator')->get(),
            'on' => $on,
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'amounts' => ['required', 'array', 'min:1'],
            'amounts.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $heads = SalaryHead::query()->active()->get()->keyBy('id');

        foreach ($data['amounts'] as $headId => $amount) {
            $head = $heads->get((int) $headId);

            // খালি ঘর মানে "এই খাতে কিছু নয়" — শূন্য বসানো নয়, কারণ
            // শূন্য বসালে পরের মাসেও ওই খাতটা শূন্য হয়ে টিকে থাকত
            if ($head === null || blank($amount)) {
                continue;
            }

            $this->salaries->set($employee, $head, $data['effective_from'], (string) $amount);
        }

        return redirect()
            ->route('hr.employee.salary', $employee)
            ->with('saved', __('hr::message.salary_saved'));
    }

    /**
     * কোন তারিখের ছবি দেখা হচ্ছে।
     *
     * ঠিকানায় তারিখ দিলে সেই দিনের কাঠামো, নাহলে আজকের — তাই "গত
     * জানুয়ারিতে ওর বেতন কত ছিল" প্রশ্নের উত্তর একটা লিংকেই মেলে।
     */
    private function asOf(Request $request): Carbon
    {
        $on = $request->query('on');

        return filled($on) ? Carbon::parse((string) $on) : now();
    }
}
