<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বেতনের রান — তালিকা, তৈরি, নিশ্চিতকরণ, ব্যাংক ফাইল।
 */
class PayrollController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.payroll.view', only: ['index', 'show', 'bankFile']),
            new Middleware('can:hr.payroll.manage', only: ['create', 'store', 'rebuild', 'confirm', 'cancel']),
        ];
    }

    public function index(Request $request): View
    {
        return view('hr::payroll.index', [
            'menu' => $this->menu->forUser($request->user()),
            'runs' => PayrollRun::query()
                ->with('branch')
                ->orderByDesc('month')->orderByDesc('id')
                ->paginate(50),
        ]);
    }

    public function create(Request $request): View
    {
        return view('hr::payroll.create', [
            'menu' => $this->menu->forUser($request->user()),
            // গত মাস, কারণ বেতন সাধারণত মাস শেষ হলে হয়
            'month' => now()->subMonthNoOverflow()->startOfMonth()->format('Y-m'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $run = $this->payroll->build($data['month'].'-01', $data['trx_date'] ?? null);

        return redirect()
            ->route('hr.payroll.show', $run)
            ->with('saved', __('hr::message.run_built', ['count' => $run->employee_count]));
    }

    public function show(Request $request, PayrollRun $run): View
    {
        $run->load(['payslips.employee', 'payslips.lines', 'branch', 'creator']);

        return view('hr::payroll.show', [
            'menu' => $this->menu->forUser($request->user()),
            'run' => $run,
            // ব্যাংকে কতজনের বেতন যাবে — ফাইলটা নামানোর আগে জানা দরকার
            'bankRows' => $run->payslips->where('payment_method', 'bank')
                ->filter(fn ($s) => bccomp((string) $s->net, '0', 4) > 0)->count(),
        ]);
    }

    public function rebuild(PayrollRun $run): RedirectResponse
    {
        $this->payroll->rebuild($run);

        return back()->with('saved', __('hr::message.run_rebuilt'));
    }

    public function confirm(PayrollRun $run): RedirectResponse
    {
        $this->payroll->confirm($run);

        return back()->with('saved', __('hr::message.run_confirmed'));
    }

    public function cancel(Request $request, PayrollRun $run): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->payroll->cancel($run, $reason);

        return back()->with('saved', __('hr::message.run_cancelled'));
    }

    /**
     * ব্যাংকের ফাইল নামানো।
     *
     * খসড়া রানের ফাইল দেওয়া হয় না: টাকা পাঠানোর নির্দেশ যেন কেবল
     * নিশ্চিত করা বেতন থেকেই বেরোয়, নাহলে খাতায় না বসা টাকা ব্যাংকে
     * চলে যেতে পারত।
     */
    public function bankFile(PayrollRun $run): Response
    {
        abort_unless($run->isConfirmed(), 404);

        $file = $this->payroll->bankFile($run);

        return response($file['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
        ]);
    }
}
