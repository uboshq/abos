<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\PartyOpenBills;

/**
 * বিক্রয় বন্ধ — কারও খোলা বিল নেই ([[PartyOpenBills]])।
 */
final class NoPartyOpenBills implements PartyOpenBills
{
    public function openBills(string $partyType, int $partyId, int $limit = 50): array
    {
        return [];
    }
}
