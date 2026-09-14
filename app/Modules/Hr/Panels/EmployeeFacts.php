<?php

declare(strict_types=1);

namespace App\Modules\Hr\Panels;

use App\Core\Contracts\ContributesFacts;
use App\Core\Panels\Fact;
use App\Modules\Hr\Models\Employee;

/**
 * ⛔ একজন লগইনের পিছনে কোন মানুষ, আর তাঁর পদবি কী — ১৩ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন উত্তরটা এখান থেকে যায় ────────────────────────────────────────
 * মালিক ফুটারে চেয়েছেন *"Al-Amin Shuvo (CEO)"* — নাম ব্যবহারকারীর, কিন্তু
 * **পদবি HR-এর**। ⓘ শেল ([[statusbar]]) কোরের অংশ, আর কোর কোনো মডিউলের
 * নাম চেনে না — গুনে দেখা: `app/Core`, `app/Models`, `app/Providers` আর
 * শেলের ভিউ, চারটার একটাতেও `App\Modules\` লেখা **নেই**।
 *
 * ⭐ তাই ছাঁচটা [[App\Modules\Sales\Panels\SalesFacts]]-এরই: *"শেষ কেনা
 * কবে" কথাটা আসলে বিক্রয়ের; গ্রাহক কেবল তার বিষয়।* এখানে **"পদবি কী"
 * কথাটা HR-এর; ব্যবহারকারী কেবল তার বিষয়।**
 *
 * ── ⚠️ কোম্পানি ধরে, নাহলে একই ভুল এক ধাপ নিচে ───────────────────────
 * মালিক রোল বাদ দিতে বলেছেন এই যুক্তিতে: *"ekjone ekadik roll thakte
 * pare tai ekhne roll dewa zabena"*। ⓘ সরানো কোডে সত্যিই লেখা ছিল
 * `getRoleNames()->first()` — অর্থাৎ যেটা আগে পড়ে সেটাই, কোনো নিয়ম ছাড়া।
 *
 * ⛔ পদবিতেও ঠিক সেই ফাঁদ আছে: `hr_employees`-এ `user_id` **কোম্পানি
 * ধরে** অনন্য (`EmployeeController::validated()`), অর্থাৎ একই মানুষ দুই
 * কোম্পানিতে দুই পদবিতে থাকতে পারেন — ট্রেড ডিপোতে "CEO", ফ্রেশ মার্টে
 * "পরিচালক"। ⚠️ ছাঁকনি ছাড়া খুঁজলে যেটা আগে পড়ে সেটাই আসত, আর সেটা
 * `first()`-এর ভুলই, নতুন ছদ্মবেশে।
 *
 * ⭐ মডেলের গ্লোবাল স্কোপ চলতি কোম্পানিতেই সীমিত রাখে, তাই আলাদা
 * `where` বসানো হয়নি — কিন্তু কথাটা লেখা রইল, কারণ ঐ স্কোপটা একদিন
 * সরলে লক্ষণ হবে "ভুল পদবি", আর সেটা ধরা কঠিন।
 */
final class EmployeeFacts implements ContributesFacts
{
    /**
     * @return list<Fact>
     */
    public static function factsFor(string $entity, int $id): array
    {
        if ($entity !== 'user') {
            return [];
        }

        /*
         * ⓘ `with` নয়, সরাসরি সম্পর্কের কলামটাই — ফুটারে প্রতিটা পাতায়
         * এটা ডাকা হয়, তাই একটাই জোড়া-কোয়েরি যথেষ্ট।
         *
         * ⚠️ `user_id` কলামটা ২০২৬-০৮-০৯ থেকেই আছে, কিন্তু ফর্মে ঘরটা
         * বসেছে আজ। অর্থাৎ যতক্ষণ কেউ HR-এ গিয়ে মানুষটাকে ট্যাগ না
         * করছেন, এখানে কিছুই পাওয়া যাবে না — আর সেটাই সঠিক আচরণ।
         */
        $designation = Employee::query()
            ->where('user_id', $id)
            ->with('designation')
            ->first()
            ?->designation
            ?->name();

        if ($designation === null || $designation === '') {
            return [];
        }

        return [
            new Fact(
                label: 'hr::field.designation',
                value: $designation,
                sort: 10,
            ),
        ];
    }
}
