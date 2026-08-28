<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ProductionRequest;
use App\Modules\Inventory\Models\Production;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * হাঁড়ির রান্নার কাগজ।
 *
 * ── কেন এটা "লেনদেন" মেনুতে, রেসিপির পাশে নয় ─────────────────────────
 * রেসিপি একটা নিয়ম — বছরে দুইবার বদলায়। রান্না একটা **ঘটনা** — রোজ
 * সকালে ঘটে। একই মেনুতে রাখলে রোজকার কাজটা মাস্টার ডাটার সাথে মিশে
 * যেত, আর যেটা রোজ লাগে সেটা খুঁজে বের করতে হত।
 */
class ProductionController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ProductionService $productions,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Production::class, 'production'),

            /*
             * নিশ্চিত করার নিজের চাবি।
             *
             * খসড়া লেখা নিরীহ; নিশ্চিত করা মানে গুদাম থেকে মাল বেরিয়ে
             * যাওয়া। `resourcePermissions()` কেবল সাতটা চেনা কাজ চেনে,
             * তাই এটা আলাদা করে বলতে হয়।
             */
            new Middleware('can:confirm,production', only: ['confirm']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Production::query()
            ->with(['product', 'warehouse'])
            ->when((string) $request->query('status') !== '', function ($q) use ($request) {
                $q->where('status', $request->query('status'));
            })
            ->when((string) $request->query('q') !== '', function ($q) use ($request) {
                $term = '%'.$request->query('q').'%';

                $q->where('document_no', 'like', $term)
                    ->orWhereHas('product', fn ($p) => $p
                        ->where('name_en', 'like', $term)
                        ->orWhere('name_bn', 'like', $term));
            });

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
        ]);

        return view('inventory::production.index', [
            'menu' => $this->menu->forUser($request->user()),
            'productions' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'status' => $request->query('status'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => [
                'recent' => __('inventory::sort.recent'),
                'oldest' => __('inventory::sort.oldest'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory::production.form', [
            'menu' => $this->menu->forUser($request->user()),
            'production' => new Production(['trx_date' => now()->toDateString()]),
            ...$this->options(),
        ]);
    }

    public function store(ProductionRequest $request): RedirectResponse
    {
        $production = $this->productions->create($request->validated());

        return redirect()
            ->route('inventory.production.show', $production)
            ->with('saved', __('inventory::message.production_saved'));
    }

    public function show(Request $request, Production $production): View
    {
        return view('inventory::production.show', [
            'menu' => $this->menu->forUser($request->user()),
            'production' => $production->load(['lines.product', 'product', 'recipe', 'warehouse', 'creator']),
        ]);
    }

    /**
     * নিশ্চিত — এখানেই উপকরণ কমে আর খাবার ঢোকে।
     *
     * ব্যতিক্রমটা ধরা হয় না: `ProductionService` যা বলে (উপকরণ কম,
     * রেসিপি খালি) সেটাই ব্যবহারকারীর দেখা দরকার, আর ওটা
     * `ValidationException` হয়ে নিজেই ফর্মে ফেরে।
     */
    public function confirm(Production $production): RedirectResponse
    {
        $this->productions->confirm($production);

        return redirect()
            ->route('inventory.production.show', $production)
            ->with('saved', __('inventory::message.production_confirmed'));
    }

    /** খসড়া মুছে ফেলা — নিশ্চিত হয়ে গেলে নীতিই আটকায়। */
    public function destroy(Production $production): RedirectResponse
    {
        $production->delete();

        return redirect()
            ->route('inventory.production.index')
            ->with('saved', __('inventory::message.production_removed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            /*
             * কেবল **হাঁড়ির** রেসিপি।
             *
             * অর্ডারে-রান্না খাবারের জন্য উৎপাদন কাগজ লেখা মানে উপকরণ
             * দুইবার কমানো — একবার এখানে, আরেকবার বিক্রির সময়। তালিকায়
             * ওগুলো না থাকলে ভুলটা করাই যায় না।
             */
            'recipes' => Recipe::query()
                ->with('product')
                ->where('kind', Recipe::BATCH)
                ->where('is_active', true)
                ->get()
                ->mapWithKeys(fn (Recipe $r) => [$r->id => $r->product?->name() ?? ''])
                ->all(),

            'warehouses' => Warehouse::query()->active()->orderBy('code')->get()
                ->mapWithKeys(fn (Warehouse $w) => [$w->id => $w->name()])
                ->all(),

            'statuses' => [
                DocumentStatus::DRAFT => __('core.status.draft'),
                DocumentStatus::CONFIRMED => __('core.status.confirmed'),
            ],
        ];
    }
}
