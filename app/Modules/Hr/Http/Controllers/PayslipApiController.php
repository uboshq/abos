<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Services\ExpenseClaimService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ ফোনে নিজের বেতনের স্লিপ — মাস ধরে তালিকা আর একটা স্লিপের আয়-কর্তন (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
 *
 * `GET /api/v1/hr/payslips?page=` · `GET /api/v1/hr/payslips/{public_id}`
 *
 * ⓘ অঙ্কগুলো বেতনের রানের নিজের স্লিপের সারি — ওয়েবের ছাপা স্লিপের একই উৎস ([[PayslipPrintController::documentFor()]]): মোট আয়,
 * মোট কর্তন, নিট, আর খাত ধরে লাইন। কিছুই নতুন করে গোনা হয় না।
 *
 * ⛔ কেবল নিজের: কর্মী আসে লগইন থেকে ([[ExpenseClaimService::employeeOf()]]); অন্যের স্লিপের ঠিকানা ৪০৪। ⛔ খসড়া আর বাতিল রানের
 * স্লিপ নয় — খসড়া এখনো বদলায় (ওয়েবের ছাপায় তাই বড় করে "খসড়া" লেখা থাকে), আর ঐ অঙ্ক হাতে নিয়ে কেউ টাকা চাইতে পারতেন।
 * ⓘ রানের শাখার দেয়াল নিজের স্লিপে খাটে না — রানটা যে শাখারই হোক, স্লিপটা তাঁর নিজের।
 * ⛔ ব্যাংক হিসাব আর MFS নম্বর আসে না — ফোনে লাগে না, আর হারানো ফোনে পড়ে থাকত।
 */
final class PayslipApiController extends Controller implements HasMiddleware
{
    private const PER_PAGE = 24;

    public function __construct(private readonly ExpenseClaimService $claims) {}

    public static function middleware(): array
    {
        return [new Middleware('can:hr.payslip.self')];
    }

    public function mine(Request $request): JsonResponse
    {
        $employee = $this->claims->employeeOf($request->user());

        if ($employee === null) {
            return response()->json(['employee' => false, 'rows' => [], 'next_page' => null]);
        }

        $page = $this->own($employee)
            ->join('hr_payroll_runs as run', 'run.id', '=', 'hr_payslips.payroll_run_id')
            ->orderByDesc('run.month')->orderByDesc('hr_payslips.id')
            ->select('hr_payslips.*')
            ->paginate(\App\Core\Support\PerPage::of(self::PER_PAGE));

        return response()->json([
            'employee' => true,
            'rows' => collect($page->items())->map(fn (Payslip $slip) => $this->facts($slip))->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $employee = $this->claims->employeeOf($request->user());
        abort_if($employee === null, 404);

        /** @var Payslip $slip */
        $slip = $this->own($employee)->where('hr_payslips.public_id', $id)->with('lines')->firstOrFail();

        return response()->json($this->facts($slip) + [
            'name' => $employee->name('bn'),
            'code' => (string) $employee->code,
            'payment_method' => (string) ($slip->payment_method ?? ''),
            // ⓘ আয় আগে, কর্তন পরে — সারির নিজের ক্রমে ([[Payslip::lines()]])
            // ⛔ প্রতিষ্ঠানের অংশ এখানে নয় — ফোন আয় আর কর্তন ছাড়া তৃতীয় কিছু চেনে না, আর এটা নিটে ঢোকে না ([[PayslipLine::EMPLOYER]])
            'lines' => $slip->lines->reject(fn (PayslipLine $line) => $line->isEmployerShare())->map(fn (PayslipLine $line) => [
                'name' => $line->headName('bn'),
                'earning' => $line->isEarning(),
                'amount' => Money::round((string) $line->amount, 2),
            ])->values(),
            // ⭐ ভবিষ্য তহবিলে প্রতিষ্ঠানের ভাগ — আলাদা ঘরে (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFund]])
            'employer_lines' => $slip->lines->filter(fn (PayslipLine $line) => $line->isEmployerShare())->map(fn (PayslipLine $line) => [
                'name' => $line->headName('bn'),
                'amount' => Money::round((string) $line->amount, 2),
            ])->values(),
        ]);
    }

    /**
     * নিজের স্লিপ — পাকা রানের; রানের শাখার দেয়াল ছাড়া (স্লিপটা নিজের)।
     *
     * @return Builder<Payslip>
     */
    private function own(Employee $employee): Builder
    {
        return Payslip::query()
            ->where('hr_payslips.employee_id', $employee->id)
            ->whereHas('run', fn (Builder $run) => $run->withoutGlobalScope('user-branch')
                ->whereIn('status', DocumentStatus::POSTED))
            ->with(['run' => fn ($run) => $run->withoutGlobalScope('user-branch')]);
    }

    /** @return array<string, mixed> */
    private function facts(Payslip $slip): array
    {
        return [
            'id' => (string) $slip->public_id,
            'month' => $slip->run?->month?->toDateString(),
            'run_no' => (string) ($slip->run?->document_no ?? ''),
            'gross' => Money::round((string) $slip->gross, 2),
            'deductions' => Money::round((string) $slip->deductions, 2),
            'net' => Money::round((string) $slip->net, 2),
            /*
             * ⭐ নতুন ঘর, কেবল যোগ — বেতনের স্লিপ না উৎসব ভাতার (`salary` | `bonus`; fe-র সিদ্ধান্ত ৩, ২১ অক্টোবর ২০২৬)। ⛔ আগের কোনো ঘরের
             * নাম বা মান বদলায় না — অ্যাপ 0.4.42 যা পড়ে তা অবিকল ([[APayslipSaysWhetherItIsSalaryOrBonusTest]])। ⓘ পুরনো রানে ঘর খালি হলে বেতন।
             */
            'run_type' => (string) ($slip->run?->run_type ?: PayrollRun::SALARY),
        ];
    }
}
