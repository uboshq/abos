<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Reports\TenancyReports;
use App\Modules\Finance\Services\TenancyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * ⭐ ভাড়াটের চুক্তি — আমরা যখন জায়গা ভাড়া দিই (মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬; [[TenancyService]])।
 *
 * ⓘ চাবি ভাড়ার চুক্তিরই (দেখা, বসানো, শেষ করা) — একই কাজ, উল্টো দিক; নতুন চাবি মানে প্রতিটা ভূমিকায় আবার বসানো।
 */
class TenancyController extends Controller implements HasMiddleware
{
    use GrandTotals;

    /** ⭐ রিপোর্ট — স্লাগ থেকে চাবি; দরজার চাবি আর সংজ্ঞার চাবি এক কি না পাহারা মেলায় ([[EveryReportNamesTheKeyItsWebDoorAsksForTest]]) */
    public const SLUGS = [
        'collections' => TenancyReports::COLLECTIONS,
        'arrears' => TenancyReports::ARREARS,
    ];

    public function __construct(
        private readonly TenancyService $tenancies,
        private readonly MenuBuilder $menu,
        private readonly ReportEngine $reports,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.rental.view', only: ['index', 'show']),
            new Middleware('can:finance.rental.create', only: [
                'create', 'store', 'collect', 'receiveDeposit', 'fromDeposit', 'revise', 'charge',
            ]),
            // ⓘ শেষ করা আর জামানত ফেরত — ভাড়ার চুক্তির শেষের চাবি; ভুল করে করলে জামানতের দায় খাতা থেকে মুছে যেত
            new Middleware('can:finance.rental.close', only: ['close', 'refund']),
        ];
    }

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'closed' ? 'closed' : 'running';

        $query = Tenancy::query()->inViewedBranch()
            ->where('status', $tab === 'closed' ? Tenancy::CLOSED : Tenancy::ACTIVE)
            ->when(trim((string) $request->query('q')) ?: null, fn ($q, $term) => $q->where(
                fn ($w) => $w->where('tenant', 'like', "%{$term}%")->orWhere('document_no', 'like', "%{$term}%")
                    ->orWhere('premises', 'like', "%{$term}%")->orWhere('tenant_phone', 'like', "%{$term}%")
            ))
            ->orderBy('ends_on')->orderBy('id');

        return view('finance::tenancy.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'grand' => $this->grandTotals($query, ['monthly_rent' => 't.monthly_rent']),
            'tenancies' => $query->paginate(50)->withQueryString(),
            'counts' => [
                'running' => Tenancy::query()->inViewedBranch()->active()->count(),
                'closed' => Tenancy::query()->inViewedBranch()->where('status', Tenancy::CLOSED)->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('finance::tenancy.create', [
            'menu' => $this->menu->forUser($request->user()),
            'parties' => $this->parties(),
            'money' => $this->moneyAccounts(),
            'incomes' => Account::query()->postable()->active()->where('type', Account::INCOME)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'party' => ['required', 'string', 'max:64'],
            'tenant' => ['nullable', 'string', 'max:191'],
            'tenant_phone' => ['nullable', 'string', 'max:40'],
            'premises' => ['nullable', 'string', 'max:191'],
            'monthly_rent' => ['required', 'numeric', 'gt:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'rent_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'starts_on' => ['required', 'date'],
            'term_months' => ['required', 'integer', 'min:1', 'max:600'],
            'income_account_id' => ['nullable', 'integer'],
            'money_account_id' => ['nullable', 'integer'],
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        [$type, $id] = array_pad(explode(':', (string) $data['party'], 2), 2, null);
        unset($data['party']);

        $tenancy = $this->tenancies->open($data + ['party_type' => (string) $type, 'party_id' => (int) $id]);

        return redirect()->route('finance.tenancy.show', $tenancy)->with('saved', $tenancy->status === Tenancy::AWAITING
            ? __('finance::message.awaiting_signature')
            : __('finance::tenancy.opened', ['no' => $tenancy->document_no]));
    }

    public function show(Request $request, Tenancy $tenancy): View
    {
        return view('finance::tenancy.show', [
            'menu' => $this->menu->forUser($request->user()),
            'tenancy' => $tenancy->load('incomeAccount'),
            'charges' => $tenancy->charges()->with('voucher')->reorder()->orderByDesc('for_month')->get(),
            'moves' => $tenancy->moves()->with(['voucher', 'moneyAccount'])->reorder()->orderByDesc('moved_on')->orderByDesc('id')->get(),
            'money' => $this->moneyAccounts(),
            'waiting' => $this->tenancies->isWaiting($tenancy),
        ]);
    }

    public function collect(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $this->tenancies->collect($tenancy, $this->money($request));

        return $this->done($tenancy);
    }

    public function receiveDeposit(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $this->tenancies->receiveDeposit($tenancy, $this->money($request));

        return $this->done($tenancy);
    }

    public function fromDeposit(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $this->tenancies->fromDeposit($tenancy, $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'moved_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]));

        return $this->done($tenancy);
    }

    public function refund(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $this->tenancies->refund($tenancy, $this->money($request));

        return $this->done($tenancy);
    }

    public function close(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $this->tenancies->close($tenancy, $request->validate(['closed_on' => ['nullable', 'date']]));

        return back()->with('saved', __('finance::tenancy.closed_done'));
    }

    public function revise(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $this->tenancies->revise($tenancy, $request->validate([
            'monthly_rent' => ['nullable', 'numeric', 'gt:0'],
            'rent_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'tenant_phone' => ['nullable', 'string', 'max:40'],
            'premises' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:500'],
        ]));

        return back()->with('saved', __('finance::tenancy.revised'));
    }

    /** ⭐ মাসের ভাড়া দাবি — চালু সব ভাড়াটের, এক মাস একবার (চলতি মাস কমান্ড নিজেই বসায়; [[RentAccrue]]) */
    public function charge(Request $request): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $done = $this->tenancies->charge(Carbon::createFromFormat('Y-m-d', $data['month'].'-01'));

        return back()->with('saved', __('finance::tenancy.charge_done', $done));
    }

    public function report(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);
        Gate::authorize($definition->permission);

        $result = $this->reports->run($key, $request->only($definition->requestKeys()), page: max(1, (int) $request->query('page', 1)), byBranch: true);

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            'extraFilters' => 'finance::tenancy.partials.report-tabs',
            'summary' => $definition->summary === null ? null : ($definition->summary)($result->totals),
        ]);
    }

    // ── ভিতরের ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function money(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'money_account_id' => ['required', 'integer'],
            'moved_on' => ['nullable', 'date'],
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function done(Tenancy $tenancy): RedirectResponse
    {
        return back()->with('saved', $this->tenancies->isWaiting($tenancy->fresh())
            ? __('finance::message.awaiting_signature')
            : __('finance::tenancy.moved'));
    }

    /**
     * ভাড়াটে — ব্যক্তি বা গ্রাহক ([[Tenancy::PARTY_TYPES]]); তালিকা কোরের [[PartyRegistry]] থেকে, কারণ অর্থ গ্রাহক মডিউলের উপর
     * নির্ভর করে না।
     *
     * @return array<string, string>
     */
    private function parties(): array
    {
        $out = [];

        foreach (app(PartyRegistry::class)->forPicker() as $group) {
            if (! in_array($group['type'], Tenancy::PARTY_TYPES, true)) {
                continue;
            }

            foreach ($group['options'] as $option) {
                $out[$group['type'].':'.$option['id']] = $group['label'].' — '.$option['label'];
            }
        }

        return $out;
    }

    /** @return Collection<int, Account> */
    private function moneyAccounts()
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->get();
    }
}
