<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\PayeeBankAccount;

/** ⓘ সরবরাহকারী মডিউল বন্ধ — কোনো ব্যাংক তথ্য রাখা নেই, তাই ফাইলে যাওয়ার মতোও কিছু নেই ([[PayeeBankAccount]]) */
final class NoPayeeBankAccount implements PayeeBankAccount
{
    public function accountOf(string $partyType, int $partyId): ?array
    {
        return null;
    }
}
