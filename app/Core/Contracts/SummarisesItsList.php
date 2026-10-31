<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Support\SummaryShape;

/**
 * ⭐ যে কাগজের তালিকা মোট দেখায় — তার মোটের নিয়ম কাগজ নিজে বলে, এক জায়গায় ([[ListSummary]])।
 *
 * ⓘ এটাই তালিকা-মোটের খাতা: [[EveryListSummaryAddsDecimalsTest]] এই চুক্তি মানা প্রতিটা মডেল খুঁজে টাকার কলাম DECIMAL কিনা
 * আর দলগুলো ঠিক কিনা আগেই দেখে — ভুলটা পরীক্ষায় ধরা পড়ে, তালিকা খোলার সময় নয়।
 */
interface SummarisesItsList
{
    public static function listSummary(): SummaryShape;
}
