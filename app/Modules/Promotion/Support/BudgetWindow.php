<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Support;

use App\Modules\Promotion\Models\PromotionApplication;
use Illuminate\Support\Carbon;

/**
 * একটা সুবিধা কোন বিলে, কোন ক্রেতার, কোন মুহূর্তে — ছাদের জানালা মাপতে।
 *
 * ── ⭐ কেন আলাদা একটা বস্তু ──────────────────────────────────────────
 * ⓘ *"প্রতি বিলে ৫০০ টাকার বেশি নয়"* মাপতে বিলটা জানা লাগে; *"প্রতি
 * ক্রেতা মাসে ২,০০০"* মাপতে ক্রেতা আর তারিখ। ⚠️ চারটা আলগা প্যারামিটার
 * হলে একদিন কেউ তারিখটা ভুলে যেতেন, আর মাসিক ছাদ নীরবে *"আজ"* ধরে
 * মাপত — ⛔ কোনোদিন কিছু ভাঙত না, কেবল সংখ্যাটা ভুল হত।
 */
final class BudgetWindow
{
    public function __construct(
        public readonly ?string $sourceType,
        public readonly ?int $sourceId,
        public readonly ?int $customerId,
        public readonly Carbon $at,
    ) {}

    /** ⓘ নতুন সুবিধা বসানোর মুহূর্তে — বিলটা এখনো লেখা হয়নি, তাই হাতে দেওয়া */
    public static function forNewLine(string $sourceType, int $sourceId, ?int $customerId, ?Carbon $at = null): self
    {
        return new self($sourceType, $sourceId, $customerId, $at ?? Carbon::now());
    }

    /**
     * ⓘ ইতিমধ্যে বসানো সারি থেকে — হাতে বদলের সময়।
     *
     * ⚠️ তারিখটা সারির **জন্মের** তারিখ, আজকের নয়: ⓘ গত মাসের বিলের ছাড়
     * আজ বাড়ালে সেটা গত মাসের ছাদেই গোনা হয়, কারণ টাকাটা সেই বিলেই যায়।
     */
    public static function of(PromotionApplication $applied): self
    {
        return new self(
            $applied->source_type,
            $applied->source_id !== null ? (int) $applied->source_id : null,
            $applied->customer_id !== null ? (int) $applied->customer_id : null,
            $applied->created_at !== null ? Carbon::parse($applied->created_at) : Carbon::now(),
        );
    }
}
