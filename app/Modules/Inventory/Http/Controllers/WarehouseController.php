<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Inventory\Models\StorageLocation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * গুদামের স্ক্রিন।
 *
 * তালিকা ও ফর্ম একই পাতায়: গুদাম কয়েকটাই থাকে, আর তিনটা সারির জন্য
 * আলাদা পাতা খোলা মানে অকারণ ক্লিক।
 */
class WarehouseController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use SortsLists;

    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Warehouse::class, 'warehouse'),

            // সক্রিয় করাও নিষ্ক্রিয় করার মতোই ক্ষমতা — পণ্যে একই নিয়ম
            new Middleware('can:delete,warehouse', only: ['activate']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Warehouse::query()
            ->search($request->query('q'))
            ->when(! $request->boolean('inactive'), fn ($q) => $q->active())
            ->with('branch');

        $sort = $this->applySort($query, $request, [
            // প্রধান গুদাম আগে — বেশিরভাগ কাজ ওটাতেই, তাই তালিকার
            // মাথায় থাকাই স্বাভাবিক
            'default_first' => fn ($q) => $q->defaultFirst(),
            'code' => fn ($q) => $q->orderBy('code'),
            'name' => fn ($q) => $q->orderBy('name_en'),
        ]);

        return view('inventory::warehouse.index', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouses' => $query->paginate(50)->withQueryString(),
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'q' => $request->query('q'),
            'sort' => $sort,
            'sortOptions' => [
                'default_first' => __('inventory::sort.default_first'),
                'code' => __('inventory::sort.code'),
                'name' => __('inventory::sort.name'),
            ],
            'showInactive' => $request->boolean('inactive'),
            'editing' => null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory::warehouse.form', [
            'menu' => $this->menu->forUser($request->user()),
            // ⓘ হেডারে বাছা শাখাই আগে থেকে বসানো — যে শাখায় দাঁড়িয়ে বানাচ্ছেন, গুদাম সেই শাখার ([[withBranch()]])
            'warehouse' => new Warehouse(['is_active' => true, 'branch_id' => ViewedBranch::one()]),
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->warehouses->create($this->withBranch($this->validated($request)));

        return redirect()
            ->route('inventory.warehouse.index')
            ->with('saved', __('inventory::message.warehouse_created'));
    }

    public function edit(Request $request, Warehouse $warehouse): View
    {
        return view('inventory::warehouse.form', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouse' => $warehouse,
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
        ]);
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->warehouses->update($warehouse, $this->validated($request, $warehouse->id));

        return redirect()
            ->route('inventory.warehouse.index')
            ->with('saved', __('inventory::message.warehouse_updated'));
    }

    /** মোছা নয়, নিষ্ক্রিয় করা — নিয়ম ৫। */
    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $this->warehouses->deactivate($warehouse);

        return redirect()
            ->route('inventory.warehouse.index')
            ->with('saved', __('inventory::message.warehouse_updated'));
    }

    public function activate(Warehouse $warehouse): RedirectResponse
    {
        $this->warehouses->activate($warehouse);

        return redirect()
            ->route('inventory.warehouse.index')
            ->with('saved', __('inventory::message.warehouse_updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $exceptId = null): array
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            // খালি রাখলে সিরিজ থেকে বসে — WarehouseService::create() দেখুন
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:191'],
            'name_bn' => ['nullable', 'string', 'max:191'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_bn' => ['nullable', 'string', 'max:500'],
            // exists-এ company_id — গ্লোবাল স্কোপ কাঁচা কোয়েরিতে চলে না
            'branch_id' => ['nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $data['is_default'] = $request->boolean('is_default');

        return $data;
    }

    /**
     * ⭐ নতুন গুদামের শাখা — মালিক, ২ অক্টোবর ২০২৬: *"godawn create korar poreo list e godawn asteche na"*।
     *
     * ⛔ কী হচ্ছিল: ফর্মে শাখার ঘর ফাঁকা থাকত ("-"), ফাঁকা রেখে বানালে গুদাম কোনো শাখার নয়; আর হেডারে এক শাখা বাছা
     * থাকলে তালিকা কেবল সেই শাখার গুদাম দেখায় ([[ScopedToUserWarehouse]], প্রতিটা শাখা আলাদা) — নতুন গুদাম উধাও।
     * ⭐ এখন: ফাঁকা এলে হেডারের শাখা; "সব শাখা"-য় কোম্পানির একটাই শাখা থাকলে সেটা; একাধিক হলে জিজ্ঞেস — অনুমান নয়।
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withBranch(array $data): array
    {
        if (! empty($data['branch_id'])) {
            return $data;
        }

        $branches = Branch::query()->active()->pluck('id');
        $branch = ViewedBranch::one() ?? ($branches->count() === 1 ? (int) $branches->first() : null);

        if ($branch === null && $branches->count() > 1) {
            throw ValidationException::withMessages(['branch_id' => __('inventory::message.warehouse_needs_branch')]);
        }

        return [...$data, 'branch_id' => $branch];
    }

    /**
     * এক গুদামের নিজের পাতা — কী আছে, কার হাতে, আর কোথায় বসে।
     *
     * ── কেন পাতাটা লাগল, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * ভাড়ার চুক্তি এখন গুদামের সাথে জোড়া যায়, আর তখনই ধরা পড়ল গুদামের
     * কোনো পাতাই নেই — কেবল তালিকা আর সম্পাদনার ফর্ম। মালিককে জানানোর
     * পর: *"ok"*। ⓘ প্রশ্নটা রোজকার: "এই গুদামে কত টাকার মাল আছে, ভাড়া
     * কত, আর দায়িত্বে কে?"
     *
     * ── কেন ভাড়ার সংখ্যা এখানে গোনা হয় না ───────────────────────────
     * ⛔ মজুদ মডিউল অর্থ মডিউলের নাম জানে না, আর জানা উচিতও নয় — ঠিক এই
     * সীমাটা আজই অর্থের দিকে সারানো হয়েছে। তাই ভাড়ার ঘরটা একটা **লিংক**,
     * সংখ্যা নয়: ছাঁকা তালিকাটা অর্থের পাতাতেই খোলে।
     */
    public function show(Request $request, Warehouse $warehouse, StockService $stock): View
    {
        $this->authorize('view', $warehouse);

        $states = $stock->statesForAll($warehouse);

        /*
         * ⓘ "কয়টা পণ্য" মানে যার আজ কিছু আছে — শূন্য সারিগুলো নয়।
         * ⚠️ শূন্যগুলো গুনলে সংখ্যাটা ক্যাটালগের আকার বলত, গুদামের নয়।
         */
        $withStock = collect($states)->filter(fn (array $s) => bccomp((string) ($s['floor'] ?? '0'), '0', 4) !== 0);

        return view('inventory::warehouse.show', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouse' => $warehouse->load('branch'),
            'productCount' => $withStock->count(),
            'places' => StorageLocation::query()->where('warehouse_id', $warehouse->id)->count(),
            'rentalUrl' => Route::has('finance.rental.index')
                ? route('finance.rental.index', ['subject' => 'warehouse:'.$warehouse->id])
                : null,
        ]);
    }
}
