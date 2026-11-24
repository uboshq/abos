<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\ListFilter\Facets;
use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\PerPage;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Company;
use App\Modules\SystemAdmin\Support\ModuleBoard;
use App\Modules\SystemAdmin\Support\ModulePackages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * কোন শাখা কোন মডিউল পাবে।
 *
 * ── ⓘ কেন কন্ট্রোল প্যানেলের পাশে, ভিতরে নয় (২৮ নভেম্বর ২০২৬) ─────────
 * কন্ট্রোল প্যানেল **কোম্পানির** সুইচ ধরে — `settings` টেবিল, unique `(company_id, key)`। ⛔ ওখানে শাখা ঢোকাতে হলে ঐ unique index
 * বদলাতে হত। ⭐ তাই আলাদা টেবিল ([[BranchModule]])।
 *
 * ⚠️ ক্রমটা এক দিকেই চলে: শাখা এমন কিছু **চালু করতে পারে না** যা কোম্পানি বন্ধ রেখেছে ([[MenuBuilder::moduleEnabled()]])। পর্দায় ঐ
 * সারিগুলো ধরা থাকে, লুকানো নয়।
 *
 * ⭐ অনেক শাখা একসাথে (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4): বাঁয়ে খোঁজা-ছাঁকা তালিকা পাতা ধরে, ডানে মডিউল; লেখার আগে কী
 *   বদলাবে তার তালিকা। ⛔ শাখা ধরে আলাদা প্রশ্ন বা কলাম নেই — একশো শাখাতেও একই।
 */
