<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ খোলা মজুদের সারি সংশোধন ও মুছে ফেলা — মালিক, ৬ অক্টোবর ২০২৬: *"খোলা মজুদ দিতে গিয়ে ভুলে লট ছাড়া সেভ করে
 * ফেলেছি, এডিটের ব্যবস্থা কী?"*
 *
 * ⓘ নিয়ম আর হিসাব [[OpeningStockService::correct()]] ও [[OpeningStockService::remove()]]-এ; এখানে কেবল পর্দা, শাখার
 * দেয়াল আর খোলা মজুদের চাবি। ⛔ অন্য শাখার গুদামের সারি এই পর্দায় খোলে না (৪০৪)।
 */
class OpeningCorrectionController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly OpeningStockService $opening,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:inventory.stock.opening')];
    }

    public function edit(Request $request, StockMovement $movement): View
    {
        $this->inView($movement);
        $layer = CostLayer::query()->where('source_type', OpeningStockService::SOURCE_TYPE)->where('source_id', $movement->id)->first();

        return view('inventory::stock.opening-edit', [
            'menu' => $this->menu->forUser($request->user()),
            'movement' => $movement,
            'product' => Product::query()->findOrFail($movement->product_id),
            'warehouse' => Warehouse::query()->findOrFail($movement->warehouse_id),
            'batch' => $movement->batch_id === null ? null : Batch::query()->find($movement->batch_id),
            'layer' => $layer,
        ]);
    }

    public function update(Request $request, StockMovement $movement): RedirectResponse
    {
        $this->inView($movement);

        $data = $request->validate([
            'batch_no' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'free_qty' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'gt:0'],
        ]);

        // ⓘ দর লুকানো থাকলে ঘরটা খালি আসে — খালি মানে "দর বদলাব না"
        if (blank($data['unit_cost'] ?? null)) {
            $data['unit_cost'] = (string) CostLayer::query()->where('source_type', OpeningStockService::SOURCE_TYPE)
                ->where('source_id', $movement->id)->value('unit_cost');
        }

        $this->opening->correct($movement, [
            'batch_no' => $data['batch_no'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'qty' => (string) $data['qty'],
            'free_qty' => isset($data['free_qty']) ? (string) $data['free_qty'] : null,
            'unit_cost' => (string) $data['unit_cost'],
        ]);

        return redirect()->route('inventory.stock.opening')->with('saved', __('inventory::message.opening_corrected'));
    }

    public function destroy(StockMovement $movement): RedirectResponse
    {
        $this->inView($movement);
        $this->opening->remove($movement);

        return redirect()->route('inventory.stock.opening')->with('saved', __('inventory::message.opening_removed'));
    }

    /** ⛔ দেখা শাখার বাইরের গুদামের সারি — নেই (৪০৪), আর খোলা মজুদের সারি ছাড়া কিছু নয় */
    private function inView(StockMovement $movement): void
    {
        $ids = Warehouse::idsInViewedBranch();

        abort_if($movement->source_type !== OpeningStockService::SOURCE_TYPE, 404);
        abort_if($ids !== null && ! in_array((int) $movement->warehouse_id, array_map('intval', $ids), true), 404);
    }
}
