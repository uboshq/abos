<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\CustomerTrade;
use App\Core\Panels\TradeGlance;

/**
 * [[CustomerTrade]]-এর "বিক্রয় নেই" বাস্তবায়ন।
 *
 * ⓘ `null`, শূন্য নয়: বিক্রয় বন্ধ থাকা কোম্পানিতে "এই মাসে ০টা বিল" কথাটা
 * মিথ্যা নয় কিন্তু অর্থহীন — পাতা তখন অংশগুলোই আঁকে না। ⚠️ বাঁধন ছাড়া
 * কনটেইনার ছুঁড়ত আর গ্রাহকের সারাংশটাই খুলত না — [[NoCreditHolds]]-এর
 * একই কারণ।
 */
final class NoCustomerTrade implements CustomerTrade
{
    public function glanceFor(int $customerId, string $monthStart, string $today): ?TradeGlance
    {
        return null;
    }
}