class BranchModuleController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly ModuleBoard $board,
        private readonly ModulePackages $packages,
        private readonly MenuBuilder $menu,
        private readonly ListExport $export,
    ) {}

    public static function middleware(): array
    {
        /*
         * ⓘ কন্ট্রোল প্যানেলের চাবিই — দুইটা পর্দা একই প্রশ্নের দুই অর্ধেক
         * ("কোন পর্দাগুলো থাকবে")। ⚠️ আলাদা চাবি দিলে কাউকে অর্ধেক উত্তর
         * বদলানোর অধিকার দেওয়া হত, আর অর্ধেক উত্তর কোনো উত্তর নয়।
         */
        return [new Middleware('can:system_admin.settings.manage')];
    }

    /** পুরনো ঠিকানা (`/branch-modules?branch=…`) — নতুন পর্দায়, সেই শাখাটা বাছা অবস্থায় */
    public function edit(Request $request): RedirectResponse
    {
        $branch = (int) $request->query('branch', 0);

        return redirect()->route('system_admin.module.branches', $branch > 0 ? ['pick' => [$branch]] : []);
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $menu = $this->menu->forUser($user);
        $companies = $this->board->companiesFor($user);
        $company = $this->company($request);

        return CompanyContext::forCompany((int) $company->id, function () use ($request, $menu, $companies, $company): View {
            $q = is_string($request->query('q')) ? trim($request->query('q')) : '';
            $state = is_string($request->query('state')) ? $request->query('state') : '';
            $query = $this->board->branches($q, $state);
            $allowed = (clone $query)->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($request->query('sort') === 'off') {
                $query->reorder()->orderByDesc(BranchModule::query()->selectRaw('COUNT(*)')->whereColumn('branch_modules.branch_id', 'branches.id')->where('is_enabled', false))
                    ->orderBy('name_en')->orderBy('id');
            }
            $rows = $query->paginate(PerPage::of())->withQueryString();

            $ids = ModuleBoard::picked($request->query('pick', []), $allowed);
            if ($ids === [] && $allowed !== []) {
                $ids = [$allowed[0]];
            }

            $pageIds = collect($rows->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $copy = (int) $request->query('copy_from', 0);
            $off = $this->board->branchOff(array_values(array_unique([...$pageIds, ...$ids, ...($copy > 0 ? [$copy] : [])])));
            $names = Branch::query()->whereIn('id', [...$ids, $copy])->get()->mapWithKeys(fn (Branch $b) => [(int) $b->id => $b->name()])->all();
            $wall = $this->board->companyOff([(int) $company->id])[(int) $company->id];
            $copying = isset($names[$copy]);

            $this->offerTheMatrix($rows, $off, $wall, $company);

            return view('system_admin::module-board.board', [
                'menu' => $menu,
                'kind' => 'branch',
                'rows' => $rows,
                'off' => $off,
                'packages' => $this->packages->namesFor($pageIds),
                'ids' => $ids,
                'names' => $names,
                'groups' => $this->board->rows($ids, $copying ? array_fill_keys($ids, $off[$copy]) : $off, $wall),
                'was' => $this->board->rows($ids, $off, $wall),
                'copyable' => collect($rows->items())->mapWithKeys(fn (Branch $b) => [(int) $b->id => $b->name()])->all(),
                'copied' => $copying ? $names[$copy] : null,
                'history' => $this->board->branchHistory($ids, $names),
                'companies' => $companies,
                'company' => $company,
                'facets' => Facets::panel([
                    Facets::pick('state', __('core.table.status'), ['diff' => __('system_admin::module_board.differs'), 'same' => __('system_admin::module_board.like_company')]),
                ]),
            ]);
        });
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $menu = $this->menu->forUser($request->user());
        $company = $this->company($request);
        [$names, $plan] = $this->planned($request, $company);
        $back = route('system_admin.module.branches', ['company' => $company->id, 'pick' => array_keys($names)]);

        if ($plan['changes'] === []) {
            return redirect($back)->with('saved', __('system_admin::module_board.nothing'));
        }

        return view('system_admin::module-board.preview', [
            'menu' => $menu,
            'kind' => 'branch', 'plan' => $plan, 'board' => $this->board, 'stamp' => $this->board->stamp($plan['changes']),
            'apply' => route('system_admin.module.branches.apply'),
            'back' => $back,
            'payload' => ['company' => $company->id, ...$request->only(['pick', 'scope', 'modules', 'was', 'all_on', 'reset'])],
            'stale' => false,
        ]);
    }

    public function apply(Request $request): View|RedirectResponse
    {
        $company = $this->company($request);
        [$names, $plan] = $this->planned($request, $company);

        // ⛔ দেখার পরে অন্য কেউ বদলেছেন — নতুন তালিকা আবার দেখাও, চুপচাপ লেখা নয়
        if ($this->board->stamp($plan['changes']) !== $request->input('stamp')) {
            return $this->preview($request)->with('stale', true);
        }

        $userId = (int) $request->user()->id;
        $count = CompanyContext::forCompany((int) $company->id, fn (): int => $this->board->writeBranches($plan['changes'], $userId));

        return redirect()->route('system_admin.module.branches', ['company' => $company->id, 'pick' => array_keys($names)])
            ->with('saved', __('system_admin::module_board.saved', ['count' => $count]));
    }

    /**
     * পুরনো সংরক্ষণের দরজা — এক শাখা, চলতি কোম্পানি; ফর্ম আর হাতের লিংক আগের মতোই চলে।
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch' => ['required', 'integer'],
            'scope' => ['array'],
            'scope.*' => ['string', 'max:64'],
            'modules' => ['array'],
        ]);

        // ⛔ শাখাটা এই কোম্পানির আর সচল কি না — দেয়াল [[BelongsToCompany]]
        $branch = $this->board->branches()->whereKey((int) $data['branch'])->first();
        abort_if($branch === null, 404);

        $id = (int) $branch->id;
        $wanted = $this->board->wanted($data['scope'] ?? [], $data['modules'] ?? [], [], []);
        $wall = $this->board->companyOff([(int) $branch->company_id])[(int) $branch->company_id];
        $plan = $this->board->plan([$id => $branch->name()], $this->board->branchOff([$id]), $wanted, $wall);
        $count = $this->board->writeBranches($plan['changes'], (int) $request->user()->id);

        return redirect()
            ->route('system_admin.module.branches', ['pick' => [$id]])
            ->with('saved', __('system_admin::branch_module.saved', ['count' => $count]));
    }

    /** বাছা কোম্পানি — না বললে চলতিটা; চাবিটা ঐ কোম্পানিতে না থাকলে ৪০৪ */
    private function company(Request $request): Company
    {
        $id = (int) $request->input('company', CompanyContext::id());
        abort_unless($request->user()->canInCompany($id, 'system_admin.settings.manage'), 404);

        return Company::query()->findOrFail($id);
    }

    /** @return array{0: array<int, string>, 1: array<string, mixed>} */
    private function planned(Request $request, Company $company): array
    {
        $data = $request->validate([
            'pick' => ['required', 'array', 'min:1'], 'pick.*' => ['integer'],
            'scope' => ['array'], 'scope.*' => ['string', 'max:64'], 'modules' => ['array'], 'was' => ['array'], 'all_on' => ['array'],
        ]);
        $asked = array_values(array_unique(array_map('intval', $data['pick'])));

        return CompanyContext::forCompany((int) $company->id, function () use ($request, $company, $data, $asked): array {
            $names = $this->board->branches()->whereIn('id', $asked)->get()->mapWithKeys(fn (Branch $b) => [(int) $b->id => $b->name()])->all();
            // ⛔ অন্য কোম্পানির বা বন্ধ শাখা — একটাও থাকলে পুরোটাই ৪০৪, অর্ধেক লেখা নয়
            abort_if(count($names) !== count($asked), 404);

            $off = $this->board->branchOff($asked);
            // ⭐ "কোম্পানির মতো করুন" — শাখায় বন্ধ যা কিছু সব চালু
            $wanted = $request->boolean('reset')
                ? array_fill_keys(array_values(array_unique(array_merge(...array_values($off)))), true)
                : $this->board->wanted($data['scope'] ?? [], $data['modules'] ?? [], $data['was'] ?? [], $data['all_on'] ?? []);
            $wall = $this->board->companyOff([(int) $company->id])[(int) $company->id];

            return [$names, $this->board->plan($names, $off, $wanted, $request->boolean('reset') ? [] : $wall)];
        });
    }

    /** নামানো ফাইলে শাখা × মডিউল, প্রতিটার অবস্থা */
    private function offerTheMatrix($rows, array $off, array $wall, Company $company): void
    {
        if (! $this->export->wanted()) {
            return;
        }

        $columns = [['key' => 'name', 'label' => __('core.company.branch'), 'render' => null]];
        foreach ($this->board->groups() as $group) {
            foreach ($group['codes'] as $code) {
                $columns[] = ['key' => $code, 'label' => $this->board->label($code), 'render' => null];
            }
        }

        $this->export->describe(['title' => __('system_admin::module_board.branches'), 'facts' => [$company->name()]]);
        $this->export->capture($columns, $rows, fn (Branch $b, array $col) => $col['key'] === 'name' ? $b->name() : __(match (true) {
            $this->board->locked($col['key']) => 'system_admin::module_board.on',
            in_array($col['key'], $wall, true) => 'system_admin::branch_module.company_off',
            in_array($col['key'], $off[(int) $b->id] ?? [], true) => 'system_admin::module_board.off',
            default => 'system_admin::module_board.on',
        }));
    }
}
