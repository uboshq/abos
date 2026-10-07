<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\CreditHolds;

/**
 * [[CreditHolds]]-এর "কিছুই আটকে নেই" বাস্তবায়ন।
 *
 * ⓘ বিক্রয় মডিউল বন্ধ থাকলে ডিও বা খসড়া বিল বলে কিছু নেই, তাই শূন্যই
 * সত্যি। ⚠️ বাঁধন ছাড়া কনটেইনার ছুঁড়ত, আর গ্রাহকের পাতাটাই খুলত না —
 * [[NoRecipeBook]]-এর একই কারণ।
 */
final class NoCreditHolds implements CreditHolds
{
    public function heldFor(int $customerId): string
    {
        return '0';
    }
}
