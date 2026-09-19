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
                'create', 'store', 'edit', 'update', 'destroy', 'activate', 'installBangladesh',
            ]),
            // মোছা আলাদা চাবিতে — মাস্টার তালিকার মতোই (MasterListController::purge)
            new Middleware('can:master_data.delete', only: ['purge']),
        ];
    }

    /**
     * ⛔ পাতা ভাগ নেই, ইচ্ছাকৃত — গাছ, আর সীমাটা আগে থেকেই আছে।
     *
     * সারিগুলো বাবা-সন্তানের ক্রমে বসে, তাই পঞ্চাশে কাটলে কাটটা পড়ত
     * একটা শাখার মাঝখানে: থানা দেখা যেত, অথচ তার জেলা আগের পাতায়।
     *
     * ⓘ বড় গাছের উত্তরটা এই পর্দায় আগে থেকেই লেখা, আর সেটা পাতা ভাগ
     * নয়: `TREE_LIMIT`-এর বেশি হলে গাছটা আঁকাই হয় না, বদলে খোঁজার ঘর
     * (`tooManyToShow`), আর খোঁজার ফল একশোতে বাঁধা। অর্থাৎ সীমাহীন
     * কোয়েরিটা এখানে কখনো চলেনি।
     *
     * কারণটা `EveryListScreenPaginatesTest`-এর ছাড়ের তালিকাতেও আছে।
     */
    public function index(Request $request): View
    {
        $q = $request->query('q');
        $showInactive = $request->boolean('inactive');
        $ladder = Location::activeLadder();

        /*
         * ⭐ স্তর ধরে আলাদা তালিকা — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬।
         *
         * *"Country Create, Divi Create… ei sob alada alada create hobe,
         * alada list hobe, Tree hobe"*।
         *
         * ⓘ গাছটা আগেও ছিল, কিন্তু সব স্তর এক জায়গায় মেশানো, আর তৈরির
         * বোতাম একটাই — কোন স্তর বানাচ্ছেন সেটা ফর্মে গিয়ে বাছতে হত।
         * ⚠️ তাই "কোথা থেকে শুরু করব" প্রশ্নের উত্তর পর্দায় ছিল না।
         *
         * ⭐ এখন `?level=` দিলে কেবল ঐ স্তরের তালিকা, নিজের "নতুন" বোতামসহ;
         * না দিলে আগের গাছ। ⚠️ অচেনা স্তর চুপচাপ মেনে নেওয়া হয় না —
         * মই-এর বাইরের কিছু এলে গাছেই ফেরা।
         */
        /* ⓘ পথ থেকে (`/locations/level/point`) — রুটের মন্তব্যে কারণ লেখা।
           ⚠️ মই-এর ভেতরে থেকেও **বন্ধ** স্তর (জোন বা এরিয়া বন্ধ থাকলে)
           এলে গাছেই ফেরা, খালি একটা পাতা নয়। */
        $level = $request->route('level');
        $level = is_string($level) && in_array($level, $ladder, true) ? $level : null;

        /*
         * ট্যাবের সংখ্যাগুলো — এক কোয়েরিতে, স্তর ধরে।
         *
         * ⓘ সংখ্যাটাই বলে দেয় কোথায় শুরু করতে হবে: "এরিয়া ০" দেখলে
         * বোঝা যায় পয়েন্ট বানানোর আগে কী লাগবে, আর সেটা বলতে কোনো
         * বার্তা লিখতে হয় না।
         */
        /*
         * ⓘ ট্যাবের সংখ্যায় নিষ্ক্রিয়গুলোও — ২০ সেপ্টেম্বর ২০২৬। স্তরের তালিকা
         * এখন নিষ্ক্রিয় সারিও দেখায় (অবস্থার কলামে), তাই সংখ্যা আর সারি মেলে।
         */
        $counts = Location::query()
            ->selectRaw('level, count(*) as n')
            ->groupBy('level')
            ->pluck('n', 'level');

        if ($level !== null) {
            return $this->levelList($request, $level, $ladder, $counts, $showInactive);
        }

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
            'ladder' => $ladder,
            'counts' => $counts,
            'level' => null,
        ]);
    }

    /**
     * একটা স্তরের সমতল তালিকা — "সব পয়েন্ট", "সব রুট"।
     *
     * ── ⓘ কেন গাছের পাশাপাশি এটাও ─────────────────────────────────────
     * গাছ বলে **কোনটা কার নিচে**; তালিকা বলে **এই স্তরে কী কী আছে**।
     * ⚠️ দুইশো পয়েন্ট গাছে ছড়িয়ে থাকলে "আমাদের কয়টা পয়েন্ট, কার
     * দায়িত্বে" প্রশ্নের উত্তর পেতে গোটা গাছ খুলতে হত।
     *
     * ── ⭐ উপরের স্তর খালি থাকলে ─────────────────────────────────────
     * `parentsMissing` — পয়েন্ট বানাতে এরিয়া লাগে, আর এরিয়া একটাও না
     * থাকলে পর্দা **আগেই** বলে দেয় কোথা থেকে শুরু করতে হবে। ⛔ আগে এই
     * অবস্থায় ফর্মে একটা খালি ড্রপডাউন বসত, অথচ সেটা required — মানুষ
     * আটকে যেতেন আর কারণ বুঝতেন না।
     *
     * @param  list<string>  $ladder
     * @param  \Illuminate\Support\Collection<string, int>  $counts
     */
    private function levelList(Request $request, string $level, array $ladder, $counts, bool $showInactive): View
    {
        $q = $request->query('q');

        $rows = Location::query()
            ->atLevel($level)

            /*
             * ⛔ নিষ্ক্রিয় সারিও থাকে — ২০ সেপ্টেম্বর ২০২৬, মালিক: *"Deactive
             * korlei List theke Hariye zacche keno?"*। ⓘ অবস্থা দেখায় নতুন
             * কলাম; সক্রিয়গুলো আগে, তারপর নিষ্ক্রিয়।
             */
            ->when(filled($q), fn ($b) => $b->search($q))

            /*
             * ⚠️ `path()`-এর শিকলটা আগে থেকে তোলা — [[parentOptions()]]-এর
             * মন্তব্য দেখুন। ⛔ না তুললে এই পাতাও ঠিক পয়েন্ট আর রুটেই
             * ৫০০ দিত, যেখানে তালিকাটা সবচেয়ে দরকারি।
             */
            ->with([...Location::drillRelations(), 'assignee'])

            ->orderByDesc('is_active')
            ->orderBy('code')
            // পেজিনেশন বাধ্যতামূলক (সেকশন ৯) — রুট কয়েকশো হতে পারে
            ->paginate(50)
            ->withQueryString();

        $parentLevel = Location::parentLevelOf($level);

        return view('master_data::location.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tree' => new Collection,
            'results' => new Collection,
            'q' => $q,
            'showInactive' => $showInactive,
            'total' => (int) $counts->sum(),
            'tooManyToShow' => false,
            'ladder' => $ladder,
            'counts' => $counts,
            'level' => $level,
            'rows' => $rows,
            'parentLevel' => $parentLevel,
            /*
             * ⚠️ `$counts` থেকে নয়, আলাদা করে — "নিষ্ক্রিয়ও দেখাও" চালু
             * থাকলে গোনায় নিষ্ক্রিয় বাবারাও পড়ে, অথচ ফর্মের তালিকায় কেবল
             * সক্রিয়রা আসে ([[parentOptions()]])। ⛔ তখন পর্দা বলত "আছে"
             * আর ফর্মের ড্রপডাউন খালি — ঠিক যে অন্ধ গলিটা এটা বন্ধ করতে এল।
             */
            'parentsMissing' => $parentLevel !== null
                && Location::query()->atLevel($parentLevel)->active()->doesntExist(),
        ]);
    }

    public function create(Request $request): View
    {
        $level = $this->levelToOpen($request);

        return view('master_data::location.form', [
            'menu' => $this->menu->forUser($request->user()),

            /*
             * ⭐ যা টাইপ করা ছিল তা ফিরিয়ে দেওয়া — ১৮ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ স্তর বদলালে পাতাটা নতুন করে খোলে (উপরের স্তর জানা না
             * থাকলে বাবার তালিকা বানানো যায় না)। ⛔ আগে ঐ মুহূর্তে
             * লেখা নামটা হারিয়ে যেত, তাই মানুষ আগে স্তর বাছতে শিখতেন —
             * আর ভুলে গেলে দুইবার টাইপ করতেন।
             *
             * ⚠️ `old()` এখানে কাজে আসে না: স্তর বদলানোটা একটা সাধারণ
             * GET, কোনো ব্যর্থ জমা নয়। তাই ঘরগুলো ঠিকানার সাথেই আসে।
             */
            'location' => new Location([
                'is_active' => true,
                'level' => $level,
                'code' => (string) $request->query('code', ''),
                'name_en' => (string) $request->query('name_en', ''),
                'name_bn' => (string) $request->query('name_bn', ''),
                'assigned_to' => $request->integer('assigned_to') ?: null,
            ]),

            'ladder' => Location::activeLadder(),
            'parents' => $this->parentOptions($level),
            'people' => $this->people(),
            'preselectedParent' => $request->integer('parent') ?: null,
        ]);
    }

    /**
     * কোন স্তরের ফর্মটা খুলবে।
     *
     * ── ⛔ যে বাগটা এটা ঠিক করে, ১৮ সেপ্টেম্বর ২০২৬ ──────────────────
     * মালিক একটা **পয়েন্ট** বানাতে গিয়ে বাবাটা না বেছে জমা দিয়েছেন।
     * [[LocationService::resolveParent()]] ঠিকই বলেছে *"এরিয়াটা
     * বাছুন"* — কিন্তু ফিরে আসা পাতায় স্তরটা আবার **দেশ** হয়ে গেছে,
     * কারণ `back()` ঠিকানায় `?level=point` ছিল না।
     *
     * ⚠️ আর দেশের কোনো বাবা নেই, তাই ফর্ম বাবার ঘরটাই দেখায়নি —
     * পর্দা এরিয়া চাইছে, অথচ এরিয়া বাছার কোনো ঘর নেই। ⛔ ঐ
     * অবস্থা থেকে ব্যবহারকারীর বেরোনোর পথ ছিল না।
     *
     * ⭐ তাই আগে `old('level')` — ব্যর্থ জমা থেকে ফেরা মানুষ ঠিক যে
     * স্তরে ছিলেন, সেখানেই ফেরেন, আর বাবার ঘরটা সাথেই থাকে।
     *
     * ⚠️ অচেনা বা বন্ধ স্তর চুপচাপ মেনে নেওয়া হয় না: `?level=zilla`
     * লিখলে [[Location::parentLevelOf()]] `null` ফেরাত, আর তখন ঠিক
     * একই অন্ধ গলি তৈরি হত। ⓘ তাই মই-এর বাইরের কিছু এলে দেশ।
     */
    private function levelToOpen(Request $request): string
    {
        $ladder = Location::activeLadder();

        foreach ([$request->old('level'), $request->query('level')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $ladder, true)) {
                return $candidate;
            }
        }

        return Location::COUNTRY;
    }

    /**
     * ⭐ সংরক্ষণের পর সেই স্তরের ট্যাবে — ১৯ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ আগে গাছে ফিরত। ⚠️ "নতুন পয়েন্ট" চেপে এসে পরপর পাঁচটা পয়েন্ট
     * বসাতে চাইলে প্রতিবার আবার পয়েন্টের ট্যাব খুঁজতে হত, আর নতুনটা
     * গাছের কোথায় বসল তা খুঁজে দেখতে হত।
     */
    public function store(Request $request): RedirectResponse
    {
        $location = $this->locations->create($this->validated($request));

        return redirect()
            ->route('master_data.location.level', ['level' => $location->level])
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

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * ⓘ `back()`: স্তরের ট্যাবের Action থেকে এলে ঐ ট্যাবেই ফেরা, গাছে নয়।
     */
    public function destroy(Location $location): RedirectResponse
    {
        $this->locations->deactivate($location);

        return back()->with('saved', __('master_data::message.deactivated'));
    }

    /** আবার সক্রিয় — নিষ্ক্রিয় করা একমুখী দরজা হতে পারে না। */
    public function activate(Location $location): RedirectResponse
    {
        $this->locations->activate($location);

        return back()->with('saved', __('master_data::message.activated'));
    }

    /**
     * সত্যিই মুছে ফেলা — কেবল যেটার নিচে কিছু নেই আর কোথাও ব্যবহার হয়নি।
     *
     * ⓘ না পারলে [[LocationService::purge()]] কারণসহ থামায় — "নিচে ৩টা
     * পয়েন্ট আছে" — আর সেই বার্তা ট্যাবের উপরেই দেখা যায়।
     */
    public function purge(Location $location): RedirectResponse
    {
        $this->locations->purge($location);

        return back()->with('saved', __('master_data::message.deleted'));
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

            /*
             * ⛔ ৫০০ এরর — lazy loading বন্ধ, ১৮ সেপ্টেম্বর ২০২৬।
             *
             * ফর্ম প্রতিটা বিকল্পে [[Location::path()]] দেখায় ("ময়মনসিংহ
             * › ত্রিশাল"), আর `path()` [[Location::ancestors()]] ধরে
             * **উপরের দিকে হেঁটে যায়**। ⚠️ শিকলটা আগে থেকে তোলা না
             * থাকায় প্রথম ধাপেই ব্যতিক্রম:
             *
             *     Attempted to lazy load [parent] on model [Location]
             *
             * ⓘ ফলটা ঠিক যেভাবে লুকিয়ে ছিল সেটাই শেখার: বাবার তালিকা
             * **খালি থাকলে** লুপটা চলত না, তাই দেশ বা বিভাগের ফর্ম
             * দিব্যি খুলত — আর পয়েন্ট বা রুটের ফর্ম, যেখানে সত্যিই
             * বিকল্প আছে, ৫০০ দিত। ⛔ অর্থাৎ যে পর্দাটা কাজ করার কথা
             * ঠিক সেটাই ভাঙা ছিল।
             *
             * ⭐ শিকলটার সংজ্ঞা একটাই জায়গায় — [[Location::drillRelations()]],
             * যেখানে *কেন সাত স্তর* তার হিসাবও লেখা আছে। ⚠️ এখানে হাতে
             * `['parent']` লিখলে দ্বিতীয় স্তরেই আবার একই ব্যতিক্রম।
             */
            ->with(Location::drillRelations())

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
