<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
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
            'warehouse' => new Warehouse(['is_active' => true]),
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->warehouses->create($this->validated($request));

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
}
