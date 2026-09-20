<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;
use App\Modules\Finance\Services\InstitutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * আর্থিক প্রতিষ্ঠানের তালিকা — মূলধনের পাতার ধাঁচে (মালিকের নমুনা):
 * শিরোনাম · বর্ণনা · [+ নতুন] → ধরনের ট্যাব → তালিকা; ফর্ম আলাদা পাতায়।
 */
class InstitutionController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly InstitutionService $institutions,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.institution.view', only: ['index', 'show']),
            new Middleware('can:finance.institution.manage',
                only: ['create', 'store', 'edit', 'update', 'toggle', 'link', 'unlink']),
        ];
    }

    public function index(Request $request): View
    {
        $kind = in_array($request->query('kind'), Institution::KINDS, true) ? $request->query('kind') : null;

        return view('finance::institution.index', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => $kind,
            'counts' => Institution::query()->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind'),
            'institutions' => Institution::query()
                ->ofKind($kind)
                ->orderByDesc('is_active')
                ->orderBy('name_en')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    /**
     * একটা প্রতিষ্ঠান — এখানে আমাদের কী কী আছে, এক পাতায়।
     *
     * ⓘ "হিসাবের খাত" অংশে জোড়া দেওয়া ব্যাংক/MFS খাত আর তাদের আজকের জের;
     * জের আসে খতিয়ান থেকে ([[Account::balanceOn()]]), এখানে কিছু লেখা থাকে না।
     */
    public function show(Request $request, Institution $institution): View
    {
        $links = $institution->accountLinks()->with('account')->get()
            ->filter(fn (InstitutionAccount $l) => $l->account !== null)
            ->map(fn (InstitutionAccount $l) => [
                'account' => $l->account,
                'balance' => $l->account->balanceOn(now()->toDateString()),
            ])
            ->values();

        $kinds = $institution->kind === Institution::MFS ? [Account::MFS] : [Account::BANK];

        return view('finance::institution.show', [
            'menu' => $this->menu->forUser($request->user()),
            'institution' => $institution,
            'links' => $links,
            'balanceTotal' => $links->reduce(fn (string $c, array $l) => bcadd($c, $l['balance'], 4), '0'),
            'linkable' => in_array($institution->kind, [Institution::BANK, Institution::NBFI, Institution::MFS], true)
                ? Account::query()
                    ->whereIn('money_kind', $kinds)
                    ->where('is_group', false)
                    ->whereNotIn('id', InstitutionAccount::query()->select('account_id'))
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])
                    ->all()
                : [],
            'policies' => $institution->insurancePolicies()->orderBy('ends_on')->get(),

            /*
             * ⓘ এই ব্যাংকের ঋণ আর আমানত — দুইটাই `institution_id` ধরে।
             * ⚠️ পুরনো সারিতে ঘরটা ফাঁকা থাকতে পারে (হাতে লেখা নামটা
             * মাইগ্রেশন মেলাতে পারেনি); ওগুলো এখানে ওঠে না, আর সেটাই
             * ঠিক — অনুমান করে দেখালে ভুল ব্যাংকের হিসাব যোগ হত।
             */
            'facilities' => BankFacility::query()
                ->where('institution_id', $institution->id)
                ->orderByDesc('sanctioned_on')
                ->get(),
            'deposits' => Deposit::query()
                ->where('institution_id', $institution->id)
                ->with('kind')
                ->orderByDesc('opened_on')
                ->get(),
        ]);
    }

    /** "খাত জোড়ো" */
    public function link(Request $request, Institution $institution): RedirectResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer']]);

        $this->institutions->link($institution, (int) $data['account_id']);

        return redirect()->route('finance.institution.show', $institution)
            ->with('saved', __('finance::institution.linked'));
    }

    public function unlink(Institution $institution, int $account): RedirectResponse
    {
        $this->institutions->unlink($institution, $account);

        return redirect()->route('finance.institution.show', $institution)
            ->with('saved', __('finance::institution.unlinked'));
    }

    public function create(Request $request): View
    {
        $kind = in_array($request->query('kind'), Institution::KINDS, true) ? $request->query('kind') : Institution::BANK;

        return view('finance::institution.form', [
            'menu' => $this->menu->forUser($request->user()),
            'institution' => new Institution(['kind' => $kind]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $institution = $this->institutions->create($this->validated($request));

        return redirect()
            ->route('finance.institution.index', ['kind' => $institution->kind])
            ->with('saved', __('finance::institution.saved', ['name' => $institution->name()]));
    }

    public function edit(Request $request, Institution $institution): View
    {
        return view('finance::institution.form', [
            'menu' => $this->menu->forUser($request->user()),
            'institution' => $institution,
        ]);
    }

    public function update(Request $request, Institution $institution): RedirectResponse
    {
        $institution = $this->institutions->update($institution, $this->validated($request));

        return redirect()
            ->route('finance.institution.index', ['kind' => $institution->kind])
            ->with('saved', __('finance::institution.saved', ['name' => $institution->name()]));
    }

    /** বন্ধ বা চালু — মোছা নয়, পুরনো ঋণ ও আমানত নামটা ধরে রাখে */
    public function toggle(Institution $institution): RedirectResponse
    {
        $this->institutions->setActive($institution, ! $institution->is_active);

        return back()->with('saved', __('finance::institution.saved', ['name' => $institution->name()]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(Institution::KINDS)],
            'name_en' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'short_code' => ['nullable', 'string', 'max:32'],
            'branch_name' => ['nullable', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
