<?php

declare(strict_types=1);

use App\Models\Company;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use Illuminate\Database\Migrations\Migration;

/**
 * চলতি প্রতিটা কোম্পানিতে বিক্রয়ের ছাড়ে মালিকের সই — মালিকের নিয়ম, ১ অক্টোবর ২০২৬ ([[OwnerSignsDiscounts]])।
 *
 * ⓘ নতুন কোম্পানি খোলার সময়েই পায় (`provisions`); এটা কেবল পুরনোগুলোর জন্য, একবার। আগের ধাপ মোছে না, মালিকেরটা যোগ করে। super_admin রোল না পেলে সেই
 * কোম্পানি বাদ — মাইগ্রেশন থামে না, কিন্তু নামটা লগে যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(OwnerSignsDiscounts::class);

        foreach (Company::query()->withoutGlobalScopes()->get() as $company) {
            $report = $service->ensure($company);

            /*
             * ⓘ আগে কী ছিল (সীমা, চালু কিনা, ধাপ) লগে থাকে — কোম্পানির নিজের বসানো ছক কী ছিল, পরে দেখা যায়।
             */
            $report['done']
                ? logger()->info('Owner discount signature set', $report)
                : logger()->warning('Owner discount signature not set', $report);
        }
    }

    public function down(): void
    {
        // ⓘ ফেরানো হয় না — মালিকের সই তুলে দেওয়া মালিকের নিজের সিদ্ধান্ত, ছকের পর্দা থেকে
    }
};
