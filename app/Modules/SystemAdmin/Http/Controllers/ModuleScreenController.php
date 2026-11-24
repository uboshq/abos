<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\ListFilter\Facets;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Services\MenuSwitches;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\PerPage;
use App\Http\Controllers\Controller;
use App\Modules\SystemAdmin\Support\ModuleBoard;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ মডিউলের পর্দা আর অ্যাপের মডিউল — কন্ট্রোল প্যানেলের সেই সুইচগুলোই, নিজের পাতায় (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4)।
 *
 * ⓘ পড়া আর লেখা দুটোই আগের: চাবি [[MenuSwitches]] আর [[PhoneModules::key()]], সংরক্ষণ `system_admin.control-panel.update` — তাই `holds`
 *   পাহারা, সুপার-অ্যাডমিনের ঘর আর অডিট এক জায়গাতেই থাকে।
 */
class ModuleScreenController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly MenuSwitches $switches,
        private readonly SettingsService $settings,
        private readonly ModuleBoard $board,
        private readonly ListExport $export,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function screens(Request $request): View
    {
        $definitions = $this->settings->definitions();
        $modules = collect($this->switches->tree())->map(function (array $m) use ($definitions): array {
            $items = collect($m['groups'])->flatMap(fn (array $g) => $g['items']);

            return [...$m, 'count' => $items->count(), 'off' => $items->filter(fn (array $i) => ! $this->settings->get($i['key'], true))->count()];
        });

        $q = is_string($request->query('q')) ? mb_strtolower(trim($request->query('q'))) : '';
        $shown = $modules->filter(fn (array $m) => ($q === '' || str_contains(mb_strtolower($m['label'].' '.$m['code']), $q)
            || collect($m['groups'])->flatMap(fn ($g) => $g['items'])->contains(fn ($i) => str_contains(mb_strtolower(__($i['label'])), $q)))
            && ($request->query('state') !== 'diff' || $m['off'] > 0) && ($request->query('state') !== 'same' || $m['off'] === 0));
        $shown = match ($request->query('sort')) {
            'count' => $shown->sortByDesc('count'),
            'off' => $shown->sortByDesc('off'),
            default => $shown->sortBy('label'),
        };
        $shown = $shown->values();
        $rows = $this->paged($shown, $request);
        $open = $modules->firstWhere('code', $request->query('module')) ?? $shown->first();

        if ($this->export->wanted()) {
            $flat = $shown->flatMap(fn (array $m) => collect($m['groups'])->flatMap(fn (array $g) => array_map(fn (array $i) => [$m['label'], __($i['label']), $i['key']], $g['items'])))->all();
            $this->export->describe(['title' => __('system_admin::module_board.screens')]);
            $this->export->capture([
                ['key' => 'module', 'label' => __('system_admin::module_board.module'), 'render' => null],
                ['key' => 'screen', 'label' => __('system_admin::module_board.why_screen'), 'render' => null],
                ['key' => 'state', 'label' => __('core.table.status'), 'render' => null],
            ], $flat, fn (array $r, array $c) => match ($c['key']) {
                'module' => $r[0], 'screen' => $r[1],
                default => __('system_admin::module_board.'.($this->settings->get($r[2], true) ? 'on' : 'off')),
            });
        }

        return view('system_admin::module-board.screens', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'open' => $open,
            'settings' => $this->settings,
            // ⓘ ঘোষিত সুইচ (নিজের নিয়ম আছে) এখানে কেবল দেখা — বদল কন্ট্রোল প্যানেলে
            'mine' => fn (string $key): bool => (bool) ($definitions[$key]['menu'] ?? false),
            'facets' => Facets::panel([
                Facets::pick('state', __('core.table.status'), ['diff' => __('system_admin::module_board.off'), 'same' => __('system_admin::module_board.all_on')]),
            ]),
        ]);
    }

    public function app(Request $request): View
    {
        $q = is_string($request->query('q')) ? mb_strtolower(trim($request->query('q'))) : '';
        $groupOf = [];
        foreach ($this->board->groups() as $g) {
            foreach ($g['codes'] as $code) {
                $groupOf[$code] = $g['label'];
            }
        }

        $all = collect(app(ModuleRegistry::class)->all())->map(fn ($m) => [
            'code' => $m->code, 'label' => $m->label(), 'group' => $groupOf[$m->code] ?? '',
            'key' => PhoneModules::key($m->code), 'on' => (bool) $this->settings->get(PhoneModules::key($m->code), false),
        ])->filter(fn (array $m) => ($q === '' || str_contains(mb_strtolower($m['label'].' '.$m['code']), $q))
            && ($request->query('state') !== 'on' || $m['on']) && ($request->query('state') !== 'off' || ! $m['on']));
        $all = ($request->query('sort') === 'on' ? $all->sortByDesc('on') : $all->sortBy('label'))->values();

        return view('system_admin::module-board.app', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $this->paged($all, $request),
            'facets' => Facets::panel([
                Facets::pick('state', __('core.table.status'), ['on' => __('system_admin::module_board.on'), 'off' => __('system_admin::module_board.off')]),
            ]),
        ]);
    }

    private function paged($items, Request $request): LengthAwarePaginator
    {
        $per = PerPage::of();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return (new LengthAwarePaginator($items->forPage($page, $per)->values(), $items->count(), $per, $page, ['path' => $request->url()]))->withQueryString();
    }
}
