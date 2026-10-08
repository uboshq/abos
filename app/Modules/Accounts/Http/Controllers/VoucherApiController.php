<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Models\Approval;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Services\DepositFormOptions;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * ⭐ ফোনে অফিসের লোকের ভাউচার — `/api/v1/accounts/vouchers…` (মালিক, ৭ অক্টোবর ২০২৬: "সব ভাউচার দেওয়ার কথা ছিল")।
 *
 * <p>এখানে কেবল পড়া: ধরন ধরে খাতের তালিকা, ভাউচারের তালিকা আর একটা ভাউচারের পাতা। লেখা যায় সিঙ্কের সারি দিয়ে
 * ([[VoucherSync]] — নেট না থাকলে ফোনে জমা, একই ভাউচার দুবার নয়), আর পাকা করা এখানে ([[post()]]) — দুটোই ওয়েবের
 * একই পথে ([[VoucherWriter]]): একই অনুরোধের নিয়ম, একই সেবা, একই সই, একই "লেখক ≠ পাকাকারী"।
 *
 * <p>চাবি: পুরোটাই `accounts.voucher.create`-ওয়ালার; তার উপর সব ভাউচার দেখা `accounts.report` (ওয়েবের তালিকার চাবি) —
 * ⓘ ক্যাশিয়ারের লেখার চাবি আছে, রিপোর্টের নেই, তিনি ফোনে কেবল নিজের লেখা ভাউচার দেখেন। পাকা করা `accounts.voucher.update`।
 * ⓘ খাত আর পক্ষ ওয়েবের ফর্মের মতো সংখ্যার আইডিতে — অনুরোধের নিয়ম ([[VoucherRequest]]) সেটাই পড়ে, আর তালিকাগুলো
 * কেবল লেখার চাবিওয়ালার।
 */
final class VoucherApiController extends Controller implements HasMiddleware
{
    private const PER_PAGE = 30;

