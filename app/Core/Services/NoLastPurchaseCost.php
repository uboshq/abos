<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\LastPurchaseCost;

/** ক্রয় মডিউল না থাকলে — কোনো ক্রয় নেই ([[LastPurchaseCost]]) */
final class NoLastPurchaseCost implements LastPurchaseCost
{
    public function of(int $productId): ?array
    {
        return null;
    }
}
