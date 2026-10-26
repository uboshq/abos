<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Contracts\PartyCreditLimit;
use App\Core\Services\SettingsService;
use App\Modules\Customer\Models\Customer;

/**
 * ⭐ গ্রাহকের বাকির সীমা হিসাবের পর্দায় — রসিদের পিকারের নিচে "বাকির সীমা ৳1,00,000" ([[PartyCreditLimit]]; মিল-তালিকা #১৯,
 * ২৫ অক্টোবর ২০২৬)।
 *
 * ⛔ এখানে কিছু গোনা হয় না — গ্রাহকের নিজের তিনটা উত্তর: চলতি সীমা [[Customer::effectiveCreditLimit()]] (অস্থায়ীসহ),
 * ব্যবহৃত [[Customer::creditUsed()]] আর অবশিষ্ট [[Customer::availableLimit()]]; গ্রাহকের পাতা আর কাউন্টারের দেয়াল যা দেখায়,
 * হুবহু তাই।
 *
 * ⭐ সীমা পরম (মালিক, ১ অক্টোবর ২০২৬: "বাকির সীমা নাই তার বিল হবে না")। তাই শূন্য সীমা মানে "সীমা ০, অবশিষ্ট ০" — `null` নয়;
 * `null` দিলে পর্দা কিছুই দেখাত না, আর মানুষ ভাবতেন যত খুশি বাকি চলে। ⓘ `null` কেবল তখন যখন সীমার কথাই নেই: কোম্পানি
 * নিজের সেটিংসে বাকির সীমার সুইচ বন্ধ রেখেছে (`customer.credit_limit_enabled`, মালিকের বিক্রয়-পরিকল্পনা ৪ অক্টোবর), বা পক্ষটা
 * এই কোম্পানির গ্রাহক নয় (কোম্পানির দেয়াল — মডেলের নিজের স্কোপ)।
 */
final class CustomerCreditLimit implements PartyCreditLimit
{
    public function __construct(private readonly SettingsService $settings) {}

    public function creditOf(string $partyType, int $partyId): ?array
    {
        if ($partyType !== Customer::drillSourceType() || ! $this->settings->enabled('customer.credit_limit_enabled')) {
            return null;
        }

        $customer = Customer::query()->find($partyId);

        if ($customer === null) {
            return null;
        }

        return [
            'limit' => bcadd($customer->effectiveCreditLimit(), '0', 2),
            'used' => bcadd($customer->creditUsed(), '0', 2),
            // ⓘ শূন্য সীমায় গ্রাহকের পাতা ড্যাশ দেখায় (`null`) — কিন্তু পরম সীমায় সেটা "কিছুই বাকি নয়"
            'available' => bcadd($customer->availableLimit() ?? '0', '0', 2),
        ];
    }
}
