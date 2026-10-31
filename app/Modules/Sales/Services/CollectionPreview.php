<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

/**
 * ⭐ খাতায় যা যাবে — পোস্টের আগে (দফা ১০; SAP-এর "Simulate"), ওয়েবের পর্দা আর ফোন দুজনেরই (a5-র চুক্তি, ২১ অক্টোবর ২০২৬)।
 *
 * ⛔ নিজের কোনো হিসাব নেই — [[CollectionService::preview()]] সেবার সেই একই লাইন-বানানো ডাকে যেটা পোস্ট খাতায় পাঠায়, তাই প্রিভিউ
 * আর খাতা আলাদা কথা বলতে পারে না। কিছুই লেখে না।
 */
final class CollectionPreview
{
    public function __construct(private readonly CollectionService $collections) {}

    /** @return array<string, mixed> */
    public function of(CollectionInput $in): array
    {
        return $this->collections->preview($in);
    }
}
