<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * ⭐ একজন মানুষের প্রোফাইলে মডিউলের যা বলার আছে — পরিচয়ের ঘর আর "এই মাসে আমার কাজ"-এর সংখ্যা ([[ProfileFacts]])।
 *
 * ⓘ প্রোফাইল কোরের পাতা, কিন্তু পদবি আর কর্মী-নম্বর HR-এর কথা, বিক্রির অঙ্ক বিক্রয়ের, ভাউচারের সংখ্যা হিসাবের। কোর কোনো
 *   মডিউলের নাম জানে না (§১৯.৭), তাই প্রতিটা মডিউল `module.php` → `profile` তালিকায় নিজের সরবরাহকারী ঘোষণা করে, আর কোর
 *   কেবল জিজ্ঞেস করে "এই মানুষটা সম্পর্কে কারও কিছু বলার আছে?" — ঠিক [[ContributesFacts]]-এর ছাঁচ।
 *
 * ⓘ মডিউল বন্ধ থাকলে কেউ বলে না — ঘরটা খালি, সংখ্যাটা বাদ, পাতা চলে।
 */
interface ContributesToAProfile
{
    /**
     * পরিচয়ের যে ঘরগুলো এই মডিউল জানে — না জানলে চাবিটা বাদ বা null।
     *
     * ⓘ চেনা চাবি: `designation`, `phone`, `employee_no`, `joined_on` (Y-m-d)। ⛔ কেবল এই চালু কোম্পানির রেকর্ড থেকে।
     *
     * @return array<string, ?string>
     */
    public function identityOf(User $owner): array;

    /**
     * এই মাসের একটা সংখ্যা, দেখানোর মতো করে লেখা ("৳12,500", "7", "85%") — এই চাবি না চিনলে বা বলার কিছু না থাকলে null।
     *
     * ⓘ চাবিগুলো [[ProfileFacts]]-এর ছক থেকে (`sales_total`, `my_sales`, `my_orders`, `target`, `vouchers_posted`,
     *   `deposits_matched`)। `sales_total` গোটা কোম্পানির (মালিকের "মোট বিক্রি"), বাকিগুলো `$owner`-এর নিজের। ⛔ মোট কেবল
     *   পাকা কাগজের।
     */
    public function stat(string $key, User $owner, Carbon $from, Carbon $to): ?string;
}
