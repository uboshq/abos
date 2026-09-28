<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ফেরতের কারণ বসত কেবল কেউ কষ্ট করে বাছলে।
 *
 * ── ⛔ যা ছিল ─────────────────────────────────────────────────────────
 * `sal_returns.reason_code_id` ছিল, কিন্তু ঐচ্ছিক — আর কাউন্টারের ফেরতের
 * পর্দায় ঘরটাই ছিল না। ফলে "কোন কারণে সবচেয়ে বেশি ফেরত আসে" প্রশ্নের
 * উত্তরে বেশিরভাগ সারি "কারণ নেই"।
 *
 * ── ⭐ এই মাইগ্রেশন যা বসায় ──────────────────────────────────────────
 *   · হেডারে `reason_note` — "অন্যান্য" বাছলে যা লেখা হয়
 *   · লাইনে নিজের কারণ ও নোট — একই ফেরতে দুই বস্তা নষ্ট, একটা ভুল মাল
 *   · লাইনে `batch_id` — মেয়াদোত্তীর্ণ মাল কোন লটের, সেটা ছাড়া
 *     সরবরাহকারীর কাছে দাবি হয় না
 *
 * ⓘ সবগুলোই nullable: পুরনো ফেরতগুলো যেমন ছিল তেমনই থাকে, আর লাইনের
 * কারণ খালি মানে "হেডারের কারণই"। নিয়মটা বসে সেবায়
 * ([[SalesReturnReasonGuard]]), ডাটাবেজে নয় — কারণ "অন্যান্য হলে নোট"
 * শর্তটা কোনো কলামের NOT NULL দিয়ে বলা যায় না।
 *
 * ⚠️ ফরেন কি-র নাম হাতে দেওয়া আর ছোট: ৬৪ অক্ষর পেরোলে `migrate:fresh`
 * সবার মেশিনে ভাঙে, আর InnoDB-তে নামটা গোটা ডাটাবেজে অনন্য হতে হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_returns', function (Blueprint $table): void {
            $table->string('reason_note', 500)->nullable()->after('reason_code_id');
        });

        Schema::table('sal_return_lines', function (Blueprint $table): void {
            /*
             * ⓘ `restrictOnDelete` — কারণ-কোড soft-delete হয়, তাই সাধারণ
             * মোছায় এটা কখনো বাধে না; ⛔ কিন্তু কেউ জোর করে মুছলে ফেরতের
             * সারিটা চুপচাপ "কারণ নেই" হয়ে যেত না।
             */
            $table->foreignId('reason_code_id')->nullable()->after('to_hold')
                ->constrained('mdm_reason_codes', indexName: 'sal_rtl_reason_fk')
                ->restrictOnDelete();

            $table->string('reason_note', 500)->nullable()->after('reason_code_id');

            // লট মুছলে সারিটা "কোন লট জানা নেই" হয়ে যেত — রিকলের সুতোটাই ছিঁড়ত
            $table->foreignId('batch_id')->nullable()->after('product_id')
                ->constrained('inv_batches', indexName: 'sal_rtl_batch_fk')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sal_return_lines', function (Blueprint $table): void {
            $table->dropForeign('sal_rtl_reason_fk');
            $table->dropForeign('sal_rtl_batch_fk');
            $table->dropColumn(['reason_code_id', 'reason_note', 'batch_id']);
        });

        Schema::table('sal_returns', function (Blueprint $table): void {
            $table->dropColumn('reason_note');
        });
    }
};
