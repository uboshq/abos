<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Assistant;

use App\Core\Engines\Assistant\AssistantTool;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;

/**
 * ⭐ কোপাইলটের দরজা — "চিনি কত আছে?" (পূর্ণ পরিকল্পনা অংশ ২)। গুদাম ধরে বিক্রয়যোগ্য পরিমাণ — মজুদের নিজের হিসাব
 * ([[StockService::availableQty()]]), গুদামের দেয়াল মেনে। ⓘ পরিমাণ, টাকা নয় — খরচের চাবি লাগে না।
 */
final class StockOnHandTool implements AssistantTool
{
    public static function name(): string
    {
        return 'stock.on_hand';
    }

    public static function permission(): string
    {
        return 'inventory.stock.view';
    }

    public static function describe(): array
    {
        return [
            'description' => ['bn' => 'একটা পণ্যের মজুদ কত আছে, গুদাম ধরে', 'en' => 'How much of a product is in stock, by warehouse'],
            'parameters' => ['product' => ['type' => 'string', 'description' => 'product name or code']],
            'required' => ['product'],
            'keywords' => ['মজুদ', 'স্টক', 'কত আছে', 'koto ache', 'stock', 'mojud', 'on hand'],
        ];
    }

    public function run(User $user, array $args): array
    {
        // ⭐ "সব স্টক", "আজকের স্টক" — পণ্যের নাম নয়, পুরো মজুদ (মালিক, ১৩ নভেম্বর ২০২৬; [[StockSummaryTool]])
        if (StockSummaryTool::asksForAll($args)) {
            return (new StockSummaryTool)->run($user, $args);
        }

        $product = ProductInQuestion::find($args);

        if ($product === null) {
            return ['rows' => [], 'columns' => [], 'summary' => ProductInQuestion::notFound($args), 'sources' => []];
        }

        $stock = app(StockService::class);
        $rows = [];
        $total = '0';

        foreach (Warehouse::query()->where('is_active', true)->get() as $w) {
            $qty = $stock->availableQty($product, $w);
            $total = bcadd($total, $qty, 4);
            $rows[] = ['warehouse' => (string) $w->drillLabel(), 'qty' => rtrim(rtrim($qty, '0'), '.') ?: '0'];
        }

        return [
            'rows' => $rows,
            'columns' => ['warehouse', 'qty'],
            'summary' => (string) __('inventory::assistant.on_hand', ['product' => $product->name(), 'qty' => rtrim(rtrim($total, '0'), '.') ?: '0']),
            'sources' => [['label' => (string) __('inventory::assistant.source_stock'), 'href' => route('inventory.stock.index', ['q' => $product->code])]],
        ];
    }
}
