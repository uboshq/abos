<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * যে শাখায় একটাই চালু গুদাম আর কোনোটা প্রধান নয়, সেটাকে প্রধান করা — লাইভের ত্রুটি (fe, ৬ অক্টোবর ২০২৬)।
 *
 * ⛔ ইউনিভারের সাত শাখার কেবল একটায় প্রধান গুদাম ছিল; বাকিগুলোর কাউন্টার লট দেখাত না। ⓘ লাইভে fe হাতে সারিয়েছে, এটা
 * ডেমো আর অন্য কোম্পানির পুরনো তথ্যের জন্য। ⓘ কেবল নিরাপদ জায়গা: একাধিক চালু গুদাম থাকলে কোনটা প্রধান তা আন্দাজ করা হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        $branches = DB::table('inv_warehouses')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('branch_id')
            ->groupBy('company_id', 'branch_id')
            ->havingRaw('COUNT(*) = 1')
            ->havingRaw('SUM(CASE WHEN is_default = 1 THEN 1 ELSE 0 END) = 0')
            ->select('company_id', 'branch_id')
            ->get();

        foreach ($branches as $b) {
            $hasMain = DB::table('inv_warehouses')->where('company_id', $b->company_id)->where('branch_id', $b->branch_id)
                ->whereNull('deleted_at')->where('is_default', true)->exists();

            if ($hasMain) {
                continue;
            }

            DB::table('inv_warehouses')
                ->where('company_id', $b->company_id)
                ->where('branch_id', $b->branch_id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        // ⓘ ফেরানোর কিছু নেই — কোন গুদাম আগে প্রধান ছিল না তা মনে রাখা হয়নি, আর প্রধান থাকাই ঠিক অবস্থা
    }
};
