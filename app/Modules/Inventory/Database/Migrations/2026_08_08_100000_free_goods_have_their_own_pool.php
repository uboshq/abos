<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ফ্রি পণ্যের নিজস্ব ভাণ্ডার।
 *
 * ── কেন আলাদা, বিক্রয়ের মজুদ থেকে নয় ─────────────────────────────────
 * ফ্রি মাল আসে প্রস্তুতকারকের কাছ থেকে, বিক্রির জন্য নয় — "১০ কার্টন কিনলে
 * ১ কার্টন ফ্রি"। ওই এক কার্টন কোম্পানি কেনেনি, তার কোনো ক্রয়মূল্য নেই, আর
 * সেটা কেউ টাকা দিয়ে কিনতেও পারবে না।
 *
 * একই ভাণ্ডারে রাখলে তিনটা জিনিস ভাঙত:
 *
 *   ১. বিক্রয়যোগ্য সংখ্যাটা বেশি দেখাত — কাউন্টারে "৭৪৬ আছে" দেখে বেচতে
 *      গিয়ে দেখা যেত ৬২টা আসলে ফ্রি, বিক্রির নয়।
 *   ২. বিক্রীত পণ্যের ব্যয় ভুল হত — ফ্রি মালের দাম শূন্য, কিন্তু গড় দরে
 *      মিশে গেলে প্রতিটা বিক্রির খরচ একটু করে কমে যেত।
 *   ৩. "কত ফ্রি দিলাম" প্রশ্নের উত্তর থাকত না, অথচ প্রস্তুতকারকের কাছে
 *      হিসাব দিতে ঠিক ওই সংখ্যাটাই লাগে।
 *
 * ── কেন সংরক্ষিত-ফ্রি আলাদা ──────────────────────────────────────────
 * অর্ডারে যেমন বিক্রির মাল ধরা পড়ে, তেমনি ফ্রি মালও ধরা পড়ে। দুইটা এক
 * ঘরে গুনলে একটা অর্ডারের ফ্রি অন্য অর্ডারের বিক্রয়যোগ্য থেকে কাটা যেত।
 *
 * পুরনো সারিগুলোয় শূন্য বসে — আগে ফ্রি বলে কিছু ছিল না, তাই শূন্যই সত্যি।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            // তাকে থাকা ফ্রি মাল
            $table->decimal('free_change', 18, 4)->default(0)->after('hold_change');

            // অর্ডারে ধরা ফ্রি মাল
            $table->decimal('free_reserved_change', 18, 4)->default(0)->after('free_change');
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['free_change', 'free_reserved_change']);
        });
    }
};
