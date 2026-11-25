<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
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
            new Middleware('can:hr.payroll.view', only: ['index', 'show', 'bankFile', 'leftOut']),
            /*
             * ⛔ ব্যাংকের ফাইলে প্রতিটা কর্মীর হিসাব আর রাউটিং নম্বর — পরিচয়ের চাবিও লাগে (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ HR ⛔৩)।
             * ⓘ মডিউল এই ঘরগুলোকে `hr.identity.view`-এর বলে ([[FieldSecurity]]), অথচ ফাইলটা কেবল বেতন দেখার চাবিতে নামত।
             */
            new Middleware('can:hr.identity.view', only: ['bankFile', 'leftOut']),
            new Middleware('can:hr.payroll.manage', only: ['create', 'store', 'rebuild', 'confirm', 'cancel']),
        ];
    }

    public function index(Request $request): View
    {
        /*
         * ⛔ সীমিত ম্যানেজারের তালিকায় কেবল নাগালের কর্মীদের মোট — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ৫;
         * [[ThePayrollListShowsOnlyMyPeopleTest]])। ⓘ রানের পাতা ([[show()]]) আগেই নাগাল মানত, তালিকা মানত না: প্রতিটা রানের সারিতে
         * গোটা কোম্পানির জন, মোট, কর্তন আর নিট। এখন সীমা থাকলে প্রতিটা রানের অঙ্ক নাগালের বেতনশিট থেকে গোনা; সীমা না থাকলে (মালিক)
         * রানের নিজের মোটই, আগের মতো।
         */
        $reach = app(BranchReach::class);
        // ⭐ নাগাল, আর হেডারে একটা শাখা বাছা থাকলে সেটা — তালিকার মোট দেখার শাখার কর্মীদের ([[BranchReach::viewing()]], fe, ২১ অক্টোবর ২০২৬)
        $limited = $reach->viewing($request->user()) !== null;
        $seen = fn (string $sum) => $reach->throughEmployeeInView(Payslip::query(), $request->user())
            ->whereColumn('hr_payslips.payroll_run_id', 'hr_payroll_runs.id')
            ->selectRaw($sum)->toBase();

        /*
         * ⛔ বেতনের অঙ্ক কেবল `hr.salary.view`-এ — সব জায়গায় এক নিয়ম (fe, ২১ অক্টোবর ২০২৬)। ⓘ চাবি ছাড়া রানগুলো দেখা যায়, অঙ্কের
         * ঘরে [[FieldSecurity::mask()]]; সর্বমোটও গোনা হয় না, আর তালিকার ফাইল (CSV) পর্দার ঘরই নেয়, তাই সেখানেও ঢাকা।
         */
        $pay = $request->user()->can('hr.salary.view');

        // ⛔ রান কোম্পানির খাতার কাগজ — কর্মী-খাতা এক হলেও তালিকা আর মোট এই কোম্পানির
        $list = PayrollRun::query()->where('hr_payroll_runs.company_id', \App\Core\Support\CompanyContext::id())
                ->with('branch')
                ->when($limited, fn ($q) => $q->select('hr_payroll_runs.*')
                    ->selectSub($seen('COUNT(*)'), 'seen_count')
                    ->selectSub($seen('COALESCE(SUM(hr_payslips.gross), 0)'), 'seen_gross')
                    ->selectSub($seen('COALESCE(SUM(hr_payslips.deductions), 0)'), 'seen_deductions')
                    ->selectSub($seen('COALESCE(SUM(hr_payslips.net), 0)'), 'seen_net'))
                /*
                 * ⭐ খোঁজা — টুলবারের ঘরটা সত্যিই কাজ করে (১৯ সেপ্টেম্বর ২০২৬)।
                 * ⓘ রানের নম্বর আর বিবরণ।
                 */
                ->when(trim((string) $request->query('q')) ?: null, fn ($q, $term) => $q->where(
                    fn ($w) => $w->where('document_no', 'like', "%{$term}%")
                        ->orWhere('narration', 'like', "%{$term}%"),
                ))
                ->when(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) (is_string($request->query('month')) ? $request->query('month') : '')) === 1,
                    fn ($q) => $q->whereDate('month', '>=', $request->query('month').'-01')
                        ->whereDate('month', '<=', \Illuminate\Support\Carbon::parse($request->query('month').'-01')->endOfMonth()->toDateString()))
                ->orderBy('month', $request->query('sort') === 'oldest' ? 'asc' : 'desc')
                ->orderBy('id', $request->query('sort') === 'oldest' ? 'asc' : 'desc');

        return view('hr::payroll.index', [
            'menu' => $this->menu->forUser($request->user()),
            'pay' => $pay,
            // ⭐ সর্বমোট — ছাঁকা তালিকার সব পাতা মিলে ([[GrandTotals]]); পাতা ভাঙার আগে, কারণ paginate() কোয়েরিতে সীমা বসায়
            'grand' => ! $pay ? [] : $this->grandTotals($list, $limited
                ? ['gross_total' => 't.seen_gross', 'deduction_total' => 't.seen_deductions', 'net_total' => 't.seen_net']
                : ['gross_total' => 't.gross_total', 'deduction_total' => 't.deduction_total', 'net_total' => 't.net_total']),
            // ⓘ withQueryString — পরের পাতায় গেলে খোঁজা আর ঘনত্ব হারায় না
            'runs' => $list->paginate(\App\Core\Support\PerPage::of())->withQueryString(),
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
        /*
         * ⛔ সামনের মাস নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[APayrollMonthAndDateStayInsideTheirLimitsTest]])।
         * ⓘ আগে ২০২৭-০৩-এর মতো মাসও নেওয়া হত, আর নিশ্চিত করলে না-খাটা মাসের বেতন আগাম খরচে বসত (IAS 19: কাজ হলে তবে খরচ)।
         * দিনটা মাসের আগে হতে পারে না — সেটা [[PayrollService::build()]]-এ, সব দরজার জন্য।
         */
        /*
         * ⭐ উৎসব ভাতার রান — নিজের দিন ধরে (যোগ্যতা সেদিন মাপা হয়), মাস নয় (a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])।
         */
        if ($request->input('run_type') === PayrollRun::BONUS) {
            $bonus = $request->validate([
                'bonus_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'narration' => ['nullable', 'string', 'max:200'],
            ]);

            $run = $this->payroll->buildBonus($bonus['bonus_date'], $bonus['narration'] ?? null);

            return redirect()
                ->route('hr.payroll.show', $run)
                ->with('saved', __('hr::message.run_built', ['count' => $run->employee_count]));
        }

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'month.before_or_equal' => __('hr::validation.payroll_month_in_future'),
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
        // ⭐ দেখার শাখা — হেডারে একটা শাখা বাছা থাকলে কেবল তার কর্মীর স্লিপ আর মোট ([[BranchReach::viewing()]], fe, ২১ অক্টোবর ২০২৬)
        $slips = $reach->throughEmployeeInView($run->payslips()->getQuery(), $request->user())->with(['employee', 'lines'])->get();
        $run->setRelation('payslips', $slips);
        $limited = $reach->viewing($request->user()) !== null;
        // ⭐ রানের বেতন খোলা — নিরীক্ষায়, অঙ্ক ছাড়া (ঘাটতির তালিকা #১৮; [[SensitiveViews]])
        app(\App\Modules\Hr\Services\SensitiveViews::class)->seen($run, 'payroll_viewed');

        return view('hr::payroll.show', [
            'menu' => $this->menu->forUser($request->user()),
            'run' => $run,
            // ⛔ বেতনের অঙ্ক কেবল `hr.salary.view`-এ — মোট আর প্রতিটা স্লিপ, চাবি ছাড়া ঢাকা (fe, ২১ অক্টোবর ২০২৬)
            'pay' => $request->user()->can('hr.salary.view'),
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
            // ⭐ করের ধাপ বসানো নেই — উৎসে কিছুই কাটা হয়নি, পাতায় সেটা স্পষ্ট (a6, ২১ অক্টোবর ২০২৬; [[SalaryTax::note()]])
            'taxNote' => $run->isBonus() ? null : app(\App\Modules\Hr\Services\SalaryTax::class)->note(),
            // ব্যাংকে কতজনের বেতন যাবে — ফাইলটা নামানোর আগে জানা দরকার
            'bankRows' => $run->payslips->where('payment_method', 'bank')
                ->filter(fn ($s) => bccomp((string) $s->net, '0', 4) > 0)->count(),
            // ⭐ ব্যাংক ফাইলে কারা নেই আর কেন — আটকে থাকা স্লিপ (fe, ২১ অক্টোবর ২০২৬; [[PayrollService::leftOutOfTheBankFile()]])
            'leftOut' => $this->payroll->leftOutOfTheBankFile($run, $request->user()),
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
    /**
     * ⭐ ব্যাংক ফাইলের সঙ্গী-তালিকা — কারা ফাইলে নেই আর কেন (fe, ২১ অক্টোবর ২০২৬; [[PayrollService::leftOutOfTheBankFile()]])।
     */
    public function leftOut(PayrollRun $run): Response
    {
        abort_unless($run->isConfirmed(), 404);

        $csv = "\u{FEFF}".__('hr::payroll.left_out_head')."\r\n";

        foreach ($this->payroll->leftOutOfTheBankFile($run, request()->user()) as $row) {
            $csv .= implode(',', array_map(fn (string $v) => '"'.str_replace('"', '""', ltrim($v, "=+-@\t\r")).'"',
                [$row['code'], $row['name'], $row['reason'], $row['paid'] ? '' : $row['deadline']]))."\r\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="salary-'.$run->month->format('Y-m').'-'.$run->document_no.'-left-out.csv"',
        ]);
    }

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
