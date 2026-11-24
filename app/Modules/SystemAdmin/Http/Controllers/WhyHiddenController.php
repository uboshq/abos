<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\SystemAdmin\Support\ModuleBoard;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/** ⭐ একজন মানুষ একটা পর্দা কেন দেখেন না — চার ধাপ, যেটা আটকায় সেটা আর সেখানে যাওয়ার লিংক; কেবল পড়ে (মালিক, ২৪ নভেম্বর ২০২৬) */
class WhyHiddenController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly MenuSwitches $switches,
        private readonly SettingsService $settings,
        private readonly ModuleBoard $board,
        private readonly ModuleRegistry $registry,
    ) {}

    public static function middleware(): array
    {
        // ⓘ দুটো চাবিই — পর্দার সুইচ আর কার কী অনুমতি, দুটোই বলে
        return [new Middleware('can:system_admin.settings.manage'), new Middleware('can:system_admin.user.manage')];
    }

    public function __invoke(Request $request): View
    {
        $companyId = (int) CompanyContext::id();
        $people = User::query()->whereHas('companies', fn ($q) => $q->whereKey($companyId))->orderBy('name')->get(['id', 'name']);
        $branches = Branch::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name_en')->get();

        $screens = [];
        $items = [];
        foreach ($this->registry->all() as $module) {
            foreach ($module->menu as $group => $rows) {
                foreach ($rows as $item) {
                    if ($item['planned'] ?? false) {
                        continue;
                    }
                    // ⓘ একই সুইচের দুই সারি হতে পারে, তাই চাবিতে মডিউলও
                    $key = $module->code.'|'.$this->switches->forItem($item);
                    $screens[$key] = $module->label().' › '.__($item['label']);
                    $items[$key] = [$module, (string) $group, $item];
                }
            }
        }

        $person = $people->firstWhere('id', (int) $request->query('person'));
        $branch = $branches->firstWhere('id', (int) $request->query('branch'));
        $screen = is_string($request->query('screen')) ? $request->query('screen') : '';
        $steps = $person !== null && $branch !== null && isset($items[$screen])
            ? $this->steps(User::query()->findOrFail($person->id), $branch, $companyId, ...$items[$screen]) : [];

        return view('system_admin::module-board.why', [
            'menu' => $this->menu->forUser($request->user()),
            'people' => $people, 'branches' => $branches, 'screens' => $screens,
            'person' => $person?->id, 'branch' => $branch?->id, 'screen' => $screen, 'steps' => $steps,
        ]);
    }

    /** @return list<array{label: string, ok: bool, url: string}> */
    private function steps(User $person, Branch $branch, int $companyId, ModuleDefinition $module, string $group, array $item): array
    {
        $name = $module->label();
        $companyOn = $module->essential || (bool) $this->settings->get($this->switches->forModule($module->code), true);
        $branchOn = $module->essential || ! in_array($module->code, $this->board->branchOff([(int) $branch->id])[(int) $branch->id], true);
        $key = $item['permission'] ?? null;

        return [
            ['label' => __('system_admin::module_board.why_member'), 'ok' => (bool) $person->is_active && $person->canAccessCompany($companyId), 'url' => route('system_admin.user.index')],
            ['label' => __('system_admin::module_board.why_company', ['module' => $name]), 'ok' => $companyOn, 'url' => route('system_admin.module.companies', ['pick' => [$companyId]])],
            ['label' => __('system_admin::module_board.why_branch', ['module' => $name]), 'ok' => $branchOn, 'url' => route('system_admin.module.branches', ['pick' => [$branch->id]])],
            ['label' => __('system_admin::module_board.why_switch'), 'ok' => $this->switches->itemIsOn($this->settings, $module, $group, $item) || (! $companyOn && (bool) $this->settings->get($this->switches->forItem($item), true)),
                'url' => route('system_admin.module.screens', ['module' => $module->code])],
            ['label' => __('system_admin::module_board.why_key'), 'ok' => $key === null || collect((array) $key)->contains(fn ($one) => $person->canInCompany($companyId, (string) $one)), 'url' => route('system_admin.role.index')],
        ];
    }
}
