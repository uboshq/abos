<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\PartyTaxIds;

/**
 * পক্ষের মডিউল বন্ধ থাকলে — কারো BIN/TIN রাখা নেই ([[PartyTaxIds]])।
 */
final class NoPartyTaxIds implements PartyTaxIds
{
    public function taxIdsOf(string $partyType, int $partyId): ?array
    {
        return null;
    }
}
