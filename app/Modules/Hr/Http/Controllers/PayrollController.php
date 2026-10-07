<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Support\BranchReach;
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
    use GrandTotals;

    public function __construct(
        private readonly PayrollService $payroll,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.payroll.view', only: ['index', 'show', 'bankFile']),
            /*
             * ⛔ ব্যাংকের ফাইলে প্রতিটা কর্মীর হিসাব আর রাউটিং নম্বর — পরিচয়ের চাবিও লাগে (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ HR ⛔৩)।
             * ⓘ মডিউল এই ঘরগুলোকে `hr.identity.view`-এর বলে ([[FieldSecurity]]), অথচ ফাইলটা কেবল বেতন দেখার চাবিতে নামত।
             */
            new Middleware('can:hr.identity.view', only: ['bankFile']),
            new Middleware('can:hr.payroll.manage', only: ['create', 'store', 'rebuild', 'confirm', 'cancel']),
        ];
    }

    public function index(Request $request): View
    {
        return view('hr::payroll.index', [
            'menu' => $this->menu->forUser($request->user()),
            // ⭐ সর্বমোট — ছাঁকা তালিকার সব পাতা মিলে ([[GrandTotals]]); পাতা ভাঙার আগে, কারণ paginate() কোয়েরিতে সীমা বসায়
            'grand' => $this->grandTotals($list = PayrollRun::query()
                ->with('branch')
                /*
                 * ⭐ খোঁজা — টুলবারের ঘরটা সত্যিই কাজ করে (১৯ সেপ্টেম্বর ২০২৬)।
                 * ⓘ রানের নম্বর আর বিবরণ।
                 */
                ->when(trim((string) $request->query('q')) ?: null, fn ($q, $term) => $q->where(
                    fn ($w) => $w->where('document_no', 'like', "%{$term}%")
                        ->orWhere('narration', 'like', "%{$term}%"),
                ))
                ->orderByDesc('month')->orderByDesc('id'),
                ['gross_total' => 't.gross_total', 'deduction_total' => 't.deduction_total', 'net_total' => 't.net_total']),
            // ⓘ withQueryString — পরের পাতায় গেলে খোঁজা আর ঘনত্ব হারায় না
            'runs' => $list->paginate(50)->withQueryString(),
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
        $run->load(['branch', 'creator']);

        /*
         * ⛔ কেবল নাগালের কর্মীদের স্লিপ, আর তাদেরই মোট — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⛔২)। ⓘ রান পুরো কোম্পানির; আগে
         * সীমিত ম্যানেজার রানটা দেখতে পেলে সব কর্মীর মোট, কর্তন আর নিট দেখতেন। সীমা না থাকলে (মালিক) রানের নিজের মোটই।
         */
        $reach = app(BranchReach::class);
        $slips = $reach->throughEmployee($run->payslips()->getQuery(), $request->user())->with(['employee', 'lines'])->get();
        $run->setRelation('payslips', $slips);
        $limited = $reach->branches($request->user()) !== null;

        return view('hr::payroll.show', [
            'menu' => $this->menu->forUser($request->user()),
            'run' => $run,
            'summary' => $limited ? [
                'count' => $slips->count(),
                'gross' => (string) $slips->sum('gross'),
                'deductions' => (string) $slips->sum('deductions'),
                'net' => (string) $slips->sum('net'),
            ] : [
                'count' => $run->employee_count,
                'gross' => (string) $run->gross_total,
                'deductions' => (string) $run->deduction_total,
                'net' => (string) $run->net_total,
            ],
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

        // ⛔ কেবল নাগালের কর্মীদের সারি (অডিট HR ⛔২)
        $file = $this->payroll->bankFile($run, request()->user());

        return response($file['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
        ]);
    }
}
