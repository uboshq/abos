<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use App\Modules\Finance\Services\InsuranceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * বীমা পলিসি — মূলধনের পাতার ধাঁচে: শিরোনাম · বর্ণনা · [+ নতুন] → ট্যাব
 * → তালিকা; ফর্ম আলাদা পাতায়।
 *
 * ⓘ প্রিমিয়াম এখানে দেওয়া হয় না — "প্রিমিয়াম দিন" পরিশোধ ভাউচার খোলে,
 * আর ভাউচার পোস্ট হলে সারিটা নিজে "দেওয়া হয়েছে" হয় ([[InsurancePremium]])।
 */
class InsuranceController extends Controller implements HasMiddleware
{
    /** @var list<string> */
    private const TABS = ['all', 'due', 'unpaid', 'inactive'];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly InsuranceService $insurance,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.insurance.view', only: ['index', 'show']),
            new Middleware('can:finance.insurance.manage',
                only: ['create', 'store', 'edit', 'update', 'renewForm', 'renew', 'toggle']),
        ];
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'all';

        return view('finance::insurance.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'counts' => collect(self::TABS)->mapWithKeys(fn ($t) => [$t => $this->filtered($t)->count()]),
            'policies' => $this->filtered($tab)
                ->with(['institution', 'premiums'])
                ->orderBy('ends_on')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function show(Request $request, InsurancePolicy $policy): View
    {
        return view('finance::insurance.show', [
            'menu' => $this->menu->forUser($request->user()),
            'policy' => $policy->load(['institution', 'premiums.voucher']),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new InsurancePolicy([
            'covers' => InsurancePolicy::VEHICLE,
            'starts_on' => now()->startOfDay(),
            'ends_on' => now()->startOfDay()->addYear()->subDay(),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $policy = $this->insurance->create($this->validated($request));

        return redirect()
            ->route('finance.insurance.show', $policy)
            ->with('saved', __('finance::insurance.saved', ['no' => $policy->policy_no]));
    }

    public function edit(Request $request, InsurancePolicy $policy): View
    {
        return $this->form($request, $policy);
    }

    public function update(Request $request, InsurancePolicy $policy): RedirectResponse
    {
        $policy = $this->insurance->update($policy, $this->validated($request));

        return redirect()
            ->route('finance.insurance.show', $policy)
            ->with('saved', __('finance::insurance.saved', ['no' => $policy->policy_no]));
    }

    public function renewForm(Request $request, InsurancePolicy $policy): View
    {
        return view('finance::insurance.renew', [
            'menu' => $this->menu->forUser($request->user()),
            'policy' => $policy->load('institution'),
        ]);
    }

    public function renew(Request $request, InsurancePolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'premium' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'sum_insured' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ]);

        $this->insurance->renew($policy, $data);

        return redirect()
            ->route('finance.insurance.show', $policy)
            ->with('saved', __('finance::insurance.renewed'));
    }

    public function toggle(InsurancePolicy $policy): RedirectResponse
    {
        $this->insurance->setActive($policy, ! $policy->is_active);

        return back()->with('saved', __('finance::insurance.saved', ['no' => $policy->policy_no]));
    }

    private function form(Request $request, InsurancePolicy $policy): View
    {
        return view('finance::insurance.form', [
            'menu' => $this->menu->forUser($request->user()),
            'policy' => $policy,
            'insurers' => Institution::query()
                ->ofKind(Institution::INSURANCE)
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $policy->institution_id))
                ->orderBy('name_en')
                ->get()
                ->mapWithKeys(fn (Institution $i) => [$i->id => $i->label()])
                ->all(),
        ]);
    }

    /** @return Builder<InsurancePolicy> */
    private function filtered(string $tab): Builder
    {
        return match ($tab) {
            'due' => InsurancePolicy::query()->dueForRenewal(),
            'unpaid' => InsurancePolicy::query()->whereHas('premiums',
                fn ($q) => $q->where('status', InsurancePremium::DRAFT)),
            'inactive' => InsurancePolicy::query()->where('is_active', false),
            default => InsurancePolicy::query(),
        };
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'institution_id' => ['nullable', 'integer',
                Rule::exists('fin_institutions', 'id')
                    ->where('company_id', CompanyContext::id())
                    ->where('kind', Institution::INSURANCE)],
            'institution_new' => ['nullable', 'string', 'max:160'],
            'policy_no' => ['required', 'string', 'max:60'],
            'covers' => ['required', Rule::in(InsurancePolicy::COVERS)],
            'subject' => ['required', 'string', 'max:200'],
            'sum_insured' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'premium' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
