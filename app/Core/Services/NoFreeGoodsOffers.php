<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\FreeGoodsOffers;

/** অফারের মডিউল বন্ধ — কোনো লাইনে ফ্রি নেই ([[FreeGoodsOffers]])। */
final class NoFreeGoodsOffers implements FreeGoodsOffers
{
    public function forLine(array $line): array
    {
        return [];
    }
}
