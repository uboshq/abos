<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Contracts\PartyTaxIds;
use App\Modules\Supplier\Models\Supplier;

/**
 * ⭐ সরবরাহকারীর BIN আর TIN — মাস্টার তথ্যের নিজের কলাম (`suppliers.bin`, `suppliers.tin`) থেকে, হিসাবের পর্দার জন্য
 * ([[PartyTaxIds]]; মিল-তালিকা #৬০, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ গ্রাহকের মাস্টারে BIN/TIN-এর কোনো ঘর নেই (`customers` টেবিলে দুইটার একটাও নেই) — তাই গ্রাহকের জন্য উত্তর `null`, বানিয়ে
 * কিছু নয়। গ্রাহকের মাস্টারে ঘর এলে গ্রাহক মডিউল নিজের বাস্তবায়ন দেবে।
 * ⓘ অন্য কোম্পানির সরবরাহকারী মডেলের কোম্পানি-স্কোপেই পড়ে না — উত্তর `null`।
 */
final class SupplierTaxIds implements PartyTaxIds
{
    public function taxIdsOf(string $partyType, int $partyId): ?array
    {
        if ($partyType !== Supplier::drillSourceType()) {
            return null;
        }

        $supplier = Supplier::query()->find($partyId);

        if ($supplier === null) {
            return null;
        }

        $bin = trim((string) $supplier->bin);
        $tin = trim((string) $supplier->tin);

        if ($bin === '' && $tin === '') {
            return null;
        }

        return ['bin' => $bin !== '' ? $bin : null, 'tin' => $tin !== '' ? $tin : null];
    }
}
