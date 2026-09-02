<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Services\LocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * এলাকার গাছ।
 *
 * হিসাবের ছকের মতোই গাছ হিসেবে দেখানো হয়, আর একই কারণে: "রুট-৩" একা
 * কিছু বলে না, "ময়মনসিংহ › ত্রিশাল › রুট-৩" বলে।
 */
class LocationController extends Controller implements HasMiddleware
{
    /** পুরো গাছ একবারে দেখানোর সীমা — এর বেশি হলে খোঁজায় বদলায়। */
    private const TREE_LIMIT = 600;

    public function __construct(
        private readonly LocationService $locations,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:master_data.view', only: ['index', 'show']),
            new Middleware('can:master_data.manage', only: [
                'create', 'store', 'edit', 'update', 'destroy', 'installBangladesh',
            ]),
        ];
    }

    public function index(Request $request): View
    {
        $q = $request->query('q');
        $showInactive = $request->boolean('inactive');

        $query = Location::query()
            ->when(! $showInactive, fn ($b) => $b->active())
            /*
             * দায়িত্বে কে — সারির সাথেই।
             *
             * গাছটা কয়েকশো সারি পর্যন্ত এক পাতায় আঁকা হয়, আর প্রতিটা
             * সারিতে নামটা দেখানো হয়। আলাদা করে আনলে ওখানেই কয়েকশো
             * বাড়তি কোয়েরি — আর পাতাটা ধীরে খোলা ছাড়া কোনো লক্ষণ থাকত না।
             */
            ->with('assignee')
            ->orderBy('code');

        $total = (clone $query)->count();
        $searching = filled($q);

        $all = $searching
            ? $query->search($q)->limit(100)->get()
            : ($total <= self::TREE_LIMIT ? $query->get() : new Collection);

        return view('master_data::location.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tree' => $searching ? new Collection : $this->tree($all),
            'results' => $searching ? $all : new Collection,
            'q' => $q,
            'showInactive' => $showInactive,
            'total' => $total,
            'tooManyToShow' => ! $searching && $total > self::TREE_LIMIT,
            'ladder' => Location::activeLadder(),
        ]);
    }

    public function create(Request $request): View
    {
        $level = (string) $request->query('level', Location::COUNTRY);

        return view('master_data::location.form', [
            'menu' => $this->menu->forUser($request->user()),
            'location' => new Location(['is_active' => true, 'level' => $level]),
            'ladder' => Location::activeLadder(),
            'parents' => $this->parentOptions($level),
            'people' => $this->people(),
            'preselectedParent' => $request->integer('parent') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->locations->create($this->validated($request));

        return redirect()
            ->route('master_data.location.index')
            ->with('saved', __('master_data::message.created'));
    }

    public function show(Request $request, Location $location): View
    {
        return view('master_data::location.show', [
            'menu' => $this->menu->forUser($request->user()),
            /*
             * সন্তানদের দায়িত্বপ্রাপ্তও একসাথে — পর্দাটা প্রতিটা সন্তানের
             * পাশে নামটা দেখায়, তাই আলাদা করে আনলে সন্তান যত, কোয়েরিও তত।
             */
            'location' => $location->load(['parent', 'assignee', 'children.assignee']),
            'childLevel' => Location::childLevelOf($location->level),
        ]);
    }

    public function edit(Request $request, Location $location): View
    {
        return view('master_data::location.form', [
            'menu' => $this->menu->forUser($request->user()),
            // দায়িত্বপ্রাপ্ত কে — ফর্মে আগে থেকে বাছা থাকে, তাই সাথেই আসুক
            'location' => $location->load('assignee'),
            'ladder' => Location::activeLadder(),
            // নিজে ও নিজের নিচের কেউ বাবা হতে পারে না — তালিকা থেকেই বাদ
            'parents' => $this->parentOptions(
                $location->level,
                exclude: $location->selfAndDescendants()->pluck('id')->all(),
            ),
            'people' => $this->people(),
            'preselectedParent' => $location->parent_id,
        ]);
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $this->locations->update($location, $this->validated($request, editing: true));

        return redirect()
            ->route('master_data.location.show', $location)
            ->with('saved', __('master_data::message.updated'));
    }

    /** নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)। */
    public function destroy(Location $location): RedirectResponse
    {
        $this->locations->deactivate($location);

        return redirect()
            ->route('master_data.location.index')
            ->with('saved', __('master_data::message.deactivated'));
    }

    public function installBangladesh(): RedirectResponse
    {
        $this->locations->installBangladesh();

        return back()->with('saved', __('master_data::message.bangladesh_installed'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $editing = false): array
    {
        return $request->validate([
            // খালি রাখা যায় — [[LocationService::create()]] তখন নাম থেকে বসায়
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'level' => [$editing ? 'nullable' : 'required', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }

    /**
     * যে এলাকাগুলোর নিচে এই স্তরটা বসতে পারে।
     *
     * শুধু ঠিক উপরের চালু স্তরের এলাকাগুলো — অন্য স্তরের দেখালে
     * ব্যবহারকারী বাছার পর ভুলের বার্তা পেত, আর তখন কেন ভুল তা বোঝা
     * কঠিন হত।
     *
     * @param  list<int>  $exclude
     * @return Collection<int, Location>
     */
    private function parentOptions(string $level, array $exclude = []): Collection
    {
        $parentLevel = Location::parentLevelOf($level);

        if ($parentLevel === null) {
            return new Collection;
        }

        return Location::query()
            ->atLevel($parentLevel)
            ->active()
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', $exclude))
            ->orderBy('code')
            ->get();
    }

    /** @return Collection<int, User> */
    private function people(): Collection
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * সমতল তালিকাকে গাছে — একবার ঘুরে, বারবার কোয়েরি না করে।
     *
     * @param  Collection<int, Location>  $all
     * @return Collection<int, Location>
     */
    private function tree(Collection $all): Collection
    {
        $byParent = $all->groupBy(fn (Location $l) => $l->parent_id ?? 0);

        $attach = function (Location $node) use ($byParent, &$attach): Location {
            $node->setRelation(
                'children',
                ($byParent[$node->id] ?? new Collection)->map($attach)->values(),
            );

            return $node;
        };

        return ($byParent[0] ?? new Collection)->map($attach)->values();
    }
}
