<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Models;

use App\Core\Concerns\ScopedToUserBranch;
use App\Modules\Customer\Models\Customer;

/**
 * ⭐ গ্রাহকের বাকির পাতা — ড্রিলের ঠিকানা হিসেবে (বাকি নিয়ন্ত্রণ ধাপ ১.৩, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ একই সারি, একই দেয়াল (কোম্পানি, ডিলার) — কেবল চাপলে গ্রাহকের সাধারণ পাতার বদলে তাঁর বাকির পাতা খোলে। তালিকা থেকে
 * গ্রাহক, গ্রাহক থেকে বিল — মালিকের "প্রতিটা সংখ্যা নিচে নামে"।
 */
class CreditProfile extends Customer
{
    // ⛔ শাখার দেয়াল — ড্রিলের ঠিকানা হিসেবে অন্য শাখার গ্রাহক খোলে না (পাতাও নিজে যাচাই করে, [[CreditProfileController]])
    use ScopedToUserBranch;

    protected $table = 'customers';

    public static function drillSourceType(): string
    {
        return 'credit_profile';
    }

    public function drillRoute(): array
    {
        return ['credit_control.customer.show', ['customer' => $this->id]];
    }
}
