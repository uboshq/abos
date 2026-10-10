<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DepositClaimService;
use App\Modules\Sales\Services\DepositSlip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * কর্মীর হাতে জমার অনুরোধ, স্লিপসহ — ওয়েব আর অ্যাপ (মালিক, ১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ কে কী পারেন ─────────────────────────────────────────────────────
 * পাঠানো — যাঁর আদায় নেওয়ার চাবি (`sales.collection.create`, SR/মাঠকর্মী): দোকানি ব্যাংকে টাকা দিলেন, SR
 *          স্লিপের ছবি তুলে পাঠালেন। ⓘ এটা অনুরোধ, টাকা নয় — হিসাবরক্ষক স্লিপ মিলিয়ে গ্রহণ করলে তবেই আদায়
 *          ([[DepositClaimController]], `sales.claim.decide`)। তাই নতুন চাবি লাগে না।
 * স্লিপ দেখা — যাঁর দাবি দেখার চাবি (`sales.claim.view`)।
 *
 * ⛔ ব্যাংকে জমায় স্লিপ ছাড়া অনুরোধ ওঠে না — যাচাইয়ের একমাত্র কাগজ ওটাই। ⛔ ব্যাংক খাত কেবল এই কোম্পানির,
 * চালু, ব্যাংক-ধরনের। গ্রাহক কেবল এই কোম্পানির (ফোন পাঠায় `public_id`, ওয়েব ক্রমিক id)।
 */
class DepositRequestController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly DepositSlip $slips,
        private readonly MenuBuilder $menu,
        private readonly DepositClaimService $claims,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.collection.create', except: ['slip']),
            new Middleware('can:sales.claim.view', only: ['slip']),
        ];
    }

    /** `GET /sales/deposit-requests/new` */
    public function create(Request $request): View
    {
        /*
         * ⓘ গ্রাহক বাছা থাকলে (`?customer=`, ফর্মের "বিল দেখুন" বোতাম) তাঁর খোলা বিল — "কোন বিলের বিপরীতে" ঘর। পাতা আবার খোলে,
         * জাভাস্ক্রিপ্ট ছাড়া: বান্ডেল বদলায় না (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬)।
         */
        $picked = (string) old('customer', PhoneInput::text($request, 'customer', ''));
        $customer = ctype_digit($picked) ? Customer::query()->inViewedBranch()->whereKey((int) $picked)->first() : null;

        return view('sales::claim.request', [
            'menu' => $this->menu->forUser($request->user()),
            'customers' => Customer::query()->inViewedBranch()->active()->orderBy('name_en')->get(),
            'banks' => $this->banks(),
            'picked' => $customer?->id,
            'openBills' => $customer === null ? null : $this->claims->openBills($customer),
        ]);
    }

    /** `POST /sales/deposit-requests` */
    public function store(Request $request): RedirectResponse
    {
        $claim = $this->raise($request);

        return redirect()->route('sales.claim.request.create')
            ->with('status', __('sales::slip.sent', ['no' => $claim->id]));
    }

    /** `GET /api/v1/sales/deposit-requests/accounts` — অ্যাপের ব্যাংক বাছাই */
    public function accounts(): JsonResponse
    {
        return response()->json(['accounts' => $this->banks()->map(fn (Account $a) => [
            'id' => (int) $a->id,
            'name' => $a->label(),
        ])->values()]);
    }

    /** `POST /api/v1/sales/deposit-requests` — multipart, `slip` ফাইল */
    public function apiStore(Request $request): JsonResponse
    {
        return response()->json($this->facts($this->raise($request)), 201);
    }

    /** `GET /api/v1/sales/deposit-requests?customer=` — এক দোকানের অনুরোধগুলো, সর্বশেষ আগে */
    public function apiIndex(Request $request): JsonResponse
    {
        $customer = $this->customer(PhoneInput::text($request, 'customer', ''));

        return response()->json(['requests' => DepositClaim::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('claimed_on')->orderByDesc('id')
            ->limit(30)->get()
            ->map(fn (DepositClaim $c) => $this->facts($c))->values()]);
    }

    /** `GET /sales/deposit-claims/{claim}/slip` — হিসাবরক্ষকের চোখে স্লিপ */
    /**
     * `GET /deposit-requests/bills?customer=` — ডিলারের খোলা বিল, পুরনো আগে, বকেয়াসহ ([[DepositClaimService::openBills()]])।
     * ⭐ বিজ্ঞপ্তির "কোন বিলের বিপরীতে" (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬) — ফোন আর ওয়েবের অনুরোধ-ফর্ম একই দরজায়।
     * ⓘ চাবি আর দেয়াল অনুরোধ লেখার একই: `sales.collection.create`, আর গ্রাহক দেখা শাখায় ([[customer()]])।
     */
    public function bills(Request $request): JsonResponse
    {
        $customer = $this->customer(PhoneInput::text($request, 'customer', ''));

        return response()->json(['bills' => $this->claims->openBills($customer)->map(fn (SalesInvoice $i) => [
            'id' => (string) $i->public_id,
            'no' => (string) $i->document_no,
            'date' => $i->trx_date?->toDateString(),
            'total' => bcadd((string) $i->total, '0', 2),
            'due' => bcadd($i->dueAmount(), '0', 2),
        ])->values()]);
    }

    public function slip(DepositClaim $claim): StreamedResponse
    {
        return $this->slips->stream($claim);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function raise(Request $request): DepositClaim
    {
        $data = $request->validate([
            'customer' => ['required', 'string', 'max:64'],
            'claimed_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', PhoneInput::DECIMAL, 'gt:0'],
            'method' => ['required', Rule::in([DepositClaim::BANK, DepositClaim::MFS, DepositClaim::CASH])],
            'reference' => ['nullable', 'string', 'max:64'],
            'bank_account_id' => ['nullable', 'integer', Rule::in($this->banks()->modelKeys())],
            'note' => ['nullable', 'string', 'max:500'],
            'slip' => [Rule::requiredIf($request->input('method') === DepositClaim::BANK), 'nullable', 'file',
                'max:'.intdiv(\App\Core\Engines\Attachment\AttachmentEngine::SLIP_MAX_BYTES, 1024)],
            // ⭐ কোন বিলের বিপরীতে — ঐচ্ছিক; বিলের public_id আর অঙ্ক ([[DepositClaimService::raise()]] বাকিটা দেখে)
            'bills' => ['nullable', 'array', 'max:50'],
            'bills.*.invoice' => ['required', 'string', 'max:64'],
            'bills.*.amount' => ['nullable', 'numeric', PhoneInput::DECIMAL, 'min:0'],
        ], [
            'slip.required' => __('sales::slip.required'),
        ]);

        $customer = $this->customer($data['customer']);
        $data['bills'] = self::billIds($customer, (array) ($data['bills'] ?? []));
        unset($data['customer'], $data['slip']);

        // ⓘ কে পাঠালেন — নিরীক্ষায় থাকে (DepositClaim IsAudited); নোটে নাম, যাতে তালিকাতেই দেখা যায়
        $data['note'] = trim(__('sales::slip.by', ['name' => (string) $request->user()?->name]).' '.($data['note'] ?? ''));

        return $this->slips->raise($customer, $data, $request->file('slip'));
    }

    private function customer(string $key): Customer
    {
        /*
         * ⛔ দেখার শাখার গ্রাহকই; ফোনে কেবল public_id (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⛔৩): আগে শাখা ছাড়া খোঁজা, আর
         * সংখ্যার আইডিও চলত — /standing/1, /standing/2 … অন্য শাখার গ্রাহকের নামে জমার বিজ্ঞপ্তি তোলা যেত। ⓘ ওয়েবের ফর্ম
         * এখনো সংখ্যার আইডি পাঠায়, তাই সংখ্যা কেবল ওয়েবের দরজায়।
         */
        $phone = str_starts_with(request()->path(), 'api/');
        abort_if($phone && ! Str::isUuid($key), 404);

        return Customer::query()->inViewedBranch()
            ->when(Str::isUuid($key), fn ($q) => $q->where('public_id', $key), fn ($q) => $q->whereKey((int) $key))
            ->firstOrFail();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Account> */
    /**
     * বিলের public_id → ভেতরের id, কেবল এই গ্রাহকের বিলে — অন্যের বা অচেনা হলে ৪২২ (পোর্টালও এটাই ডাকে)।
     *
     * @param  list<array<string, mixed>>  $rows  `[{invoice, amount}]`
     * @return list<array{sales_invoice_id: int, amount: string}>
     */
    public static function billIds(Customer $customer, array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => is_array($r) && filled($r['amount'] ?? null) && filled($r['invoice'] ?? null)));
        if ($rows === []) {
            return [];
        }

        $ids = SalesInvoice::query()->where('customer_id', $customer->id)
            ->whereIn('public_id', array_map(fn (array $r) => (string) $r['invoice'], $rows))->pluck('id', 'public_id');

        return array_map(function (array $r) use ($ids): array {
            if (! isset($ids[(string) $r['invoice']])) {
                throw ValidationException::withMessages(['bills' => __('sales::slip.bill_not_theirs')]);
            }

            return ['sales_invoice_id' => (int) $ids[(string) $r['invoice']], 'amount' => (string) $r['amount']];
        }, $rows);
    }

    private function banks()
    {
        return Account::query()->ofMoneyKind(Account::BANK)->active()->orderBy('code')->get();
    }

    /** @return array<string, mixed> */
    private function facts(DepositClaim $claim): array
    {
        return [
            'id' => (string) $claim->public_id,
            'claimed_on' => $claim->claimed_on?->toDateString(),
            'amount' => bcadd((string) $claim->amount, '0', 2),
            'method' => (string) $claim->method,
            'reference' => $claim->reference,
            'status' => (string) $claim->status,
            // ⭐ অবস্থার নাম সার্ভারের ভাষায় — ফোন এটাই দেখায় (টাকার পরিকল্পনা ১, ৭ অক্টোবর ২০২৬; a4, 8e39aaa5)
            'status_label' => $claim->statusLabel(),
            'decision_reason' => $claim->decision_reason,
            'has_slip' => $this->slips->of($claim) !== null,
            // ⓘ বাছা বিল — দাবির প্রস্তাব; গ্রহণের পরে আসল ভাগ আদায়ের সারিতে
            'bills' => self::billFacts($claim),
        ];
    }

    /** @return list<array{id: string, no: string, amount: string}> */
    public static function billFacts(DepositClaim $claim): array
    {
        $bills = (array) $claim->bills;
        if ($bills === []) {
            return [];
        }

        $invoices = SalesInvoice::query()->whereKey(array_map(fn ($b) => (int) ($b['sales_invoice_id'] ?? 0), $bills))->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($b) => ($i = $invoices->get((int) ($b['sales_invoice_id'] ?? 0))) === null ? null : [
            'id' => (string) $i->public_id,
            'no' => (string) $i->document_no,
            'amount' => bcadd((string) ($b['amount'] ?? '0'), '0', 2),
        ], $bills)));
    }
}
