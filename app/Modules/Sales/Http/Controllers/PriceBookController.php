<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\Sales\Models\PriceListItem;
use App\Modules\Sales\Services\PriceBookService;
use App\Modules\Sales\Services\SalesPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * দর তালিকা — গ্রাহকের, ধরনের, এলাকার আর সবার (মালিক, ৫ অক্টোবর ২০২৬; আগে "তৈরি হচ্ছে"-র চারটা পাতা)।
 *
 * ⓘ দেখা `sales.price_list.view`, বানানো-বদলানো `sales.price_list.manage` — দর বসানো মানে কে কত দেবেন তা ঠিক করা,
 * তাই বিক্রয়কর্মীর ঢালাও চাবিতে নয় (ডেমো সিডারের বাদ-তালিকায়)।
 */
final class PriceBookController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly PriceBookService $book,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.price_list.view', only: ['index', 'show', 'history']),
            new Middleware('can:sales.price_list.manage', only: ['create', 'store', 'edit', 'update', 'storeItems', 'destroyItem']),
        ];
    }

    public function index(Request $request): View
    {
        $target = $this->target($request->query('target'));
        $q = $request->string('q')->toString();
        $lists = $this->book->lists($target, $q);

        return view('sales::price_book.index', [
            'menu' => $this->menu->forUser($request->user()),
            'target' => $target,
            'lists' => $lists,
            'counts' => $this->book->itemCounts($lists->items()),
            'aims' => $this->aims($lists->items()),
            'q' => $q,
            'canManage' => (bool) $request->user()?->can('sales.price_list.manage'),
        ]);
    }

    public function create(Request $request): View
    {
        $target = $this->target($request->query('target'));

        return view('sales::price_book.form', [
            'menu' => $this->menu->forUser($request->user()),
            'list' => new PriceList(['is_active' => true]),
            'target' => $target,
        ] + $this->choices());
    }

    public function store(Request $request): RedirectResponse
    {
        $list = $this->book->saveList(null, $this->validated($request));

        return redirect()->route('sales.price_book.show', $list)->with('saved', __('sales::price_book.saved'));
    }

    public function edit(Request $request, PriceList $list): View
    {
        return view('sales::price_book.form', [
            'menu' => $this->menu->forUser($request->user()),
            'list' => $list,
            'target' => SalesPrice::targetOf($list),
        ] + $this->choices());
    }

    public function update(Request $request, PriceList $list): RedirectResponse
    {
        $this->book->saveList($list, $this->validated($request));

        return redirect()->route('sales.price_book.show', $list)->with('saved', __('sales::price_book.saved'));
    }

    public function show(Request $request, PriceList $list): View
    {
        $products = Product::query()->active()->with('unit')->orderBy('name_en')->get();

        return view('sales::price_book.show', [
            'menu' => $this->menu->forUser($request->user()),
            'list' => $list,
            'target' => SalesPrice::targetOf($list),
            'aim' => $this->aims([$list])[(int) $list->id] ?? null,
            'items' => $this->book->items($list),
            'products' => $products,
            'packs' => app(PackConversion::class)->optionsFor($products),
            'canManage' => (bool) $request->user()?->can('sales.price_list.manage'),
        ]);
    }

    /** গ্রিডের সারি — পণ্য, ঐচ্ছিক একক, দর; শুরুর ও শেষের তারিখ সবার জন্য একটা */
    public function storeItems(Request $request, PriceList $list): RedirectResponse
    {
        $data = $request->validate([
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'rows' => ['required', 'array', 'max:2000'],
            'rows.*.product_id' => ['nullable', 'integer'],
            'rows.*.unit_id' => ['nullable', 'integer'],
            'rows.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $count = $this->book->saveItems($list, $data['rows'], $data['valid_from'], $data['valid_to'] ?? null);

        return redirect()->route('sales.price_book.show', $list)->with('saved', __('sales::price_book.rows_saved', ['count' => $count]));
    }

    public function destroyItem(PriceList $list, PriceListItem $item): RedirectResponse
    {
        $this->book->removeItem($list, $item);

        return redirect()->route('sales.price_book.show', $list)->with('saved', __('sales::price_book.row_removed'));
    }

    public function history(Request $request, PriceList $list, Product $product): View
    {
        return view('sales::price_book.history', [
            'menu' => $this->menu->forUser($request->user()),
            'list' => $list,
            'product' => $product,
            'rows' => $this->book->history($list, $product),
        ]);
    }

    private function target(mixed $value): string
    {
        return in_array($value, PriceBookService::TARGETS, true) ? (string) $value : SalesPrice::CUSTOMER;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'name_en' => ['required', 'string', 'max:60'],
            'name_bn' => ['nullable', 'string', 'max:60'],
            'target' => ['required', 'string', 'in:'.implode(',', PriceBookService::TARGETS)],
            'target_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }

    /** @return array<string, mixed> ফর্মের তিনটা বাছাই */
    private function choices(): array
    {
        return [
            'customers' => Customer::query()->inViewedBranch()->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn']),
            'tiers' => PartyType::query()->active()->whereIn('applies_to', [PartyType::CUSTOMER, PartyType::BOTH])->orderBy('code')->get(),
            'places' => Location::query()->active()->orderBy('code')->get(),
        ];
    }

    /**
     * প্রতিটা তালিকা কার জন্য — নাম, পর্দায় লেখার জন্য।
     *
     * @param  iterable<PriceList>  $lists
     * @return array<int, string>
     */
    private function aims(iterable $lists): array
    {
        $lists = collect($lists);
        $customers = Customer::query()->inViewedBranch()->whereIn('id', $lists->pluck('customer_id')->filter())->get()->keyBy('id');
        $tiers = PartyType::query()->whereIn('id', $lists->pluck('party_type_id')->filter())->get()->keyBy('id');
        $places = Location::query()->whereIn('id', $lists->pluck('location_id')->filter())->get()->keyBy('id');

        return $lists->mapWithKeys(fn (PriceList $l) => [(int) $l->id => match (SalesPrice::targetOf($l)) {
            SalesPrice::CUSTOMER => $customers->get($l->customer_id)?->name() ?? '—',
            SalesPrice::TIER => $tiers->get($l->party_type_id)?->name() ?? '—',
            SalesPrice::TERRITORY => $places->get($l->location_id)?->path() ?? '—',
            default => __('sales::price_book.everyone'),
        }])->all();
    }
}
