<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\ExpenseClaimService;
use App\Modules\Hr\Support\BranchReach;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ খরচের দাবি আর অগ্রিম অনুরোধ — পর্দা আর ফোনের দরজা (মালিকের আদেশ, ৭ অক্টোবর ২০২৬; [[ExpenseClaimService]])।
 *
 * ⓘ নিজের দাবি `hr.claim.self`-এ; সবার দাবির তালিকা `hr.claim.view`-এ, নাগালের কর্মী ধরে ([[BranchReach]])। সই হয় সইয়ের
 * বাক্সে (ওয়েব আর ফোন দুটোতেই আগে থেকে আছে), আর টাকা দেন ক্যাশিয়ার ভাউচারের পর্দা থেকে।
 */
class ExpenseClaimController extends Controller implements HasMiddleware
{
    use GrandTotals;

    public function __construct(
        private readonly ExpenseClaimService $claims,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            // ⓘ তালিকা — নিজের দাবি; "সবার" ট্যাব নিজে `hr.claim.view` দেখে। একটা দাবির পাতা নীতি দেখে ([[ExpenseClaimPolicy]])
            new Middleware('can:hr.claim.self', only: ['index', 'create', 'store', 'apiIndex', 'apiStore', 'apiHeads']),
        ];
    }

    public function index(Request $request): View
    {
        $all = $request->query('tab') === 'all' && $request->user()->can('hr.claim.view');

        $query = ExpenseClaim::query()->with(['employee', 'expenseAccount'])
            ->when(
                $all,
                fn ($q) => app(BranchReach::class)->throughEmployee($q, $request->user()),
                fn ($q) => $q->where('requested_by', $request->user()->id),
            )
            ->when(in_array($request->query('status'), ExpenseClaim::STATES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id');

        $employee = $this->claims->employeeOf($request->user());

        return view('hr::claim.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $all ? 'all' : 'mine',
            'grand' => $this->grandTotals($query, ['amount' => 't.amount']),
            'claims' => $query->paginate(50)->withQueryString(),
            'openAdvance' => $employee === null ? null : $this->claims->openAdvance($employee),
        ]);
    }

    public function create(Request $request): View
    {
        return view('hr::claim.create', [
            'menu' => $this->menu->forUser($request->user()),
            'heads' => $this->heads(),
            'employee' => $this->claims->employeeOf($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $claim = $this->claims->submit($request->user(), $this->validated($request), $request->file('receipt'));

        return redirect()->route('hr.claim.show', $claim)->with('saved', __('hr::claim.sent_'.$claim->status, ['no' => $claim->document_no]));
    }

    public function show(Request $request, ExpenseClaim $claim): View
    {
        $this->authorize('view', $claim);

        $claim->load(['employee', 'expenseAccount', 'paymentVoucher', 'settleVoucher', 'requester']);

        return view('hr::claim.show', [
            'menu' => $this->menu->forUser($request->user()),
            'claim' => $claim,
            // ⓘ অগ্রিমের পাতায় — বাকি অগ্রিম নগদে ফেরতের ঘরের জন্য ([[ExpenseClaimService::takeBackAdvance()]])
            'openAdvance' => $claim->kind === ExpenseClaim::ADVANCE && $claim->employee !== null ? $this->claims->openAdvance($claim->employee) : null,
        ]);
    }

    /** ⭐ বাকি অগ্রিম নগদে ফেরত — খসড়া আদায় ভাউচার, ক্যাশিয়ার নিজের টিলে পাকা করেন (টাকার পরিকল্পনা দফা ১৩, ধাপ ৪) */
    public function takeBackAdvance(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);

        $voucher = $this->claims->takeBackAdvance($claim, (string) $data['amount']);

        return redirect()->route('accounts.voucher.show', $voucher)
            ->with('saved', __('hr::claim.return_drafted', ['no' => $voucher->document_no]));
    }

    // ── ফোন ─────────────────────────────────────────────────────────────

    /** নিজের দাবিগুলো, সাথে খোলা অগ্রিম */
    public function apiIndex(Request $request): JsonResponse
    {
        $employee = $this->claims->employeeOf($request->user());

        return response()->json([
            'open_advance' => $employee === null ? null : $this->claims->openAdvance($employee),
            'claims' => ExpenseClaim::query()->with('expenseAccount')->where('requested_by', $request->user()->id)
                ->orderByDesc('id')->limit(50)->get()->map(fn (ExpenseClaim $c) => $this->facts($c))->values(),
        ]);
    }

    /** খরচের খাতের তালিকা — দাবির ফর্মের জন্য */
    public function apiHeads(): JsonResponse
    {
        return response()->json(['heads' => $this->heads()->map(fn (Account $a) => [
            'id' => (int) $a->id, 'code' => (string) $a->code, 'name' => $a->name(),
        ])->values()]);
    }

    public function apiStore(Request $request): JsonResponse
    {
        $claim = $this->claims->submit($request->user(), $this->validated($request), $request->file('receipt'));

        return response()->json($this->facts($claim->fresh('expenseAccount')), 201);
    }

    public function apiShow(Request $request, ExpenseClaim $claim): JsonResponse
    {
        $this->authorize('view', $claim);

        return response()->json($this->facts($claim->load('expenseAccount')));
    }

    // ── ভিতরের ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(ExpenseClaim::KINDS)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'expense_account_id' => ['nullable', 'integer'],
            'spent_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:500'],
            'receipt' => ['nullable', 'file'],
        ]);
    }

    /** @return Collection<int, Account> */
    private function heads()
    {
        return Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->get();
    }

    /** @return array<string, mixed> ফোনের আকার — একই সব জায়গায় */
    private function facts(ExpenseClaim $claim): array
    {
        return [
            'id' => (string) $claim->public_id,
            'number' => (string) $claim->document_no,
            'kind' => (string) $claim->kind,
            'status' => (string) $claim->status,
            'status_label' => __('hr::claim.state_'.$claim->status),
            'amount' => (string) $claim->amount,
            'from_advance' => (string) $claim->from_advance,
            'cash' => $claim->cashPart(),
            'head' => $claim->expenseAccount === null ? null : ['id' => (int) $claim->expenseAccount->id, 'name' => $claim->expenseAccount->name()],
            'spent_on' => $claim->spent_on?->toDateString(),
            'reason' => (string) $claim->reason,
            'submitted_at' => $claim->created_at?->toIso8601String(),
            'decided_at' => $claim->decided_at?->toIso8601String(),
            'paid_at' => $claim->paid_at?->toIso8601String(),
        ];
    }
}
