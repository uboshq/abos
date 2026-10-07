<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একই ঘাটতি কয়েকবার খাতায় — Inventory অডিট গ৭, ৪ অক্টোবর ২০২৬।
 *
 * ⛔ সমন্বয় সইয়ে আটকালে বা ভুল হলে একটা খসড়া গণনা পড়ে থাকত, আর আবার চাপলে আরেকটা — অনুমোদনকারী পরে সবগুলো
 * মানলে একই ঘাটতি বারবার বসত। ⓘ এখন একই পণ্যের দ্বিতীয় খসড়া হয় না ([[StockCountService::record()]]), আর পড়ে থাকা
 * খসড়াটা কারণসহ বাতিল করা যায় — এই তিনটা ঘর সেই বাতিলের: কে, কখন, কেন।
 *
 * ⓘ MariaDB-নিরাপদ: কেবল ADD COLUMN আর একটা FK, নাম হাতে দেওয়া, ৬৪ অক্ষরের অনেক নিচে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_counts', function (Blueprint $table) {
            $table->string('cancel_reason', 500)->nullable()->after('approved_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancel_reason')
                ->constrained('users', indexName: 'inv_stock_counts_cancelled_by_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_counts', function (Blueprint $table) {
            $table->dropForeign('inv_stock_counts_cancelled_by_fk');
            $table->dropColumn(['cancel_reason', 'cancelled_by', 'cancelled_at']);
        });
    }
};
