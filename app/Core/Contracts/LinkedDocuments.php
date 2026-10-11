<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Models\User;

/**
 * একটা রেকর্ডের সাথে জোড়া কাগজ — গ্রাহক, সরবরাহকারী, ক্রয়াদেশ, বিল, কর্মী… (ডকুমেন্ট পরিকল্পনা §১৫;
 * ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ কোর কোনো মডিউলের নাম জানে না — রেকর্ড চেনে কেবল ড্রিলের নাম আর id দিয়ে ([[Drillable]])।
 * ডকুমেন্ট মডিউল চালু থাকলে সে এই চুক্তি বাঁধে; বন্ধ থাকলে [[NoLinkedDocuments]] — খালি তালিকা,
 * আর রেকর্ডের পাতায় অংশটাই বসে না।
 */
interface LinkedDocuments
{
    /**
     * এই মানুষ যে জোড়া কাগজগুলো দেখতে পান — নম্বর, নাম, ঠিকানা।
     *
     * @return list<array{no: string, name: string, url: string}>
     */
    public function forRecord(string $sourceType, int $sourceId, User $user): array;
}
