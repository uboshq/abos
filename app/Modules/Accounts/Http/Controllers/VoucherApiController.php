<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Models\Approval;
use App\Core\Engines\Approval\PaperSigningFacts;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Http\Requests\VoucherRequest;
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
        private readonly PaperSigningFacts $signing,
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
            /*
             * ⭐ চুক্তির সংস্করণ — ২: পরিশোধে `gross_amount` (মোট) চলে, নিট সার্ভার গোনে, আর `POST /vouchers/preview` আছে (পরিশোধে-মোট-টাকা-চুক্তি
             * §৬)। ⓘ পুরনো সার্ভারে ঘরটাই নেই — ফোন তখন পুরনো `amount` (নিট) পাঠায়।
             */
            'voucher_contract' => 2,
            ...($type === Voucher::JOURNAL
                ? ['accounts' => $this->accounts($o['allAccounts'])]
                : ['from' => $side($o['sides']['from']), 'to' => $side($o['sides']['to'])]),
            // ⭐ ওয়েবের ফর্মের মতো — জের-থাকা নিষ্ক্রিয় পক্ষও, চিহ্নসহ ([[PickableWhileOwing]])
            'parties' => collect($this->parties->forPicker(evenInactiveWithABalance: true))->map(fn (array $g) => [
                'type' => $g['type'],
                'label' => $g['label'],
                'options' => collect($g['options'])->map(fn (array $p) => [
                    'id' => $p['id'], 'label' => $p['label'], 'hint' => $p['hint'] ?? null,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * `GET /vouchers?type=&money=&branch=&status=&from=&to=&q=&awaiting=&page=`
     *
     * ⭐ `money` — টাকা কোন খাতে (মালিক, ১৫ অক্টোবর ২০২৬, অ্যাপ ০.৪.৩৪: "নগদ রসিদ" আলাদা): `cash` নগদ বা ক্যাশ-বাক্স, `bank` ব্যাংক,
     * `mfs` মোবাইল ব্যাংকিং। ⓘ ভাউচারের নিজের টাকার খাত, না বসে থাকলে সারির টাকার খাত ([[moneySide()]]-এর একই নিয়ম)।
     * ⭐ `total` — ছাঁকা তালিকার মোট টাকা, সার্ভারে গোনা (পাতা নয়, পুরো ফল)। শাখা আর দেখার নিয়ম আগের মতো ([[visible()]]).
     */
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
            'money' => ['nullable', Rule::in(Account::MONEY_KINDS)],
            // ⓘ শাখার public_id — ফোন ক্রমিক id পাঠায় না (চুক্তি §৩ ক)
            'branch' => ['nullable', 'string', 'max:64'],
        ]);

        $query = $this->visible($user)
            ->when($data['type'] ?? null, fn (Builder $q, string $t) => $q->ofType($t))
            ->when($data['money'] ?? null, fn (Builder $q, string $kind) => $this->paidInto($q, $kind))
            // ⓘ শাখা — মডেলের নিজের দেয়ালের ভিতরে; নাগালের বাইরের বা অচেনা শাখা চাইলে খালি তালিকা, অন্যের কাগজ নয়
            ->when($data['branch'] ?? null, fn (Builder $q, string $b) => $q->whereIn('branch_id',
                \App\Models\Branch::query()->where('company_id', \App\Core\Support\CompanyContext::id())->where('public_id', $b)->select('id')))
            ->search($data['q'] ?? null)
            ->when($data['from'] ?? null, fn (Builder $q, string $d) => $q->where('trx_date', '>=', $d))
            ->when($data['to'] ?? null, fn (Builder $q, string $d) => $q->where('trx_date', '<=', $d))
            ->when($data['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when((bool) ($data['awaiting'] ?? false), fn (Builder $q) => $q->whereIn('id', $this->awaitingIds()));

        // ⓘ মোট — পুরো ছাঁকা ফলের, বাতিল বাদ (বাতিল কাগজে টাকা আসেনি)
        $total = bcadd((string) ((clone $query)->where('status', '<>', \App\Core\Support\DocumentStatus::CANCELLED)->sum('amount') ?? '0'), '0', 2);

        $page = $query
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $rows = $page->getCollection();
        /*
         * ⓘ সারির পক্ষ ([[partyOf()]]) আর টাকার খাত ([[moneySide()]]) — পাতায় একবারে, সারি ধরে নয়: সারির খাত এক ডাকে,
         * মাথার টাকার খাত এক ডাকে ([[moneyAccountsOf()]])।
         */
        $rows->load(['lines:id,voucher_id,account_id,debit,credit,party_type,party_id,sort_order', 'lines.account', 'creator:id,name']);
        $names = $this->partyNames($rows);
        $awaiting = $this->awaitingIds($rows->modelKeys());
        $moneyAccounts = $this->moneyAccountsOf($rows);
        // ⭐ ধাপ আর আটকে থাকার কারণ — পাতায় দুই ডাকে, সারি ধরে নয় ([[signing()]])
        $signing = $this->signing($rows, $awaiting);

        return response()->json([
            'rows' => $rows->map(fn (Voucher $v) => [
                ...$this->row($v, $names, $awaiting, $signing),
                // ⭐ টাকা কোথায় জমা / কোথা থেকে — তালিকাতেই (মালিক, ১৭ অক্টোবর ২০২৬: "kon account e recived hoto ta dekhayna")
                ...$this->moneySide($v, $moneyAccounts),
            ])->values(),
            'count' => $page->total(),
            'total' => $total,
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

    /**
     * `POST /vouchers/preview` — খসড়ার একই payload, খাতায় যা যাবে তার ছবি (চুক্তি §৫, ২৫ অক্টোবর ২০২৬)।
     *
     * ⓘ যাচাই খসড়ার হুবহু ([[VoucherRequest]] — ভুল হলে খসড়ার একই ঘর আর চাবিতে ৪২২); হিসাব ওয়েবের simulate-এর একই
     * [[VoucherService::ledgerPreview()]]। ⛔ কিছুই লেখে না: ভাউচার, নম্বর বা হাতে লেখা নামের মানুষ — কিছুই নয় (মানুষ বানানো এই
     * দরজার নামে বন্ধ, [[VoucherRequest::prepareForValidation()]])। ⓘ ফোন নিট নিজে গোনে না — `net_amount` সার্ভারের।
     */
    public function preview(Request $request): JsonResponse
    {
        $user = $this->writer($request);
        $type = $this->type($request->input('type'));

        $paper = VoucherRequest::create($request->getPathInfo(), 'POST', $request->all());
        $paper->setContainer(app())->setRedirector(app(\Illuminate\Routing\Redirector::class));
        $paper->setUserResolver(fn () => $user);
        $paper->setRouteResolver(fn () => $request->route());
        $paper->validateResolved();

        $data = [...$paper->validated(), 'type' => $type];
        $lines = $this->writer->linesFor($type, $paper->all());
        $ledger = $this->vouchers->ledgerPreview($data, $lines);

        return response()->json([
            'type' => $type,
            'net_amount' => bcadd((string) ($data['amount'] ?? '0'), '0', 4),
            // ⭐ পোস্টে সইয়ের অপেক্ষায় যাবে কি না — পোস্টের নিজের প্রশ্নে ([[VoucherApproval::wouldWait()]]); ফোনের "সইয়ের অপেক্ষায় হবে" চিপ
            'needs_signature' => app(VoucherApproval::class)->wouldWait($data, $lines),
            ...$this->ledgerOf(collect($ledger['lines'])->map(fn (array $l) => [
                'account_id' => (int) ($l['account_id'] ?? 0),
                'debit' => (string) ($l['debit'] ?? '0'),
                'credit' => (string) ($l['credit'] ?? '0'),
            ])->all()),
        ]);
    }

    /**
     * `GET /parties/{type}/{id}/open-bills` — এই পক্ষের খোলা বিল, ওয়েবের কার্ডের একই উৎস ([[\App\Core\Contracts\PartyOpenBills]]; চুক্তি §৭.১)।
     *
     * ⓘ পক্ষটা আগে নাগালে কি না — [[PartyRegistry::exists()]] মডেলের নিজের কোয়েরিতে, তাই কোম্পানির দেয়াল আর বিক্রয়কর্মীর ডিলারের দেয়াল
     * (ScopedToUserDealers) দুটোই খাটে: অন্যের ডিলার বা অন্য কোম্পানির পক্ষে ৪০৪, তালিকা নয়। বিলের তালিকাও একই দেয়ালে ছাঁকা।
     * ⓘ `id` বিলের public_id (চুক্তি §৩-এর `bills[i][id]`), `outstanding` = বাকি; ক্রমিক আইডি বাইরে যায় না।
     */
    public function openBills(Request $request, string $type, int $id): JsonResponse
    {
        $this->writer($request);
        abort_unless($this->parties->knows($type) && $this->parties->exists($type, $id), 404);

        return response()->json([
            'bills' => collect(app(\App\Modules\Accounts\Services\VoucherAllocations::class)->openBillsOf($type, $id))->map(fn (array $b) => [
                'id' => (string) $b['public_id'],
                'no' => (string) $b['no'],
                'date' => (string) $b['date'],
                'due_date' => (string) $b['due_date'],
                'days_late' => (int) $b['days_late'],
                'total' => bcadd((string) $b['total'], '0', 4),
                'outstanding' => bcadd((string) $b['outstanding'], '0', 4),
            ])->values(),
        ]);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * ⭐ নিট — পক্ষের হাতে যা গেল বা এল, খাতার সারি থেকে (চুক্তি §৫)।
     *
     * ⛔ মাথার `amount` নয়: পোস্টের সময় সেবা সেটা ডেবিটের মোটে বদলায় ([[VoucherService::post()]]) — পরিশোধে তখন মোট + চার্জ (১,০২৫),
     * নিট (৮৭৫) নয়। ⓘ তাই টাকার খাতের সারি থেকে: পরিশোধে টাকার খাতের ক্রেডিট − চার্জ (চার্জ মোটের বাইরে), রসিদে টাকার খাতের ডেবিট
     * + চার্জ (ব্যাংক চার্জ কেটে রাখে)। খসড়া আর পাকা দুই অবস্থায় একই উত্তর; অন্য ধরনে লেখা অঙ্কই।
     */
    private function netOf(Voucher $v): string
    {
        if (! in_array($v->type, [Voucher::RECEIPT, Voucher::PAYMENT], true)) {
            return bcadd((string) ($v->amount ?? '0'), '0', 4);
        }

        $money = $v->lines
            ->filter(fn (VoucherLine $l) => $l->account?->money_kind !== null)
            ->reduce(fn (string $sum, VoucherLine $l) => bcadd($sum, (string) ($v->type === Voucher::RECEIPT ? $l->debit : $l->credit), 4), '0');
        $charge = (string) ($v->charge_amount ?? '0');

        return $v->type === Voucher::PAYMENT ? bcsub($money, $charge, 4) : bcadd($money, $charge, 4);
    }

    /**
     * ⭐ খাতার সারি ফোনের চুক্তির আকারে — `account` {id, code, name}, `debit`, `credit` (৪ দশমিকের string; a4-এর নাম, চুক্তি §৭.৩ —
     * dr/cr নয়), আর মোট দুই পাশ `total_dr`, `total_cr`।
     * ⓘ preview আর ভাউচারের পাতা একই রূপে বলে, যাতে ফোন দুটো পাশাপাশি মেলাতে পারে (চুক্তি §৫)।
     *
     * @param  list<array{account_id: int, debit: string, credit: string}>  $lines
     * @return array{ledger: list<array<string, mixed>>, total_dr: string, total_cr: string}
     */
    private function ledgerOf(array $lines): array
    {
        $accounts = Account::query()->whereKey(array_column($lines, 'account_id'))->get()->keyBy('id');
        $dr = '0';
        $cr = '0';
        $rows = [];

        foreach ($lines as $line) {
            $account = $accounts->get($line['account_id']);
            $dr = bcadd($dr, $line['debit'], 4);
            $cr = bcadd($cr, $line['credit'], 4);
            $rows[] = [
                'account' => ['id' => (int) $line['account_id'], 'code' => (string) ($account?->code ?? ''), 'name' => (string) ($account?->name() ?? '')],
                'debit' => bcadd($line['debit'], '0', 4),
                'credit' => bcadd($line['credit'], '0', 4),
            ];
        }

        return ['ledger' => $rows, 'total_dr' => $dr, 'total_cr' => $cr];
    }


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

    /**
     * ভাউচারগুলোর মাথার টাকার খাত (`money_account_id`) — এক ডাকে, সারি ধরে নয়।
     *
     * ⓘ `whereKey` — তালিকা থেকে বাছাই নয় ([[MoneyNeverLandsOnAGroupAccountTest]]); মডেলের নিজের দেয়াল আগের মতোই খাটে।
     *
     * @param  Collection<int, Voucher>  $vouchers
     * @return array<int, Account>
     */
    private function moneyAccountsOf(Collection $vouchers): array
    {
        $ids = $vouchers->pluck('money_account_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return $ids === [] ? [] : Account::query()->whereKey($ids)->get()->keyBy('id')->all();
    }

    /**
     * ⭐ টাকার খাত — পক্ষের উল্টো দিক।
     *
     * - আদায়: "কোথায় জমা হলো"; পরিশোধ আর খরচ: "কোথা থেকে গেল" (`money_account` + `money_label`, ৭ অক্টোবর থেকে)।
     * - ⭐ কনট্রা: দুই দিকই — `from_account` (টাকার খাতের ক্রেডিট সারি) আর `to_account` (টাকার খাতের ডেবিট সারি), মালিক,
     *   ১৭ অক্টোবর ২০২৬। ⓘ কনট্রার মাথার `money_account_id` পোস্টে প্রথম ব্যাংক-সারিটা নেয় ([[VoucherService::assertBankReferenceIsFree()]])
     *   — সেটা যেকোনো দিক হতে পারে, তাই কনট্রায় দিক সারি থেকেই; চার্জের সারি টাকার খাত নয়, তাই বাদ পড়ে।
     * - `money_role` (`into` | `out_of` | `contra` | null) — ফোনের তালিকার ছোট লেখা ("জমা:", "থেকে:") এটা দেখে।
     *
     * ⚠️ পুরনো চাবিগুলো (`money_account`, `money_label`) আগের মতোই — কনট্রা আর জাবেদায় null; পুরনো ফোন ভাঙে না, কেবল চাবি যোগ।
     *
     * @param  array<int, Account>|null  $moneyAccounts  মাথার টাকার খাত, আগে এক ডাকে আনা ([[moneyAccountsOf()]]); null হলে এখানেই খোঁজা
     * @return array{money_role: ?string, money_account: ?string, money_label: ?string, from_account: ?string, from_label: ?string, to_account: ?string, to_label: ?string}
     */
    private function moneySide(Voucher $v, ?array $moneyAccounts = null): array
    {
        $none = [
            'money_role' => null, 'money_account' => null, 'money_label' => null,
            'from_account' => null, 'from_label' => null, 'to_account' => null, 'to_label' => null,
        ];

        if ($v->type === Voucher::CONTRA) {
            $side = fn (string $column) => $v->lines
                ->first(fn (VoucherLine $l) => $l->account?->isMoney() === true && bccomp((string) $l->{$column}, '0', 4) > 0)
                ?->account;
            $from = $side('credit');
            $to = $side('debit');

            return [
                ...$none,
                'money_role' => $from === null && $to === null ? null : 'contra',
                'from_account' => $from?->label(),
                'from_label' => $from === null ? null : (string) __('accounts::phone_voucher.contra_from'),
                'to_account' => $to?->label(),
                'to_label' => $to === null ? null : (string) __('accounts::phone_voucher.contra_to'),
            ];
        }

        $role = match ($v->type) {
            Voucher::RECEIPT => 'into',
            Voucher::PAYMENT, Voucher::EXPENSE => 'out_of',
            default => null,
        };
        if ($role === null) {
            return $none;
        }

        // ⓘ খাতটা আগেই বাছা (ভাউচারের নিজের ঘর); পোস্টের আগে বসে না, তখন সারির টাকার খাত — সইয়ের পাতার একই নিয়ম
        if ($v->money_account_id !== null) {
            $moneyAccounts ??= $this->moneyAccountsOf(collect([$v]));
            $money = $moneyAccounts[(int) $v->money_account_id] ?? null;
        } else {
            $money = $v->lines->map(fn (VoucherLine $l) => $l->account)
                ->first(fn (?Account $a) => $a !== null && ($a->isBank() || $a->isMfs() || $a->isCash()));
        }

        return [
            ...$none,
            'money_role' => $money === null ? null : $role,
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
     * ⭐ চিপের দুই কথা, পাতা ধরে — a6, ২০ অক্টোবর ২০২৬ ([[PaperSigningFacts]])।
     *
     * ⓘ ধাপ কেবল সইয়ের অপেক্ষার ভাউচারে (`awaiting` — একই হিসাবের সই, [[awaitingIds()]]); কারণ কেবল যে খসড়ায় কোনো সই ঝুলে
     * নেই — ⛔ ঝুলে থাকা সইয়ের কাগজ "আটকে" নয়, সে এখনো সইয়ের অপেক্ষায়।
     *
     * @param  iterable<Voucher>  $vouchers
     * @param  list<int>  $awaiting
     * @return array{standing: array<int, array{level: int, levels: int}>, problems: array<int, string>}
     */
    private function signing(iterable $vouchers, array $awaiting): array
    {
        $drafts = collect($vouchers)
            ->filter(fn (Voucher $v) => $v->isDraft() && ! in_array((int) $v->id, $awaiting, true))
            ->map(fn (Voucher $v) => (int) $v->id)->values()->all();

        return [
            'standing' => $this->signing->standing(Voucher::class, $awaiting, VoucherApproval::MODULE),
            'problems' => $this->signing->postProblems(Voucher::class, $drafts),
        ];
    }

    /**
     * ⓘ "সইয়ের অপেক্ষায়" অবস্থা নয় — খসড়ার উপর ঝুলে থাকা সই ([[VoucherApproval]])। ফোনে চারটা নাম:
     * খসড়া · সইয়ের অপেক্ষায় · পাকা · বাতিল।
     *
     * ⭐ চিপের দুই কথা (a6, ২০ অক্টোবর ২০২৬; [[signing()]]):
     *  - `approval_level` / `approval_levels` — সইয়ের অপেক্ষায় থাকলে কোন ধাপে, কয় ধাপের মধ্যে ("২-এর ১"); নইলে দুটোই null।
     *  - `posting_failed` + `post_problem` — সই শেষ, পোস্ট আটকে আছে ([[SignedButNotPosted]]): true আর ওয়েবের ঘণ্টার বাংলা
     *    কারণ; নইলে false আর null।
     * ⛔ `state` বদলায় না — আটকে থাকা কাগজ আগের মতোই `draft`: পুরনো অ্যাপ চার নামের বাইরে কিছু চেনে না, `status=` ছাঁকনি
     *   আর `can_post` ওই নামেই চলে (কারণ মিটিয়ে হাতে পোস্ট — খসড়ারই কাজ)। নতুন অ্যাপ `posting_failed` দেখে "পোস্ট হয়নি" দেখায়।
     *
     * @param  array<string, string>  $names
     * @param  list<int>  $awaiting
     * @param  array{standing?: array<int, array{level: int, levels: int}>, problems?: array<int, string>}  $signing
     * @return array<string, mixed>
     */
    private function row(Voucher $v, array $names, array $awaiting, array $signing = []): array
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
            'approval_level' => $state === 'awaiting' ? ($signing['standing'][(int) $v->id]['level'] ?? null) : null,
            'approval_levels' => $state === 'awaiting' ? ($signing['standing'][(int) $v->id]['levels'] ?? null) : null,
            'posting_failed' => $state === 'draft' && isset($signing['problems'][(int) $v->id]),
            'post_problem' => $state === 'draft' ? ($signing['problems'][(int) $v->id] ?? null) : null,
            'party' => $this->partyOf($v, $names),
            // ⭐ পক্ষের ঘরের নাম, ধরন ধরে: আদায়ে "কার কাছ থেকে", পরিশোধ আর খরচে "কাকে" — দিক বোঝা যায়
            'party_label' => (string) __('accounts::phone_voucher.party_'.match ($v->type) {
                Voucher::RECEIPT => 'from',
                Voucher::PAYMENT, Voucher::EXPENSE => 'to',
                default => 'any',
            }),
            'narration' => (string) ($v->narration ?? ''),
            // ⭐ কে তুলেছেন — তালিকাতেই (মালিক, ১৫ অক্টোবর ২০২৬)
            'written_by' => (string) ($v->creator?->name ?? ''),
        ];
    }

    /**
     * টাকা এই ধরনের খাতে — ভাউচারের নিজের টাকার খাত, না বসে থাকলে (পোস্টের আগে) সারির টাকার খাত ([[moneySide()]])।
     */
    private function paidInto(Builder $q, string $kind): void
    {
        $accounts = Account::query()->where('money_kind', $kind)->select('id');

        $q->where(fn (Builder $w) => $w
            ->whereIn('money_account_id', $accounts)
            ->orWhere(fn (Builder $n) => $n->whereNull('money_account_id')
                ->whereHas('lines', fn (Builder $l) => $l->whereIn('account_id', Account::query()->where('money_kind', $kind)->select('id')))));
    }

    /** @return array<string, mixed> */
    private function page(Voucher $v, User $user): array
    {
        $names = $this->partyNames(collect([$v]));
        $awaiting = $v->isDraft() ? $this->awaitingIds([(int) $v->id]) : [];
        $row = $this->row($v, $names, $awaiting, $this->signing([$v], $awaiting));

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
            // ⭐ নিট আর খাতা সার্ভারের — ফোন নিজে গোনে না (চুক্তি §৫); preview-এর একই রূপ ([[ledgerOf()]])
            'net_amount' => $this->netOf($v),
            // ⭐ মোট — পক্ষের খাতা যতটা নড়ে: নিট + AIT + VDS (চুক্তি §৭.৪; পরিশোধে নকশার "মোট টাকা", রসিদে পাওয়া + পক্ষের কাটা অংশ)
            'gross_amount' => bcadd(bcadd($this->netOf($v), (string) ($v->ait_amount ?? '0'), 4), (string) ($v->vds_amount ?? '0'), 4),
            ...$this->ledgerOf($v->lines->map(fn (VoucherLine $l) => [
                'account_id' => (int) $l->account_id,
                'debit' => (string) $l->debit,
                'credit' => (string) $l->credit,
            ])->values()->all()),
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
