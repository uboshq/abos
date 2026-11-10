<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\StockSupply;

/** ক্রয় মডিউল বন্ধ থাকলে — কিছু আসছে না, বিলহীন মালও নেই ([[StockSupply]]) */
final class NoStockSupply implements StockSupply
{
    public function onOrderByProduct(): array
    {
        return [];
    }

    public function unbilledReceipts(): array
    {
        return ['receipts' => 0, 'value' => '0', 'supplier' => ''];
    }
}
