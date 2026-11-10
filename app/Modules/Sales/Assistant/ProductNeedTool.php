<?php

declare(strict_types=1);

namespace App\Modules\Sales\Assistant;

use App\Core\Engines\Assistant\AssistantTool;
use App\Core\Engines\Forecast\ForecastEngine;
use App\Models\User;
use App\Modules\Inventory\Assistant\ProductInQuestion;
use App\Modules\Inventory\Services\StockService;

/**
 * ⭐ কোপাইলটের দরজা — "আগামী সপ্তাহে চিনি কত লাগবে?" (পূর্ণ পরিকল্পনা অংশ ২)।
 * ⓘ চাহিদা পূর্বাভাসের ইঞ্জিন থেকে ([[ForecastEngine]], উৎস `sales.product_qty`) — সীমা (নিচে–উপরে), ভরসা আর আগের সপ্তাহের মিল সহ;
 *   হাতে কত মজুদের নিজের হিসাব থেকে ([[StockService::availableQty()]]); কিনতে হবে = চাহিদা − হাতে (শূন্যের নিচে নয়)।
 * ⓘ উৎসব ধরা হয় না — উত্তরেই লেখা থাকে।
 */
final class ProductNeedTool implements AssistantTool
{
    public static function name(): string
    {
        return 'sales.product_need';
    }

    public static function permission(): string
    {
        return 'sales.invoice.view';
    }

    public static function describe(): array
    {
        return [
            'description' => ['bn' => 'একটা পণ্যের আগামী কয়েক দিনের চাহিদা, হাতে কত, কত কিনতে হবে', 'en' => 'Expected demand of a product over the next days, on hand, and how much to buy'],
            'parameters' => [
                'product' => ['type' => 'string', 'description' => 'product name or code'],
                'days' => ['type' => 'integer', 'description' => 'how many days ahead (1-60, default 7)'],
            ],
            'required' => ['product'],
            'keywords' => ['লাগবে', 'চাহিদা', 'পূর্বাভাস', 'কিনতে হবে', 'lagbe', 'chahida', 'need', 'forecast', 'demand'],
        ];
    }

    public function run(User $user, array $args): array
    {
        $product = ProductInQuestion::find($args);

        if ($product === null) {
            return ['rows' => [], 'columns' => [], 'summary' => (string) __('inventory::assistant.no_product'), 'sources' => []];
        }

        $days = max(1, min(60, (int) ($args['days'] ?? (str_contains(mb_strtolower((string) ($args['q'] ?? '')), 'মাস') ? 30 : 7))));
        $f = app(ForecastEngine::class)->forecast('sales.product_qty', $user, $days, ['product_id' => $product->id]);
        $have = app(StockService::class)->availableQty($product);
        $buy = bcsub($f['total'], $have, 4);
        $n = fn (string $v): string => number_format((float) $v, 0, '.', ',');

        return [
            'rows' => [
                ['what' => (string) __('sales::assistant.need_expected'), 'value' => $n($f['total']).' ('.$n($f['low']).'–'.$n($f['high']).')'],
                ['what' => (string) __('sales::assistant.need_have'), 'value' => $n($have)],
                ['what' => (string) __('sales::assistant.need_buy'), 'value' => bccomp($buy, '0', 4) > 0 ? $n($buy) : '0'],
                ['what' => (string) __('sales::assistant.need_confidence'), 'value' => (string) __('sales::assistant.confidence.'.$f['confidence'])
                    .($f['accuracy'] === null ? '' : ' · '.__('sales::assistant.accuracy', ['pct' => $f['accuracy']]))],
            ],
            'columns' => ['what', 'value'],
            'summary' => (string) __('sales::assistant.need_summary', ['product' => $product->name(), 'days' => $days, 'qty' => $n($f['total']),
                'low' => $n($f['low']), 'high' => $n($f['high']), 'have' => $n($have), 'buy' => bccomp($buy, '0', 4) > 0 ? $n($buy) : '0']),
            'sources' => [['label' => (string) __('sales::assistant.need_how', ['days' => $f['basis']['history_days']]), 'href' => route('module.dashboard', ['module' => 'inventory'])]],
        ];
    }
}
