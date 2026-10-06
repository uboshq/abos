<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositSlip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        return view('sales::claim.request', [
            'menu' => $this->menu->forUser($request->user()),
            'customers' => Customer::query()->inViewedBranch()->active()->orderBy('name_en')->get(),
            'banks' => $this->banks(),
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
        $customer = $this->customer((string) $request->query('customer', ''));

        return response()->json(['requests' => DepositClaim::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('claimed_on')->orderByDesc('id')
            ->limit(30)->get()
            ->map(fn (DepositClaim $c) => $this->facts($c))->values()]);
    }

    /** `GET /sales/deposit-claims/{claim}/slip` — হিসাবরক্ষকের চোখে স্লিপ */
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in([DepositClaim::BANK, DepositClaim::MFS, DepositClaim::CASH])],
            'reference' => ['nullable', 'string', 'max:64'],
            'bank_account_id' => ['nullable', 'integer', Rule::in($this->banks()->modelKeys())],
            'note' => ['nullable', 'string', 'max:500'],
            'slip' => [Rule::requiredIf($request->input('method') === DepositClaim::BANK), 'nullable', 'file',
                'max:'.intdiv(\App\Core\Engines\Attachment\AttachmentEngine::SLIP_MAX_BYTES, 1024)],
        ], [
            'slip.required' => __('sales::slip.required'),
        ]);

        $customer = $this->customer($data['customer']);
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
            'decision_reason' => $claim->decision_reason,
            'has_slip' => $this->slips->of($claim) !== null,
        ];
    }
}
