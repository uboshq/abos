<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\PayableOpenBills;

/** ক্রয় মডিউল না থাকলে — খোলা বিল নেই ([[PayableOpenBills]]) */
final class NoPayableOpenBills implements PayableOpenBills
{
    public function openBills(string $partyType, int $partyId, int $limit = 50): array
    {
        return [];
    }
}
