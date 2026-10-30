<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\PayeeBankView;

/**
 * সরবরাহকারী মডিউল বন্ধ থাকলে — কারো ব্যাংক তথ্য রাখা নেই, দেখানোর কিছু নেই ([[PayeeBankView]])।
 */
final class NoPayeeBankView implements PayeeBankView
{
    public function bankOf(string $partyType, int $partyId): ?array
    {
        return null;
    }
}
