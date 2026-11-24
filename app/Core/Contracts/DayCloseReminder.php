<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Models\User;

/**
 * ⭐ দিন বন্ধ হয়নি — নিচের চলন্ত বারের এক লাইন (মালিক, ১৮ নভেম্বর ২০২৬: *"পপআপে যা দেখাবে ঠিক না হওয়ার আগ পর্যন্ত পপআপ কাটার পরে
 * নিচের নোটিশ বারে অল টাইম চলতেই থাকবে"*)।
 *
 * ⓘ কোর প্রশ্নটা রাখে ([[StatusNotices::forTicker()]]), উত্তর হিসাব মডিউলের — কোন দিন বন্ধ হয়নি তা ওর হিসাব ([[DayChecklist]])।
 *   হিসাব বন্ধ থাকলে কোরের শূন্য-বাস্তবায়ন ([[\App\Core\Services\NoDayCloseReminder]]) — বারে কিছু নেই।
 */
interface DayCloseReminder
{
    /**
     * এই মানুষের জন্য একটা লাইন (দিন বা মাস বন্ধ হয়নি), নইলে null।
     *
     * @return array{text: string, url: ?string, tone: string}|null
     */
    public function forTicker(User $user): ?array;
}
