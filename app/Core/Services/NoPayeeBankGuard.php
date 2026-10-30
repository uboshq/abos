<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\PayeeBankGuard;

/**
 * সরবরাহকারী মডিউল বন্ধ থাকলে — কোনো ব্যাংক তথ্য রাখা নেই, তাই আটকানোর মতো কিছু নেই ([[PayeeBankGuard]])।
 */
final class NoPayeeBankGuard implements PayeeBankGuard
{
    public function assertBankPayable(?string $partyType, ?int $partyId, ?string $method): void
    {
    }
}
