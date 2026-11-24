<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\FinalSettlement;
use App\Modules\Hr\Services\FinalSettlements;
use App\Modules\Hr\Support\BranchReach;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ চূড়ান্ত পাওনা — তালিকা, আগাম দেখা ও কাগজ বানানো, আর কাগজের পাতায় মালিকের অনুমোদন (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlements]])।
 * ⛔ পুরোটাই টাকা — দরজায় বেতনের চাবি; বানানো `hr.final_settlement.manage`-এ। অনুমোদনের অধিকার ইঞ্জিনের প্রশ্নে ([[ApprovalEngine::canDecide()]]),
 * ইনবক্সের হুবহু।
 */
final class FinalSettlementController extends Controller implements HasMiddleware
{
    use \App\Core\Concerns\GrandTotals;
    use \App\Core\Concerns\SortsLists;

    public function __construct(
        private readonly FinalSettlements $settlements,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.salary.view'),
            new Middleware('can:hr.final_settlement.manage', only: ['create', 'store']),
        ];
    }

    public function index(Request $request): View
    {
        $term = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $query = FinalSettlement::query()->with('employee')
            ->whereIn('employee_id', app(BranchReach::class)->employeesInView(Employee::query()->withTrashed(), $request->user())
                ->when($term !== '', fn ($q) => $q->search($term))->select('id'));
        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->latest('id'),
            'oldest' => fn ($q) => $q->oldest('id'),
            'largest' => fn ($q) => $q->orderByDesc('net')->orderByDesc('id'),
        ]);

        return view('hr::settlement.index', [
            'menu' => $this->menu->forUser($request->user()),
            'sort' => $sort,
            'sortOptions' => [
                'recent' => __('hr::settlement.sort_recent'), 'oldest' => __('hr::settlement.sort_oldest'), 'largest' => __('hr::settlement.sort_largest'),
            ],
            // ⛔ মোট কেবল অনুমোদিত কাগজের — অপেক্ষার, ফেরত বা তুলে নেওয়া কাগজ যোগে নয় (মালিক, ২৪ অক্টোবর ২০২৬)
            'grand' => $this->grandTotals($query, ['net' => "CASE WHEN t.status = '".FinalSettlement::APPROVED."' THEN t.net ELSE 0 END"]),
            'rows' => $query->paginate(\App\Core\Support\PerPage::of())->withQueryString(),
            // ⓘ শেষ দিন বসানো, অথচ খোলা বা অনুমোদিত কাগজ নেই — নতুন কাগজের বাছাই
            'leavers' => $request->user()->can('hr.final_settlement.manage') ? $this->leavers($request) : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        $employee = $this->employeeInView($request, (int) $request->query('employee'));

        return view('hr::settlement.create', [
            'menu' => $this->menu->forUser($request->user()),
            'employee' => $employee,
            'figures' => $this->settlements->compute($employee),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'separation' => ['required', Rule::in(FinalSettlement::SEPARATIONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $employee = $this->employeeInView($request, (int) $data['employee_id']);

        $settlement = $this->settlements->prepare($employee, $data['separation'], $data['note'] ?? null, $request->user());

        return redirect()->route('hr.final_settlement.show', $settlement)->with('saved', __('hr::settlement.prepared'));
    }

    public function show(Request $request, FinalSettlement $settlement): View
    {
        $this->employeeInView($request, (int) $settlement->employee_id);

        return view('hr::settlement.show', [
            'menu' => $this->menu->forUser($request->user()),
            'settlement' => $settlement->load('employee', 'requester'),
            'approval' => $this->settlements->approvalOf($settlement),
            'canDecide' => $this->settlements->canDecide($settlement, $request->user()),
        ]);
    }

    public function approve(Request $request, FinalSettlement $settlement): RedirectResponse
    {
        $this->employeeInView($request, (int) $settlement->employee_id);
        $this->settlements->approve($settlement, $request->user());

        return back()->with('saved', __('hr::settlement.approved_msg'));
    }

    public function reject(Request $request, FinalSettlement $settlement): RedirectResponse
    {
        $this->employeeInView($request, (int) $settlement->employee_id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->settlements->reject($settlement, $request->user(), $data['reason']);

        return back()->with('saved', __('hr::settlement.rejected_msg'));
    }

    /** ⛔ দেখার শাখার বাইরের কর্মী — ৪০৪, অন্য দরজার মতো */
    private function employeeInView(Request $request, int $id): Employee
    {
        $employee = app(BranchReach::class)->employeesInView(Employee::query()->withTrashed(), $request->user())->whereKey($id)->first();

        abort_if($employee === null, 404);

        return $employee;
    }

    /** @return \Illuminate\Support\Collection<int, Employee> */
    private function leavers(Request $request)
    {
        return app(BranchReach::class)->employeesInView(Employee::query()->withTrashed(), $request->user())
            ->whereNotNull('leaving_date')
            ->whereNotIn('id', FinalSettlement::query()->whereIn('status', [FinalSettlement::PENDING, FinalSettlement::APPROVED])->select('employee_id'))
            ->orderByDesc('leaving_date')->limit(200)->get();
    }
}
