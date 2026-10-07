<?php

declare(strict_types=1);

namespace App\Core\Engines\Sync;

/**
 * একটা হ্যান্ডলারের একটা পাতা — রেকর্ড, পাতাটা ভরা ছিল কি না, আর শেষ **সারির** অবস্থান (গ১৮, ৪ অক্টোবর ২০২৬)।
 *
 * ⚠️ "ভরা" আর "শেষ অবস্থান" সারি থেকে, রেকর্ড থেকে নয়। হ্যান্ডলার কখনো কখনো সারি বাদ দেয় (যেমন মুছে ফেলা পণ্যের মজুদ,
 * [[StockOnHandSync]]) — আগে সেবা রেকর্ড গুনত, তাই ১,০০০ সারির পাতায় একটা বাদ পড়লে ৯৯৯ গুনে "আর নেই" ধরে নিত আর
 * বাকিগুলো চিরতরে হারাত।
 */
final class SyncBatch
{
    /**
     * @param  list<SyncRecord>  $records
     */
    public function __construct(
        public readonly array $records,
        public readonly bool $full,
        public readonly ?SyncPosition $last,
    ) {}

    /**
     * @param  list<SyncRecord>  $records
     */
    public static function of(array $records, int $rowsRead, int $limit, ?SyncPosition $last): self
    {
        return new self(array_values($records), $rowsRead >= $limit, $rowsRead > 0 ? $last : null);
    }

    public static function empty(): self
    {
        return new self([], false, null);
    }
}
