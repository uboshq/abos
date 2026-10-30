<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\PartyCreditLimit;

/**
 * গ্রাহক মডিউল বন্ধ থাকলে — কারো বাকির সীমা রাখা নেই, তাই বলার কিছু নেই ([[PartyCreditLimit]])।
 * ⚠️ বাঁধন ছাড়া কনটেইনার ছুঁড়ত, আর রসিদের পর্দাটাই খুলত না — [[NoCreditHolds]]-এর একই কারণ।
 */
final class NoPartyCreditLimit implements PartyCreditLimit
{
    public function creditOf(string $partyType, int $partyId): ?array
    {
        return null;
    }
}
