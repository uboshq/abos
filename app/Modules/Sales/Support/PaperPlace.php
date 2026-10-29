<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;

/**
 * ⭐ ছাপা কাগজের "পয়েন্ট · শাখা" ঘর — বিল, চালান, আদেশ, রসিদের নকশাগুলোর `point` (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম:
 * *"গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে"*)।
 *
 * ⓘ নকশাগুলো (২০০-র বেশি) একই `point` ঘর আর `sales::print.classic.point` নাম পড়ে — তাই শাখা ঐ এক লাইনেই জোড়া, নতুন
 * লাইন নয়: কাগজের উচ্চতা বদলায় না, A4/A5 এক পাতায় যা ধরত তা-ই ধরে। নকশাগুলো ইংরেজি নামে ছাপে, তাই শাখাও ইংরেজি।
 * ⚠️ ডাকার আগে `customer.location.parent` আর `customer.branch` একসাথে আনুন — ছাপার পথে lazy loading বন্ধ।
 */
final class PaperPlace
{
    /** "Sample Bazar · Dhaka" — যেটা নেই সেটা বাদ; দুটোই না থাকলে খালি */
    public static function of(?Customer $customer, ?Location $point): string
    {
        $branch = $customer?->relationLoaded('branch') ? trim((string) $customer->branch?->name('en')) : '';

        return implode(' · ', array_filter([trim((string) ($point?->name('en') ?? '')), $branch], fn (string $part) => $part !== ''));
    }
}
