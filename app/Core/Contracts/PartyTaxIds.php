<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ একজন পক্ষের BIN আর TIN — পরিশোধ ভাউচারের মাথায় "BIN 000123456-0101 · TIN 1234567890" (রসিদ-পরিশোধ মিল-তালিকা #৬০,
 * ২৫ অক্টোবর ২০২৬; নকশা পাতা ৪)।
 *
 * ── কেন কোরে একটা চুক্তি ([[PayeeBankGuard]]-এর ছাঁচ) ───────────────────
 * BIN/TIN পক্ষের মাস্টার তথ্যে (সরবরাহকারীর `bin`, `tin` কলাম), আর হিসাব সরবরাহকারী মডিউল পড়তে পারে না (BoundariesTest)।
 * ⓘ যার তথ্য সে-ই বাস্তবায়ন দেয় (`module.php`-র `bindings`); মডিউল বন্ধ থাকলে কোরের [[NoPartyTaxIds]] `null` বলে।
 */
interface PartyTaxIds
{
    /**
     * `bin` আর `tin` — কোনোটা না থাকলে সেটা `null`; দুইটাই না থাকলে, পক্ষ অচেনা বা অন্য কোম্পানির হলে গোটা উত্তর `null`।
     *
     * @return array{bin: ?string, tin: ?string}|null
     */
    public function taxIdsOf(string $partyType, int $partyId): ?array;
}
