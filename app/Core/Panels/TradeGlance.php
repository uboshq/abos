<?php

declare(strict_types=1);

namespace App\Core\Panels;

/**
 * [[CustomerTrade]]-এর উত্তর — টাকা সবসময় চার ঘরের স্ট্রিং, তারিখ Y-m-d।
 *
 * ⓘ লিংকগুলোও এখানেই আসে (নিয়ম ১ — প্রতিটা অঙ্ক তার উৎসে), কারণ কোন
 * রুটে বিল বা আদায় থাকে তা বিক্রয় জানে, গ্রাহকের পাতা জানে না।
 */
final class TradeGlance
{
    public function __construct(
        public readonly int $monthCount,
        public readonly string $monthAmount,
        public readonly int $lifetimeCount,
        public readonly string $lifetimeAmount,
        public readonly int $pendingCount,
        public readonly int $pendingItems,
        public readonly ?string $lastPurchaseDate,
        public readonly ?string $lastPurchaseAmount,
        public readonly ?string $lastPurchaseUrl,
        public readonly ?string $lastPaymentDate,
        public readonly ?string $lastPaymentAmount,
        public readonly ?string $lastPaymentUrl,
        public readonly ?string $invoicesUrl,
    ) {}
}
