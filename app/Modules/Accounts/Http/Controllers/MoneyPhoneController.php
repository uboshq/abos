<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashCountService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ টাকার হাতবদল আর নগদ গোনা, ফোনের নিজের পর্দায় — কার হাতে কত, হস্তান্তর পাঠানো-নেওয়া-বাতিল, গোনা আর মেনে নেওয়া
 * (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
 *
 *   GET  /api/v1/accounts/custody                      কার হাতে কত — ওয়েবের [[MoneyCustodyController]]-এর একই হিসাব
 *   GET  /api/v1/accounts/money/setup                  টিল, গন্তব্য, মানুষ, নোটের মাপ
 *   GET  /api/v1/accounts/transfers · /{id}            POST /transfers · /{id}/confirm · /{id}/cancel
 *   GET  /api/v1/accounts/cash-counts · /{id}          POST /cash-counts · /{id}/approve
 *
 * ── ⛔ নতুন পোস্টিং-পথ নেই — ওয়েবের একই সেবা ─────────────────────────────────────────────────────
 * [[MoneyTransferService]] `initiate` (Dr ১১০৩ পথের টাকা / Cr টিল; সই চাইলে কিছু বসে না), `confirm` (Dr গন্তব্য / Cr ১১০৩ —
 * কেবল প্রাপক বা টিলের দায়িত্বে যিনি), `cancel` (উল্টো সারি); [[CashCountService]] `record` (খাতা নড়ে না) আর `approve` (যিনি
 * গুনেছেন তিনি নন; ঘাটতি Dr ৫২৯৯ / Cr টিল, বাড়তি Dr টিল / Cr ৪৩০০; সই চাইলে আটকায়, সইয়ের পরে আবার চাপা — ওয়েবের মতো)।
 * চাবি ওয়েবের পর্দার একই; টিল আর হস্তান্তর মডেলের নিজের শাখার দেয়ালে খোঁজা; নিরীক্ষা মডেলের নিজের ([[IsAudited]])।
 * ⭐ প্রতিটা লেখা একবারই — রুটে [[RemembersAPhoneWrite]]। ⓘ কেবল `public_id`।
 */
final class MoneyPhoneController extends Controller implements HasMiddleware
{
    public const PER_PAGE = 30;

    public function __construct(
        private readonly MoneyTransferService $transfers,
        private readonly CashCountService $counts,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.till.view', only: ['custody']),
            new Middleware('can:accounts.transfer.create', only: ['setup', 'transfers', 'transfer', 'sendTransfer', 'cancelTransfer']),
            new Middleware('can:accounts.transfer.confirm', only: ['confirmTransfer']),
            new Middleware('can:accounts.count.create', only: ['countSetup', 'counts', 'count', 'recordCount']),
            new Middleware('can:accounts.count.approve', only: ['approveCount']),
        ];
    }

    /** কার হাতে কত — ওয়েবের পাতার একই সারি আর একই "পথের টাকা" */
    public function custody(Request $request): JsonResponse
    {
        $rows = [];

        foreach (CashTill::query()->with(['account', 'holder'])->orderByDesc('is_primary')->orderBy('code')->get() as $till) {
            $rows[] = ['kind' => __('accounts::custody.kind_till'), 'name' => $till->name(), 'holder' => $till->holder?->name,
                'balance' => Money::format((string) $till->balance()), 'amount' => (string) $till->balance(), 'active' => (bool) $till->is_active];
        }

        $tillAccounts = CashTill::query()->pluck('account_id')->filter()->all();

        foreach (Account::query()->ofMoneyKind(Account::CASH)->when($tillAccounts !== [], fn ($q) => $q->whereNotIn('id', $tillAccounts))
            ->with('keeper')->orderBy('code')->get() as $cash) {
            $rows[] = ['kind' => __('accounts::custody.kind_office_cash'), 'name' => $cash->name(), 'holder' => $cash->keeper?->name,
                'balance' => Money::format((string) $cash->balanceInView()), 'amount' => (string) $cash->balanceInView(), 'active' => (bool) $cash->is_active];
        }

        foreach (Account::query()->ofMoneyKind(Account::BANK)->orderBy('code')->get() as $bank) {
            $rows[] = ['kind' => __('accounts::custody.kind_bank'), 'name' => $bank->label(), 'holder' => null,
                'balance' => Money::format((string) $bank->balanceInView()), 'amount' => (string) $bank->balanceInView(), 'active' => (bool) $bank->is_active];
        }

        $onTheRoad = ViewedBranch::narrow(MoneyTransfer::query(), 'money_transfers.branch_id')
            ->with(['fromTill', 'toTill', 'toAccount', 'giver'])->pending()->orderBy('trx_date')->get();

        return response()->json([
            'rows' => $rows,
            'on_the_road' => Money::format((string) (StandardChart::find(StandardChart::CASH_IN_TRANSIT)?->balanceInView() ?? '0')),
            'transfers' => $onTheRoad->map(fn (MoneyTransfer $t) => $this->transferRow($t, $request->user()))->values(),
        ]);
    }

    /** হস্তান্তরের ফর্মের বাছাই — ওয়েবের ফর্মের একই তালিকা */
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tills = CashTill::query()->active()->with('account')->orderByDesc('is_primary')->orderBy('code')->get();

        return response()->json([
            'tills' => $tills->map(fn (CashTill $t) => ['id' => (string) $t->public_id, 'name' => $t->name(),
                'balance' => Money::format((string) $t->balance())])->values(),
            // ⓘ গন্তব্য — আরেকটা টিল, বা ব্যাংক (MFS নয়; ওয়েবের একই নিয়ম)
            'destinations' => [
                ...$tills->map(fn (CashTill $t) => ['id' => 'till:'.$t->public_id, 'name' => $t->name()])->all(),
                ...Account::query()->ofMoneyKind(Account::BANK)->active()->orderBy('code')->get()
                    ->map(fn (Account $a) => ['id' => 'account:'.$a->public_id, 'name' => $a->label()])->all(),
            ],
            'people' => User::query()->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->where('is_active', true)
                ->orderBy('name')->get()->map(fn (User $u) => ['id' => (string) $u->public_id, 'name' => (string) $u->name])->values(),
            'notes' => CashCount::DENOMINATIONS,
            'can' => [
                'transfer' => $user->can('accounts.transfer.create'),
                'confirm' => $user->can('accounts.transfer.confirm'),
                'count' => $user->can('accounts.count.create'),
                'approve_count' => $user->can('accounts.count.approve'),
                'custody' => $user->can('accounts.till.view'),
            ],
        ]);
    }

    /** গোনার ফর্মের বাছাই — টিল (তাদের এখনকার জের) আর নোটের মাপ ([[CashCount::DENOMINATIONS]]) */
    public function countSetup(Request $request): JsonResponse
    {
        return response()->json([
            'tills' => CashTill::query()->active()->orderByDesc('is_primary')->orderBy('code')->get()
                ->map(fn (CashTill $t) => ['id' => (string) $t->public_id, 'name' => $t->name(), 'balance' => Money::format((string) $t->balance())])->values(),
            'notes' => CashCount::DENOMINATIONS,
            'can_approve' => (bool) $request->user()?->can('accounts.count.approve'),
        ]);
    }

    public function transfers(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = MoneyTransfer::query()->with(['fromTill', 'toTill', 'toAccount', 'giver', 'receiver'])
            ->orderByDesc('trx_date')->orderByDesc('id')->paginate(self::PER_PAGE);

        return response()->json([
            // ⭐ আমার নেওয়ার অপেক্ষায় — ওয়েবের তালিকার মাথার একই সারি ([[MoneyTransfer::awaiting()]])
            'awaiting' => MoneyTransfer::query()->awaiting((int) $user->id)->with(['fromTill', 'giver'])->orderBy('trx_date')->get()
                ->map(fn (MoneyTransfer $t) => $this->transferRow($t, $user))->values(),
            'rows' => $page->getCollection()->map(fn (MoneyTransfer $t) => $this->transferRow($t, $user))->values(),
            'count' => $page->total(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function transfer(Request $request, string $id): JsonResponse
    {
        $transfer = $this->findTransfer($id);
        $transfer->load(['fromTill', 'toTill', 'toAccount', 'giver', 'receiver', 'creator', 'confirmer', 'branch']);

        return response()->json($this->transferRow($transfer, $request->user()) + [
            'narration' => $transfer->narration,
            'created_by' => $transfer->creator?->name,
            'confirmed_by' => $transfer->confirmer?->name,
            'confirmed_at' => $transfer->confirmed_at === null ? null : DateFormat::formatWithTime($transfer->confirmed_at),
            'cancel_reason' => $transfer->cancel_reason,
        ]);
    }

    /** পাঠানো — ওয়েবের [[MoneyTransferController::store()]] */
    public function sendTransfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trx_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'from_till' => ['required', 'string', 'max:64'],
            'destination' => ['required', 'string', 'regex:/^(till|account):[0-9a-fA-F-]{36}$/'],
            'given_by' => ['nullable', 'string', 'max:64'],
            'received_by' => ['nullable', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        [$kind, $destination] = explode(':', $data['destination'], 2);

        $transfer = $this->transfers->initiate([
            'trx_date' => $data['trx_date'],
            'from_till_id' => $this->idOf(CashTill::query(), $data['from_till'], 'from_till'),
            'to_till_id' => $kind === 'till' ? $this->idOf(CashTill::query(), $destination, 'destination') : null,
            'to_account_id' => $kind === 'account' ? $this->idOf(Account::query()->ofMoneyKind(Account::BANK), $destination, 'destination') : null,
            'given_by' => filled($data['given_by'] ?? null) ? $this->personOf($data['given_by'], 'given_by') : null,
            'received_by' => filled($data['received_by'] ?? null) ? $this->personOf($data['received_by'], 'received_by') : null,
            'amount' => (string) $data['amount'],
            'narration' => $data['narration'] ?? null,
        ]);

        $held = $transfer->isAwaiting();

        return response()->json(['status' => $held ? 'held' : 'done', 'id' => (string) $transfer->public_id, 'no' => (string) $transfer->document_no,
            'notice' => $held
                ? __('accounts::message.transfer_awaiting_signature', ['no' => $transfer->document_no])
                : __('accounts::message.transfer_started', ['no' => $transfer->document_no])], $held ? 202 : 201);
    }

    /** নেওয়া — ওয়েবের [[MoneyTransferController::confirm()]]; কে নিতে পারেন সেবা দেখে */
    public function confirmTransfer(Request $request, string $id): JsonResponse
    {
        $transfer = $this->transfers->confirm($this->findTransfer($id), (int) $request->user()->id);

        return response()->json(['status' => 'done', 'notice' => __('accounts::message.transfer_received', ['no' => $transfer->document_no])], 201);
    }

    public function cancelTransfer(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'min:3', 'max:500']]);
        $transfer = $this->transfers->cancel($this->findTransfer($id), $data['cancel_reason']);

        return response()->json(['status' => 'done', 'notice' => __('accounts::message.transfer_cancelled', ['no' => $transfer->document_no])], 201);
    }

    public function counts(): JsonResponse
    {
        $page = CashCount::query()->with(['till', 'counter', 'approver'])->orderByDesc('trx_date')->orderByDesc('id')->paginate(self::PER_PAGE);

        return response()->json([
            'rows' => $page->getCollection()->map(fn (CashCount $c) => $this->countRow($c))->values(),
            'count' => $page->total(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function count(Request $request, string $id): JsonResponse
    {
        $count = $this->findCount($id);
        $count->load(['till', 'counter', 'approver', 'adjustment']);

        return response()->json($this->countRow($count) + [
            'notes' => collect((array) ($count->denominations ?? []))->map(fn ($qty, $note) => ['note' => (int) $note, 'qty' => (int) $qty])->values(),
            'narration' => $count->narration,
            'adjustment' => $count->adjustment?->document_no,
            // ⓘ মেনে নেওয়া যিনি গুনেছেন তিনি ছাড়া কেউ — সেবা আবার দেখে ([[CashCountService::approve()]])
            'can_approve' => $count->status === 'draft' && (bool) $request->user()?->can('accounts.count.approve')
                && (int) $count->counted_by !== (int) $request->user()?->id,
        ]);
    }

    /** গোনা — ওয়েবের [[CashCountController::store()]]; খাতা নড়ে না, মেনে নেওয়ার অপেক্ষায় থাকে */
    public function recordCount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'till' => ['required', 'string', 'max:64'],
            'trx_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $count = $this->counts->record([
            'cash_till_id' => $this->idOf(CashTill::query(), $data['till'], 'till'),
            'trx_date' => $data['trx_date'],
            'narration' => $data['narration'] ?? null,
        ], $data['counts']);

        return response()->json(['status' => 'done', 'id' => (string) $count->public_id, 'no' => (string) $count->document_no,
            'notice' => __('accounts::message.count_recorded', ['no' => $count->document_no])], 201);
    }

    /** মেনে নেওয়া — ওয়েবের [[CashCountController::approve()]]; সই লাগলে আটকায় (৪২২, কারণসহ) */
    public function approveCount(string $id): JsonResponse
    {
        $count = $this->counts->approve($this->findCount($id));

        return response()->json(['status' => 'done', 'notice' => __('accounts::message.count_approved', ['no' => $count->document_no])], 201);
    }

    // ── যন্ত্রপাতি ──

    private function findTransfer(string $id): MoneyTransfer
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);

        return MoneyTransfer::query()->where('public_id', $id)->firstOrFail();
    }

    private function findCount(string $id): CashCount
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);

        return CashCount::query()->where('public_id', $id)->firstOrFail();
    }

    /** ⛔ মডেলের নিজের দেয়ালে — অন্য কোম্পানির বা শাখার হলে "পাওয়া গেল না" */
    private function idOf(\Illuminate\Database\Eloquent\Builder $query, string $publicId, string $field): int
    {
        $table = $query->getModel()->getTable();
        $id = $query->where($table.'.public_id', $publicId)->where($table.'.company_id', CompanyContext::id())->value($table.'.id');

        if ($id === null) {
            throw ValidationException::withMessages([$field => __('accounts::validation.phone_unknown_reference')]);
        }

        return (int) $id;
    }

    private function personOf(string $publicId, string $field): int
    {
        $id = User::query()->where('public_id', $publicId)->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->value('id');

        if ($id === null) {
            throw ValidationException::withMessages([$field => __('accounts::validation.phone_unknown_reference')]);
        }

        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function transferRow(MoneyTransfer $t, ?User $user): array
    {
        $status = (string) $t->status;

        return [
            'id' => (string) $t->public_id,
            'no' => (string) $t->document_no,
            'date' => DateFormat::format($t->trx_date),
            'from' => $t->fromTill?->name(),
            'to' => $t->toTill?->name() ?? $t->toAccount?->label(),
            'giver' => $t->giver?->name,
            'receiver' => $t->receiver?->name,
            'amount' => bcadd((string) $t->amount, '0', 2),
            'amount_label' => Money::format((string) $t->amount),
            'status' => $status,
            'status_label' => match ($status) {
                MoneyTransfer::AWAITING => __('accounts::state.awaiting_signature'),
                'confirmed' => __('accounts::state.received'),
                'cancelled' => __('core.status.cancelled'),
                default => __('accounts::state.awaiting_receipt'),
            },
            // ⓘ আমার নেওয়ার — প্রাপক বা টিলের দায়িত্বে; শেষ কথা সেবার ([[MoneyTransferService::confirm()]])
            'mine_to_receive' => $status === 'draft' && $user !== null
                && ((int) $t->received_by === (int) $user->id || (int) ($t->toTill?->holder_id ?? 0) === (int) $user->id),
        ];
    }

    /** @return array<string, mixed> */
    private function countRow(CashCount $c): array
    {
        return [
            'id' => (string) $c->public_id,
            'no' => (string) $c->document_no,
            'date' => DateFormat::format($c->trx_date),
            'till' => $c->till?->name(),
            'counted' => Money::format((string) $c->counted_amount),
            'expected' => Money::format((string) $c->expected_amount),
            'difference' => Money::format((string) $c->difference),
            'difference_amount' => bcadd((string) $c->difference, '0', 2),
            'status' => (string) $c->status,
            'status_label' => __('core.status.'.$c->status),
            'counted_by' => $c->counter?->name,
            'approved_by' => $c->approver?->name,
        ];
    }
}
