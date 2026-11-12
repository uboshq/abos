<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Services\DealerScope;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\StaffPosition;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Customer\Services\FieldForceService;
use App\Modules\MasterData\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ ফিল্ড ফোর্সের গাছ — বাঁয়ে গাছ, ডানে বাছা মানুষের পদ, উপরওয়ালা, ডিপো, চলমান এলাকা; উপরে চালুর আগের তালিকা
 *   (মালিক, ১২ নভেম্বর ২০২৬; নমুনা "ফিল্ড ফোর্সের গাছ")।
 *
 * ⓘ বাঁধনের পাতার একই চাবি `customer.binding.manage` ([[DealerBindingController]])। ⛔ দেয়ালের সুইচ কেবল সুপার
 *   অ্যাডমিন ([[SettingsService::mayChange()]]) — এক চাপে সবার দেয়াল ওঠে-নামে।
 */
final class FieldForceController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly FieldForceService $field,
        private readonly DealerBindingService $bindings,
        private readonly DealerScope $scope,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:customer.binding.manage')];
    }

    public function index(Request $request): View
    {
        $people = $this->field->people();
        $children = [];

        foreach ($people as $id => $p) {
            if ($p['supervisor'] !== null && $people->has($p['supervisor'])) {
                $children[$p['supervisor']][] = $id;
            }
        }

        // ⓘ গাছের মাথা — উপরে কেউ নেই (বা উপরওয়ালা গাছের বাইরে); পদ উঁচু আগে
        $rank = array_flip(StaffPosition::POSITIONS);
        $roots = $people->filter(fn (array $p) => $p['supervisor'] === null || ! $people->has($p['supervisor']))
            ->sortBy([fn ($a, $b) => ($rank[$b['position']] ?? -1) <=> ($rank[$a['position']] ?? -1), fn ($a, $b) => strcmp($a['user']->name, $b['user']->name)])
            ->keys()->all();

        $staff = $this->bindings->staff();
        $pickedId = $request->integer('person') ?: null;
        $picked = $pickedId !== null ? $staff->firstWhere('id', $pickedId)?->loadMissing('roles') : null;
        $row = $picked !== null ? $people->get($picked->id) : null;
        $below = $picked !== null ? count($this->scope->reachOf($picked)) - 1 : 0;

        return view('customer::fieldforce.index', [
            'menu' => $this->menu->forUser($request->user()),
            'people' => $people,
            'children' => $children,
            'roots' => $roots,
            'loose' => count(array_filter($roots, fn ($id) => ($people[$id]['position'] ?? null) !== 'DSM' && empty($children[$id]))),
            'checklist' => $this->field->checklist($people),
            'switchOn' => $this->scope->switchOn(),
            'mayFlip' => app(SettingsService::class)->mayChange(DealerScope::SWITCH, $request->user()),
            'staff' => $staff,
            'outside' => $staff->reject(fn (User $u) => $people->has($u->id))->values(),
            'picked' => $picked,
            'row' => $row,
            'own' => $picked !== null ? $this->field->ownDealers($picked) : 0,
            'below' => $below,
            'under' => $request->has('under') ? $request->integer('under') : null,
            'depots' => Branch::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn']),
            'areas' => $this->areasInTreeOrder(),
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    /**
     * এলাকা গাছের ক্রমে — বাবার ঠিক নিচে ছেলেরা, `depth` ধাপ ধরে ভিতরে।
     *
     * @return list<Location>
     */
    private function areasInTreeOrder(): array
    {
        $all = Location::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_bn', 'level', 'parent_id']);
        $ids = $all->pluck('id')->flip();
        $kids = $all->groupBy(fn (Location $l) => $l->parent_id !== null && $ids->has($l->parent_id) ? (int) $l->parent_id : 0);
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $kids): void {
            foreach ($kids->get($parent, []) as $l) {
                $l->setAttribute('depth', $depth);
                $out[] = $l;
                $walk((int) $l->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    public function save(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'position' => ['nullable', Rule::in(StaffPosition::POSITIONS)],
            'supervisor_id' => ['nullable', 'integer'],
            'depots' => ['nullable', 'array'],
            'depots.*' => ['integer'],
            'areas' => ['nullable', 'array'],
            'areas.*' => ['integer'],
            'starts_on' => ['required', 'date'],
        ]);

        $this->field->save((int) $user->id, [
            'position' => $data['position'] ?? null,
            'supervisor_id' => $data['supervisor_id'] ?? null,
            'depots' => array_map('intval', $data['depots'] ?? []),
            'areas' => array_map('intval', $data['areas'] ?? []),
            'starts_on' => (string) $data['starts_on'],
        ]);

        return redirect()->route('customer.fieldforce.index', ['person' => $user->id])
            ->with('saved', __('customer::fieldforce.saved', ['name' => $user->name]));
    }

    public function wall(Request $request): RedirectResponse
    {
        abort_unless(app(SettingsService::class)->mayChange(DealerScope::SWITCH, $request->user()), 403);

        $on = $request->boolean('on');
        $this->field->setWall($on);

        return back()->with('saved', __($on ? 'customer::fieldforce.wall_saved_on' : 'customer::fieldforce.wall_saved_off'));
    }
}