    /** ⓘ পুরো ফোনের ভাউচার লেখার চাবিওয়ালার (সমন্বয়ক, ৭ অক্টোবর ২০২৬); পাকা করায় ওয়েবের পাকা করার চাবিও */
    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.voucher.create'),
            new Middleware('can:accounts.voucher.update', only: ['post']),
        ];
    }

    public function __construct(
        private readonly DepositFormOptions $options,
        private readonly PartyRegistry $parties,
        private readonly VoucherService $vouchers,
        private readonly VoucherWriter $writer,
    ) {}

    /** `GET /vouchers/setup?type=` — ওয়েবের ফর্মের একই তালিকা, ধরনের দুই দিক আগে থেকে ভাগ করা */
    public function setup(Request $request): JsonResponse
    {
        $type = $this->type($request->query('type'));
        $o = $this->options->all($type);

        $lists = [
            'money' => $o['moneyAccounts'],
            'all' => $o['allAccounts'],
            'expense' => $o['expenseAccounts'],
            'party_or_income' => $o['allAccounts'],
            'money_or_credit' => collect($o['moneyAccounts'])->concat($o['creditAccounts'])->values(),
        ];
        $side = fn (array $s) => [
            'label' => __($s['label']),
            'accounts' => $this->accounts($lists[$s['source']] ?? $o['allAccounts']),
        ];

        return response()->json([
            'type' => $type,
            'today' => now()->toDateString(),
            'narration_required' => (bool) app(SettingsService::class)->get('accounts.require_narration', false),
            'cash_hidden_reason' => $o['cashHiddenReason'],
            'instruments' => Voucher::INSTRUMENTS,
            ...($type === Voucher::JOURNAL
                ? ['accounts' => $this->accounts($o['allAccounts'])]
                : ['from' => $side($o['sides']['from']), 'to' => $side($o['sides']['to'])]),
            'parties' => collect($this->parties->forPicker())->map(fn (array $g) => [
                'type' => $g['type'],
                'label' => $g['label'],
                'options' => collect($g['options'])->map(fn (array $p) => [
                    'id' => $p['id'], 'label' => $p['label'], 'hint' => $p['hint'] ?? null,
                ])->values(),
            ])->values(),
        ]);
    }

    /** `GET /vouchers?type=&status=&from=&to=&q=&awaiting=&page=` */
    public function index(Request $request): JsonResponse
    {
        $user = $this->writer($request);
        $data = $request->validate([
            'type' => ['nullable', Rule::in(Voucher::TYPES)],
            'status' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'awaiting' => ['nullable', 'boolean'],
        ]);

        $page = $this->visible($user)
            ->when($data['type'] ?? null, fn (Builder $q, string $t) => $q->ofType($t))
            ->search($data['q'] ?? null)
            ->when($data['from'] ?? null, fn (Builder $q, string $d) => $q->where('trx_date', '>=', $d))
            ->when($data['to'] ?? null, fn (Builder $q, string $d) => $q->where('trx_date', '<=', $d))
            ->when($data['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when((bool) ($data['awaiting'] ?? false), fn (Builder $q) => $q->whereIn('id', $this->awaitingIds()))
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $rows = $page->getCollection();
        // ⓘ সারির পক্ষও লাগে (নিচে [[partyOf()]]) — পাতায় একবারে
        $rows->load('lines:id,voucher_id,party_type,party_id');
        $names = $this->partyNames($rows);
        $awaiting = $this->awaitingIds($rows->modelKeys());

        return response()->json([
            'rows' => $rows->map(fn (Voucher $v) => $this->row($v, $names, $awaiting))->values(),
            'count' => $page->total(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** `GET /vouchers/{public_id}` — সারিসহ; কী করা যায় (পাকা করা) তাও */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->writer($request);
        $voucher = $this->visible($user)->where('public_id', $id)->with(['lines.account', 'creator'])->firstOrFail();

        return response()->json($this->page($voucher, $user));
    }

    /**
     * `POST /vouchers/{public_id}/post` {instrument_no?} — খসড়া পাকা করা, ওয়েবের একই পথে ([[VoucherWriter::post()]])।
     *
     * ⓘ সই আটকালে পাকা হয় না, কারণসহ ২০০ (`posted: false`) — ওয়েবের হলুদ বার্তার মতো, ভুল নয়; নিয়ম আটকালে
     * (লেখক নিজে, টিল, লেনদেন নম্বর) ৪২২, সেবার নিজের কথায়।
     */
    public function post(Request $request, string $id): JsonResponse
    {
        $user = $this->writer($request);
        $data = $request->validate(['instrument_no' => ['nullable', 'string', 'max:64']]);
        $voucher = $this->visible($user)->where('public_id', $id)->firstOrFail();

        // ⓘ পাকা বা বাতিল ভাউচার সেবাই ফেরায় (`already_posted`), ওয়েবের মতো
        $stopping = $this->writer->post($voucher, $data['instrument_no'] ?? null);

        $message = match (true) {
            $stopping === null => __('accounts::phone_voucher.posted', ['no' => $voucher->document_no]),
            $stopping->status === Approval::REJECTED => __('accounts::message.voucher_approval_rejected', [
                'no' => $voucher->document_no,
                'reason' => (string) $stopping->decisions()->latest('id')->value('remarks'),
            ]),
            default => __('accounts::message.voucher_approval_pending', ['no' => $voucher->document_no]),
        };

        return response()->json([
            'posted' => $stopping === null,
            'message' => $message,
            'voucher' => $this->page($voucher->fresh(['lines.account', 'creator']), $user),
        ]);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function type(mixed $type): string
    {
        abort_unless(is_string($type) && in_array($type, Voucher::TYPES, true), 422, __('validation.in', ['attribute' => 'type']));

        return $type;
    }

    /** চাবি দরজায় ([[middleware()]]); এখানে কেবল কর্মী কি না — ডিলারের টোকেন নয় */
    private function writer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** রিপোর্টের চাবিতে সব (শাখার দেয়াল মডেলের নিজের); কেবল লেখার চাবিতে নিজের লেখা */
    private function visible(User $user): Builder
    {
        return Voucher::query()->when(! $user->can('accounts.report'), fn (Builder $q) => $q->where('created_by', $user->id));
    }

    /** @param  list<int>|null  $among  @return list<int> */
    private function awaitingIds(?array $among = null): array
    {
        return Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('module', VoucherApproval::MODULE)
            ->pending()
            ->when($among !== null, fn ($q) => $q->whereIn('approvable_id', $among))
            ->pluck('approvable_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array{money_account: ?string, money_label: ?string} */
    private function moneySide(Voucher $v): array
    {
        $role = match ($v->type) {
            Voucher::RECEIPT => 'into',
            Voucher::PAYMENT, Voucher::EXPENSE => 'out_of',
            default => null,
        };
        if ($role === null) {
            return ['money_account' => null, 'money_label' => null];
        }

        // ⓘ খাতটা আগেই বাছা (ভাউচারের নিজের ঘর) — `whereKey`, তালিকা থেকে বাছাই নয় ([[MoneyNeverLandsOnAGroupAccountTest]])
        if ($v->money_account_id !== null) {
            $money = Account::query()->whereKey($v->money_account_id)->first();
        } else {
            $money = $v->lines->map(fn (VoucherLine $l) => $l->account)
                ->first(fn (?Account $a) => $a !== null && ($a->isBank() || $a->isMfs() || $a->isCash()));
        }

        return [
            'money_account' => $money?->label(),
            'money_label' => $money === null ? null : (string) __('accounts::phone_voucher.money_'.$role),
        ];
    }

    /**
     * মাথার পক্ষ আর সারির পক্ষ — দুটোরই নাম, এক ডাকে।
     *
     * @param  Collection<int, Voucher>  $rows
     * @return array<string, string>
     */
    private function partyNames(Collection $rows): array
    {
        $pairs = [];
        foreach ($rows as $v) {
            if ($v->party_type !== null && $v->party_id !== null) {
                $pairs[] = [(string) $v->party_type, (int) $v->party_id];
            }
            foreach ($v->lines as $l) {
                if ($l->party_type !== null && $l->party_id !== null) {
                    $pairs[] = [(string) $l->party_type, (int) $l->party_id];
                }
            }
        }

        return $this->parties->labelsOf($pairs);
    }

    /**
     * ⭐ টাকা কার — মাথায় পক্ষ থাকলে সেটা, নইলে প্রথম যে সারিতে পক্ষ বসে (মালিক, ৭ অক্টোবর ২০২৬: *"vauture e kake dibe kar
     * kach theke nibe seta nai"*)। আদায় আর পরিশোধের পক্ষ প্রায়ই সারিতে বসে (প্রাপ্য বা প্রদেয়ের সারি), মাথায় নয় — তাই আগে
     * ফোনে কিছুই দেখাত না।
     *
     * @param  array<string, string>  $names
     */
    private function partyOf(Voucher $v, array $names): ?string
    {
        if ($v->party_type !== null && $v->party_id !== null) {
            return $names[$v->party_type.':'.$v->party_id] ?? null;
        }

        foreach ($v->lines->sortBy('id') as $l) {
            if ($l->party_type !== null && $l->party_id !== null && isset($names[$l->party_type.':'.$l->party_id])) {
                return $names[$l->party_type.':'.$l->party_id];
            }
        }

        return null;
    }

    /**
     * ⓘ "সইয়ের অপেক্ষায়" অবস্থা নয় — খসড়ার উপর ঝুলে থাকা সই ([[VoucherApproval]])। ফোনে চারটা নাম:
     * খসড়া · সইয়ের অপেক্ষায় · পাকা · বাতিল।
     *
     * @param  array<string, string>  $names
     * @param  list<int>  $awaiting
     * @return array<string, mixed>
     */
    private function row(Voucher $v, array $names, array $awaiting): array
    {
        $state = match (true) {
            $v->isCancelled() => 'cancelled',
            $v->isPosted() => 'posted',
            in_array((int) $v->id, $awaiting, true) => 'awaiting',
            default => 'draft',
        };

        return [
            'id' => (string) $v->public_id,
            'no' => (string) $v->document_no,
            'type' => (string) $v->type,
            'type_label' => (string) __('accounts::voucher.'.$v->type),
            'date' => $v->trx_date?->toDateString(),
            'amount' => bcadd((string) $v->amount, '0', 2),
            'state' => $state,
            'state_label' => (string) __('accounts::phone_voucher.state_'.$state),
            'party' => $this->partyOf($v, $names),
            // ⭐ পক্ষের ঘরের নাম, ধরন ধরে: আদায়ে "কার কাছ থেকে", পরিশোধ আর খরচে "কাকে" — দিক বোঝা যায়
            'party_label' => (string) __('accounts::phone_voucher.party_'.match ($v->type) {
                Voucher::RECEIPT => 'from',
                Voucher::PAYMENT, Voucher::EXPENSE => 'to',
                default => 'any',
            }),
            'narration' => (string) ($v->narration ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function page(Voucher $v, User $user): array
    {
        $names = $this->partyNames(collect([$v]));
        $awaiting = $v->isDraft() ? $this->awaitingIds([(int) $v->id]) : [];
        $row = $this->row($v, $names, $awaiting);

        return [
            ...$row,
            'instrument' => $v->instrument,
            'instrument_no' => $v->instrument_no,
            'written_by' => (string) ($v->creator?->name ?? ''),
            /*
             * ⭐ টাকার খাত — পক্ষের উল্টো দিক (মালিক, ৭ অক্টোবর ২০২৬: "Received from R paid into … Paid from R Paid to")।
             * আদায়ে "কোথায় জমা হলো" (নগদ/ব্যাংক/বিকাশ), পরিশোধ আর খরচে "কোথা থেকে গেল"। পোস্টের আগে `money_account_id` বসে না,
             * তাই সারি থেকে খোঁজা — সইয়ের পাতার একই নিয়ম ([[Voucher::signingSheet()]])।
             */
            ...$this->moneySide($v),
            'cancel_reason' => $v->cancel_reason,
            'lines' => $v->lines->map(fn (VoucherLine $l) => [
                'account' => trim(($l->account?->code ?? '').' '.($l->account?->name() ?? '')),
                'debit' => bcadd((string) $l->debit, '0', 2),
                'credit' => bcadd((string) $l->credit, '0', 2),
                'narration' => (string) ($l->narration ?? ''),
                // ⓘ সারির পক্ষ — জাবেদায় প্রতিটা সারির নিজের
                'party' => $l->party_type === null || $l->party_id === null ? null : ($names[$l->party_type.':'.$l->party_id] ?? null),
            ])->values(),
            /*
             * ⓘ পাকা করার বোতাম — চাবি, খসড়া, সই ঝুলে নেই, আর লেখক নিজে নন (অংশ ৩গ)। ⓘ বোতাম কেবল ইঙ্গিত; চাপলে
             * সার্ভার আবার সব দেখে ([[post()]])।
             */
            'can_post' => $row['state'] === 'draft' && $user->can('accounts.voucher.update')
                && ! $this->vouchers->writerMayNotPost($v),
            'awaits_another_hand' => $v->isDraft() && $this->vouchers->writerMayNotPost($v),
        ];
    }

    /** @param  iterable<Account>  $accounts  @return list<array<string, mixed>> */
    private function accounts(iterable $accounts): array
    {
        return collect($accounts)->map(fn (Account $a) => [
            'id' => (int) $a->id,
            'code' => (string) $a->code,
            'name' => $a->name(),
            'kind' => $a->money_kind,
        ])->values()->all();
    }
}
