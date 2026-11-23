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
        // ⓘ অনুমতির সাথে কর্মীর শাখাও — [[EmployeePolicy]]
        $this->authorize('viewSalary', $employee);
        // ⭐ দেখা নিরীক্ষায়, অঙ্ক ছাড়া (ঘাটতির তালিকা #১৮; [[SensitiveViews]])
        app(\App\Modules\Hr\Services\SensitiveViews::class)->seen($employee, 'salary_viewed', $employee);

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
            // ⭐ সইয়ের অপেক্ষায় থাকা বদল — এই পাতা বেতনের চাবিতেই খোলে, তাই অঙ্কসহ (§৮ ⚠️৫)
            'waiting' => $this->waitingOf($employee),
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('manageSalary', $employee);

        /*
         * ⛔ decimal:0,4 — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[ASalaryAmountOfOneE5IsAReasonNotACrashTest]])। ⓘ `numeric` "1e5"
         * মেনে নিত, আর [[SalaryStructureService]]-এর bcmath সেটা পড়তে না পেরে পাতা ৫০০ দিত; খুব বড় অঙ্ক ঘরের (১৮,৪) বাইরেও যেত।
         * চার ঘর — ঘরের দশমিক আর ফর্মের step দুটোই চার; খরচের দাবির নিয়মের ([[ExpenseClaimController]]) একই গড়ন।
         */
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'amounts' => ['required', 'array', 'min:1'],
            'amounts.*' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999999'],
        ], [
            'amounts.*.decimal' => __('hr::validation.salary_amount_shape'),
            'amounts.*.max' => __('hr::validation.salary_amount_shape'),
        ]);

        /*
         * ⭐ এক দরজা — বেতন হয়ে যাওয়া মাস থামে, ছক চালু থাকলে দ্বিতীয় সইয়ের অপেক্ষা (§৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬;
         * [[SalaryStructureService::change()]])। ⓘ আগে এখানে খাত ধরে সরাসরি `set()` — একজনই বসাতেন, দেওয়া মাসের অঙ্কও নীরবে
         * বদলাত, আর দ্বিতীয় খাতের ভুলে প্রথম খাত বসে থেকে যেত; এখন সব খাত আগে যাচাই, তারপর এক লেনদেনে।
         */
        $waiting = $this->salaries->change($employee, (string) $data['effective_from'], $data['amounts']);

        return redirect()
            ->route('hr.employee.salary', $employee)
            ->with('saved', $waiting === null ? __('hr::message.salary_saved') : __('hr::message.salary_change_waiting'));
    }

    /**
     * ⓘ সইয়ের অপেক্ষায় থাকা বদল — অনুরোধ, তারিখ আর খাত ধরে অঙ্ক; না থাকলে `null`।
     *
     * ⓘ সইকারী ইনবক্স থেকে কর্মীর পাতা হয়ে এখানে আসেন — অনুরোধের পাতায় অঙ্ক নেই, কারণ সেটা নিরীক্ষকও খোলেন
     * ([[SalaryStructureService::change()]])।
     *
     * @return array{approval: \App\Models\Approval, effective_from: string, lines: list<array{head: ?SalaryHead, amount: string}>}|null
     */
    private function waitingOf(Employee $employee): ?array
    {
        $approval = $this->salaries->waitingChange($employee);
        $proposal = $approval === null ? null : $this->salaries->proposalOf($approval);

        if ($proposal === null) {
            return null;
        }

        $heads = SalaryHead::query()->whereKey(array_keys($proposal['lines']))->get()->keyBy('id');

        return [
            'approval' => $approval,
            'effective_from' => $proposal['effective_from'],
            'lines' => array_map(
                fn (int $headId, string $amount) => ['head' => $heads->get($headId), 'amount' => $amount],
                array_keys($proposal['lines']),
                array_values($proposal['lines']),
            ),
        ];
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

        /*
         * ⛔ কেবল Y-m-d, আর সত্যিকারের দিন — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[TheSalaryScreenTakesOnlyADateTest]])।
         * ⓘ আগে `?on=abc` সোজা Carbon::parse-এ যেত আর পাতা ৫০০ দিত। এখন অন্য যেকোনো লেখায় আজকের ছবি — ঠিকানায় তারিখ না দিলে যা হয়।
         */
        if (! is_string($on) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $on, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return now();
        }

        return Carbon::parse($on);
    }
}
