<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * গুনে পাওয়া বাড়তি মালের ঢোকার মতো কোনো লট ছিল না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"bosaw"* — সমন্বয়ের পর্দায় লটের ঘর বসানোর সিদ্ধান্ত, নতুন
 * বাধ্যতামূলক ঘরটার খরচ জেনেশুনেই।
 *
 * ── ⛔ কেন ঘরটা সারিতে, কাগজে নয় ────────────────────────────────────
 * একটা গণনার কাগজে বহু পণ্য থাকে, আর লট পণ্যের জিনিস — কাগজের নয়।
 * ⚠️ কাগজে একটা লট নম্বর রাখলে ওটা সব সারিতে খাটত, অথচ দুইটা পণ্যের
 * লট নম্বর কখনোই এক নয়।
 *
 * ── ⓘ nullable, আর সেটাই ঠিক ────────────────────────────────────────
 * তিন রকম সারিতে ঘরটা খালিই থাকবে, আর তিনটাই বৈধ:
 *
 *   ১. যে পণ্যে লট ধরা হয় না — চাল, ডাল, সাবান
 *   ২. যে সারিতে গুনে **কম** পাওয়া গেছে — কোন লট থেকে যাবে তা
 *      মানুষ বলে না, FEFO বলে ([[StockAdjustmentService]])
 *   ৩. যে সারিতে খাতা আর তাক মিলে গেছে — কোনো চলাচলই হয় না
 *
 * ⛔ বাধ্যতামূলক করলে প্রথম দলটায় একটা বানানো লট নম্বর বসত, আর বানানো
 * লট রিকলের খাতায় একটা মিথ্যা সারি।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_count_lines', function (Blueprint $table) {
            /*
             * ⚠️ সূচকের নামটা হাতে দেওয়া, আর সেটা বাধ্য হয়ে।
             *
             * ⓘ Laravel নিজে নাম বানালে হত
             * `inv_stock_count_lines_batch_id_foreign` — ৪০ অক্ষর, ওটা
             * চলে। ⛔ কিন্তু এই খাতায় আগে ৬৪ অক্ষরের সীমা পেরোনো নাম
             * `migrate:fresh` ভেঙেছে, তাই ছোট নাম দেওয়াটাই এখানকার
             * অভ্যাস।
             */
            $table->foreignId('batch_id')->nullable()->after('product_id')
                ->constrained('inv_batches', indexName: 'scl_batch_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_count_lines', function (Blueprint $table) {
            $table->dropForeign('scl_batch_fk');
            $table->dropColumn('batch_id');
        });
    }
};
