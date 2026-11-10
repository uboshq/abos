<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\StockDemand;

/** বিক্রয় মডিউল বন্ধ থাকলে — কোনো চাহিদা নেই, কিছু হারায়নি ([[StockDemand]]) */
final class NoStockDemand implements StockDemand
{
    public function openByProduct(): array
    {
        return [];
    }

    public function lostByProduct(string $from): array
    {
        return [];
    }

    public function fillRate(string $from, string $to): ?string
    {
        return null;
    }

    public function grossProfit(string $from, string $to): string
    {
        return '0';
    }
}
