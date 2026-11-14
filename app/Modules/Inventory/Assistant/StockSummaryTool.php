<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Assistant;

use App\Core\Engines\Assistant\AssistantTool;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;

/**
 * ⭐ কোপাইলটের দরজা — "সব স্টক দেখাও", "আজকে কী স্টক আছে", "ফুল স্টক" (মালিক, ১৩ নভেম্বর ২০২৬: "এগুলো কিছুই বোঝে না … শুধু বলে
 *   জানি না, এই নামে কিছু নাই")। ⓘ আগে কেবল এক পণ্যের দরজা ছিল ([[StockOnHandTool]]) — মডেল "সব"-কেই পণ্যের নাম ধরে পাঠাত।
 *
 * ⓘ এই মুহূর্তের বিক্রয়যোগ্য পরিমাণ, সব পণ্য এক কোয়েরিতে ([[StockService::statesForAll()]]) — গুদামের দেয়াল মেনে, তাই যে যা দেখেন
 *   কেবল তাই। ⛔ পরিমাণ, টাকা নয় — খরচের চাবি লাগে না।
 */
final class StockSummaryTool implements AssistantTool
{
    /** ⓘ উত্তরে কয়টা সারি — বেশি পরিমাণ আগে; পুরোটা মজুদের পাতায় */
    public const ROWS = 25;

    public static function name(): string
    {
        return 'stock.summary';
    }

    public static function permission(): string
    {
        return 'inventory.stock.view';
    }

    public static function describe(): array
    {
        return [
            'description' => [
                'bn' => 'পুরো মজুদ এখন — কী কী পণ্য আছে আর কত, কয়টা শেষ; সব স্টক, আজকের স্টক, ফুল স্টক',
                'en' => 'The whole stock right now — every product in stock and how much, and how many are out; all stock, today\'s stock, full stock',
            ],
            'parameters' => [],
            'required' => [],
            'keywords' => ['সব স্টক', 'ফুল স্টক', 'আজকের স্টক', 'কী স্টক আছে', 'কি স্টক আছে', 'কী কী আছে', 'মোট মজুদ', 'পুরো মজুদ', 'সব মজুদ',
                'স্টক দেখাও', 'মজুদ দেখাও', 'full stock', 'all stock', 'stock list', 'sob stock', 'ajker stock', 'ki stock ache'],
        ];
    }

    public function run(User $user, array $args): array
    {
        $states = app(StockService::class)->statesForAll();
        $products = Product::query()->where('is_active', true)->with('unit')->get(['id', 'code', 'name_en', 'name_bn', 'unit_id']);

        $inStock = [];
        $out = 0;

        foreach ($products as $p) {
            $qty = (string) ($states[(int) $p->id]['available'] ?? '0');

            if (bccomp($qty, '0', 4) > 0) {
                $inStock[] = [$p, $qty];
            } else {
                $out++;
            }
        }

        usort($inStock, fn (array $a, array $b): int => bccomp($b[1], $a[1], 4));

        $rows = array_map(fn (array $row): array => [
            'product' => $row[0]->code.' — '.$row[0]->name(),
            'qty' => (rtrim(rtrim($row[1], '0'), '.') ?: '0').($row[0]->unit !== null ? ' '.$row[0]->unit->name() : ''),
        ], array_slice($inStock, 0, self::ROWS));

        return [
            'rows' => $rows,
            'columns' => ['product', 'qty'],
            'summary' => (string) __('inventory::assistant.stock_summary', ['count' => count($inStock), 'out' => $out]),
            'sources' => [['label' => (string) __('inventory::assistant.source_stock'), 'href' => route('inventory.stock.index')]],
        ];
    }

    /**
     * "সব", "আজকের", "ফুল", "মোট", "all" — পণ্যের নাম নয়; এক-পণ্যের দরজায় এলে পুরো মজুদ ([[StockOnHandTool::run()]])।
     *
     * @param  array<string, mixed>  $args
     */
    public static function asksForAll(array $args): bool
    {
        $asked = mb_strtolower(trim((string) ($args['product'] ?? '')));

        if ($asked === '') {
            return false;
        }

        $words = preg_split('/[\s,।]+/u', $asked) ?: [];
        $general = ['সব', 'সকল', 'সবগুলো', 'আজকের', 'আজ', 'ফুল', 'পুরো', 'মোট', 'সম্পূর্ণ', 'স্টক', 'মজুদ', 'পণ্য', 'মাল', 'কী', 'কি', 'কী কী',
            'all', 'full', 'total', 'today', 'stock', 'every', 'everything', 'products', 'sob', 'ajker'];

        foreach ($words as $w) {
            if ($w !== '' && ! in_array($w, $general, true)) {
                return false;
            }
        }

        return true;
    }
}
