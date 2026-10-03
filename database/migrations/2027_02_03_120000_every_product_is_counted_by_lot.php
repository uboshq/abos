<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ⛔ প্রতিটা পণ্য লট ধরে — মালিক, ৩ অক্টোবর ২০২৬: "লট ধরে হিসাব … বন্ধ করা যাবে না … ok"।
 *
 * ⓘ আগে বন্ধ ছিল এমন সব পণ্য চালু হয়। লটহীন যে মজুদ আগে ঢুকেছে সেটা লটহীনই থাকে — "আটকে থাকা মজুদ"
 * পাতা থেকে লট বসানো যায় ([[StrandedStockController]])। ⓘ লাইভে আগে থেকেই সব চালু ছিল; বদলায় ডেমো।
 * ফেরত নেই — আগের মান মনে রাখার কোনো কারণ নেই, নিয়মটাই সবসময় চালু।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inv_products', 'track_batch')) {
            return;
        }

        DB::table('inv_products')->where('track_batch', false)->update(['track_batch' => true]);
    }

    public function down(): void
    {
        // ⓘ ইচ্ছাকৃতভাবে কিছু নয় — কোন পণ্য আগে বন্ধ ছিল তা রাখা হয়নি, আর নিয়মটা ফেরানোর নয়।
    }
};
