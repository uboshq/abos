<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\MaternityDelivery;
use App\Modules\Hr\Services\MaternityDeliveryService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\LeaveTypeDefaults;
use App\Modules\Hr\Support\BranchReach;
use App\Modules\Hr\Support\LeaveSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ছুটির আবেদন ও তার ধরন।
 */
class LeaveController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly LeaveService $leave,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            // ⓘ তালিকার দরজা নীতিতে ([[LeaveApplicationPolicy::viewAny()]]) — চাবি, বা দল থাকা ব্যবস্থাপক; মেনুর সারিও তাই
            new Middleware('can:viewAny,'.LeaveApplication::class, only: ['index']),
            new Middleware('can:hr.leave.view', only: ['create', 'types']),
            new Middleware('can:hr.leave.manage', only: ['store', 'cancel', 'storeType', 'installTypes']),
            // ⓘ চাবি দরজায়; "কার ছুটি" — কর্মীর নিজের ব্যবস্থাপক বা HR — সেবায় ([[LeaveSigner]], ঘাটতির তালিকা #১৫)
            new Middleware('can:hr.leave.approve', only: ['approve', 'reject', 'recordDelivery', 'signDelivery', 'rejectDelivery']),
        ];
    }

    public function index(Request $request): View
    {
        // ⭐ শাখার দেয়াল — কেবল নাগালের কর্মীদের আবেদন (চূড়ান্ত অডিট ⛔১৭, [[BranchReach]])
        // ⭐ আর হেডারের বাছা শাখা ([[BranchReach::viewing()]], fe, ২১ অক্টোবর ২০২৬)
        // ⭐ সাথে নিজের দলের আবেদন, নাগালের বাইরে হলেও — সারিতে বোতামের বদলে "HR-এর কাছে গেছে" ([[LeaveSigner::fallsToHr()]])
        // ⭐ চাবি ছাড়া ব্যবস্থাপক — কেবল নিজের দলের আবেদন, শাখার বাকিদের নয় (a6, ২১ অক্টোবর ২০২৬)
        $query = ($request->user()->can('hr.leave.view')
                ? LeaveSigner::listFor($request->user())
                : LeaveApplication::query()->whereIn('hr_leave_applications.employee_id', app(LeaveSigner::class)->teamOf($request->user())))
            ->with(['employee', 'leaveType', 'decider'])
            ->when($request->boolean('pending'), fn ($q) => $q->pending())
            // ⭐ কর্মীর নাম, কোড বা মোবাইলে খোঁজা — কর্মীর তালিকার একই খোঁজা ([[Employee::scopeSearch()]]); নাগালের দেয়াল ওপরেই
            ->when(is_string($request->query('q')) && filled($request->query('q')), fn ($q) => $q->whereHas('employee', fn ($e) => $e->search($request->query('q'))));

        $sort = $this->applySort($query, $request, $this->sorts());

        $applications = $query->paginate(\App\Core\Support\PerPage::of())->withQueryString();

        return view('hr::leave.index', [
            'menu' => $this->menu->forUser($request->user()),
            'applications' => $applications,
            'onlyPending' => $request->boolean('pending'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
        ]);
    }

    /**
     * আসন্ন ছুটি আগে — তালিকাটা খোলা হয় "কে কবে থাকবে না" জানতে।
     *
     * আবেদনের ক্রম দিয়েও সাজানো যায়: কে আগে চেয়েছিল, যেটা একই দিনে
     * দুইজন চাইলে কাজে লাগে।
     *
     * @return array<string, \Closure>
     */
    private function sorts(): array
    {
        return [
            'from_date' => fn ($q) => $q->orderByDesc('from_date')->orderByDesc('id'),
            'applied' => fn ($q) => $q->orderByDesc('id'),
            'employee' => fn ($q) => $q->orderBy('employee_id')->orderByDesc('from_date'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'from_date' => __('hr::field.from_date'),
            'applied' => __('hr::sort.applied'),
            'employee' => __('hr::field.employee'),
        ];
    }

    public function create(Request $request): View
    {
        return view('hr::leave.create', [
            'menu' => $this->menu->forUser($request->user()),
            'employees' => $this->reach()->employees(Employee::query(), $request->user())->active()->orderBy('code')->get(),
            'types' => LeaveType::query()->active()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('hr_employees', 'id')->where('company_id', $companyId)],
            // ⛔ কেবল চালু ধরন — ফোনের একই নিয়ম (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬, ⓘ১৭); ⓘ বানানো POST বন্ধ করা ধরনেও আবেদন বসাত
            'leave_type_id' => ['required', 'integer',
                Rule::exists('hr_leave_types', 'id')->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            // ⛔ সাধারণ সংখ্যা, দশমিকের পরে বড়জোর এক ঘর — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ৯; [[LeaveIsCountedWhenItIsApprovedTest]]);
            // ⓘ "1e1" পরের হিসাবে (bcmath) ৫০০ দিত, আর "1.55" এক ঘরে মাপা হয়ে কোটা পার হত অথচ খাতায় 1.6 বসত
            'days' => ['required', 'decimal:0,1', 'regex:/^\d+(\.\d)?$/', 'gt:0', 'max:'.LeaveService::MAX_SPAN_DAYS],
            'reason' => ['nullable', 'string', 'max:500'],
            // ⭐ মাতৃত্বকালীন ছুটিতে প্রসবের সম্ভাব্য দিন (ধারা ৪৭; সেবা দেখে কখন লাগে)
            'expected_delivery_date' => ['nullable', 'date'],
        ], [
            'days.decimal' => __('hr::validation.days_format'),
            'days.regex' => __('hr::validation.days_format'),
            'leave_type_id.exists' => __('hr::validation.leave_type_closed'),
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        abort_unless($this->reach()->reaches($request->user(), $employee), 403);

        $this->leave->apply(
            $employee,
            LeaveType::query()->findOrFail($data['leave_type_id']),
            $data['from_date'],
            $data['to_date'],
            (string) $data['days'],
            $data['reason'] ?? null,
            $data['expected_delivery_date'] ?? null,
        );

        return redirect()->route('hr.leave.index')
            ->with('saved', __('hr::message.leave_applied'));
    }

    public function approve(Request $request, int $application): RedirectResponse
    {
        $this->leave->approve(
            $this->reachable($request, $application),
            $request->user(),
            $request->string('remarks')->toString() ?: null,
        );

        return back()->with('saved', __('hr::message.leave_approved'));
    }

    public function reject(Request $request, int $application): RedirectResponse
    {
        $remarks = $request->validate([
            'remarks' => ['required', 'string', 'max:500'],
        ])['remarks'];

        $this->leave->reject(
            $this->reachable($request, $application),
            $request->user(),
            $remarks,
        );

        return back()->with('saved', __('hr::message.leave_rejected'));
    }

    public function cancel(Request $request, int $application): RedirectResponse
    {
        $this->leave->cancel($this->reachable($request, $application), $request->user());

        return back()->with('saved', __('hr::message.leave_cancelled'));
    }

    /**
     * ⭐ প্রসবের আসল দিন লেখা — কিছুই সরে না, অনুরোধ "সইয়ের অপেক্ষায়" (ধারা ৪৫; [[MaternityDeliveryService::record()]])।
     */
    public function recordDelivery(Request $request, int $application): RedirectResponse
    {
        $date = $request->validate(['actual_delivery_date' => ['required', 'date']])['actual_delivery_date'];

        app(MaternityDeliveryService::class)->record($this->reachable($request, $application)->load('leaveType'), (string) $date, $request->user());

        return back()->with('saved', __('hr::message.delivery_recorded'));
    }

    /** ⭐ "সই দিন" — তবেই ছুটির শেষ দিন সরে ([[MaternityDeliveryService::sign()]]) */
    public function signDelivery(Request $request, int $delivery): RedirectResponse
    {
        app(MaternityDeliveryService::class)->sign($this->reachableDelivery($request, $delivery), $request->user());

        return back()->with('saved', __('hr::message.delivery_signed_short'));
    }

    public function rejectDelivery(Request $request, int $delivery): RedirectResponse
    {
        $remarks = $request->validate(['remarks' => ['required', 'string', 'max:500']])['remarks'];

        app(MaternityDeliveryService::class)->reject($this->reachableDelivery($request, $delivery), $request->user(), $remarks);

        return back()->with('saved', __('hr::message.delivery_rejected'));
    }

    /** অনুরোধটা — কেবল কর্মী নাগালে থাকলে; নইলে ৪০৩ ([[reachable()]]-এর একই দেয়াল) */
    private function reachableDelivery(Request $request, int $delivery): MaternityDelivery
    {
        $found = MaternityDelivery::query()->findOrFail($delivery);
        $this->reachable($request, (int) $found->leave_application_id);

        return $found;
    }

    // ── ছুটির ধরন ─────────────────────────────────────────────────────

    public function types(Request $request): View
    {
        $find = is_string($request->query('q')) ? '%'.addcslashes(trim($request->query('q')), '%_\\').'%' : '%%';
        $types = LeaveType::query()
            ->when($find !== '%%', fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', $find)->orWhere('name_en', 'like', $find)->orWhere('name_bn', 'like', $find)))
            ->when(in_array($request->query('paid'), ['yes', 'no'], true), fn ($q) => $q->where('is_paid', $request->query('paid') === 'yes'))
            ->when($request->query('sort') === 'name', fn ($q) => $q->orderBy('name_en'))
            ->when($request->query('sort') === 'days', fn ($q) => $q->orderByDesc('days_per_year'))
            ->orderBy('code')->get();

        return view('hr::leave.types', [
            'menu' => $this->menu->forUser($request->user()),
            'types' => $types,
            // ⭐ কোনো প্রমিত ধরন বাকি থাকলেই — নতুন প্রমিত ধরন (মাতৃত্বকালীন) চলতি কোম্পানিতেও পৌঁছায় ([[LeaveTypeDefaults]], a6, ২১ অক্টোবর ২০২৬)
            'canInstallDefaults' => app(LeaveTypeDefaults::class)->anyMissing(),
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // খালি রাখলে সিরিজ থেকে বসে — মালিকের নির্দেশ (২০২৬-০৮-০৭)
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        /*
         * কোডটা এখানেই বসে, সার্ভিসে নয় — ছুটির ধরনের নিজের কোনো
         * সার্ভিস নেই, আর কেবল কোডের জন্য একটা বানানো মানে একটা ফাইল
         * বাড়ানো যাতে আর কিছুই থাকবে না।
         */
        $data['code'] = trim((string) ($data['code'] ?? '')) !== ''
            ? trim((string) $data['code'])
            : app(NumberSeriesEngine::class)->next('LVT');

        LeaveType::create([
            ...$data,
            'is_paid' => $request->boolean('is_paid'),
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('saved', __('hr::message.leave_type_created'));
    }

    /**
     * প্রমিত ছুটির ধরনগুলো — তালিকা [[LeaveTypeDefaults]]-এ (নতুন কোম্পানি আর কনসোলের একই তালিকা; a6, ২১ অক্টোবর ২০২৬)।
     *
     * বাংলাদেশের শ্রম আইনের প্রচলিত ভাগ। প্রতিষ্ঠান নিজের নীতি অনুযায়ী দিন বদলে নেবে — কিন্তু খালি তালিকা থেকে শুরু করতে হবে না।
     * ⓘ যা আছে তা আবার বসে না; কেবল বাকিগুলো।
     */
    public function installTypes(): RedirectResponse
    {
        app(LeaveTypeDefaults::class)->install();

        return back()->with('saved', __('hr::message.leave_types_installed'));
    }

    /**
     * আবেদনটা — কেবল কর্মী নাগালে থাকলে; নইলে ৪০৩ (চূড়ান্ত অডিট ⛔১৭)।
     *
     * ⛔ আগে `findOrFail` যেকোনো শাখার আবেদন খুলত: ঢাকায় সীমিত ব্যবস্থাপক নেত্রকোনার ছুটি অনুমোদন করতে পারতেন।
     */
    private function reachable(Request $request, int $application): LeaveApplication
    {
        $found = LeaveApplication::query()->with('employee')->findOrFail($application);

        abort_unless($this->reach()->reaches($request->user(), $found->employee), 403);

        return $found;
    }

    private function reach(): BranchReach
    {
        return app(BranchReach::class);
    }
}
