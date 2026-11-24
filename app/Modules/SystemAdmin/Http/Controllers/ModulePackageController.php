<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\ListFilter\Facets;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\PerPage;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchModulePackage;
use App\Models\ModulePackage;
use App\Modules\SystemAdmin\Support\ModuleBoard;
use App\Modules\SystemAdmin\Support\ModulePackages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** ⭐ মডিউল প্যাকেজ — চলতি কোম্পানির ([[ModulePackages]]; মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4) */
class ModulePackageController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ModuleBoard $board,
        private readonly ModulePackages $packages,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function index(Request $request): View
    {
        $q = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $rows = ModulePackage::query()
            ->withCount(['branches', 'branches as custom_count' => fn ($b) => $b->where('is_custom', true)])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name_en', 'like', $like)->orWhere('name_bn', 'like', $like)))
            ->when($request->query('state') === 'used', fn ($query) => $query->has('branches'))
            ->when($request->query('state') === 'unused', fn ($query) => $query->doesntHave('branches'))
            ->when($request->query('sort') === 'branches', fn ($query) => $query->orderByDesc('branches_count'))
            ->orderBy('name_en')->orderBy('id')
            ->paginate(PerPage::of())->withQueryString();

        return view('system_admin::module-board.packages', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'board' => $this->board,
            'facets' => Facets::panel([
                Facets::pick('state', __('core.table.status'), ['used' => __('system_admin::module_board.used'), 'unused' => __('system_admin::module_board.unused')]),
            ]),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new ModulePackage(['modules' => []]));
    }

    public function edit(Request $request, ModulePackage $package): View
    {
        return $this->form($request, $package);
    }

    public function store(Request $request): RedirectResponse
    {
        $package = ModulePackage::query()->create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return redirect()->route('system_admin.module.packages.edit', $package)->with('saved', __('system_admin::module_board.package_saved', ['count' => 0]));
    }

    public function update(Request $request, ModulePackage $package): RedirectResponse
    {
        $data = $this->validated($request, $package);
        $count = DB::transaction(function () use ($request, $package, $data): int {
            $package->update($data);

            return $this->packages->spread($package, (int) $request->user()->id);
        });

        return redirect()->route('system_admin.module.packages.edit', $package)->with('saved', __('system_admin::module_board.package_saved', ['count' => $count]));
    }

    public function assign(Request $request, ModulePackage $package): View|RedirectResponse
    {
        $data = $request->validate([
            'branches' => ['required_without:types', 'array'], 'branches.*' => ['integer'],
            'types' => ['array'], 'types.*' => ['string', Rule::in(Branch::TYPES)],
        ]);
        // ⭐ ধরন ধরে — সেই ধরনের সব সচল শাখা, হাতে বাছাগুলোর সাথে; তালিকায় সবার নাম দেখিয়ে তবেই লেখা
        $types = Branch::typesFrom($data['types'] ?? []);
        $ofType = $types === [] ? [] : $this->board->branches('', '', $types)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $asked = array_values(array_unique([...array_map('intval', $data['branches'] ?? []), ...$ofType]));
        if ($asked === []) {
            return redirect()->route('system_admin.module.packages.edit', $package)->with('saved', __('system_admin::module_board.nothing'));
        }
        $names = $this->board->branches()->whereIn('id', $asked)->get()->mapWithKeys(fn (Branch $b) => [(int) $b->id => $b->name()])->all();
        $mine = array_keys($names);
        // ⛔ অন্য কোম্পানির বা বন্ধ শাখা মিশলে কিছুই নয়
        abort_if(count($mine) !== count($asked), 404);

        // ⭐ আগে যা বদলাবে তার তালিকা, নিশ্চিত করলে তবেই লেখা; দেখার পরে কেউ বদলালে তালিকা আবার
        $plan = $this->packages->plan($package, $names);
        $stamp = $this->board->stamp($plan['changes']);
        if ($request->input('stamp') !== $stamp) {
            return view('system_admin::module-board.preview', [
                'menu' => $this->menu->forUser($request->user()),
                'kind' => 'package', 'plan' => $plan, 'board' => $this->board, 'stamp' => $stamp,
                'apply' => route('system_admin.module.packages.assign', $package),
                'back' => route('system_admin.module.packages.edit', $package),
                'payload' => ['branches' => $mine],
                'stale' => $request->filled('stamp'),
            ]);
        }

        $count = $this->packages->assign($package, $mine, (int) $request->user()->id);

        return redirect()->route('system_admin.module.packages.edit', $package)->with('saved', __('system_admin::module_board.package_saved', ['count' => $count]));
    }

    private function form(Request $request, ModulePackage $package): View
    {
        $q = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $branches = $package->exists ? $this->board->branches($q)->paginate(PerPage::of())->withQueryString() : null;

        return view('system_admin::module-board.package-form', [
            'menu' => $this->menu->forUser($request->user()),
            'package' => $package,
            'groups' => $this->board->groups(),
            'board' => $this->board,
            'branches' => $branches,
            'given' => $branches === null ? [] : $this->packages->namesFor(collect($branches->items())->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'followers' => $package->exists ? count($this->packages->followers($package)) : 0,
        ]);
    }

    private function validated(Request $request, ?ModulePackage $package = null): array
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120', Rule::unique('module_packages', 'name_en')->where('company_id', CompanyContext::id())->ignore($package?->id)],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'modules' => ['array'],
            'modules.*' => ['string', Rule::in(array_keys(app(ModuleRegistry::class)->all()))],
        ]);

        return [...$data, 'modules' => array_values($data['modules'] ?? [])];
    }
}
