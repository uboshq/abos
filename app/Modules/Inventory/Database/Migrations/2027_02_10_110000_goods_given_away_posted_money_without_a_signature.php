<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "মাল বের করা" সই ছাড়াই খাতায় টাকা বসাত — Inventory অডিট গ৫, ৪ অক্টোবর ২০২৬।
 *
 * ⛔ আপ্যায়ন, উপহার বা মালিকের ব্যবহারে মাল বের করলে খরচের খাতে টাকা উঠত, অথচ কোনো সই লাগত না — মালিকের
 * নিয়ম "যেকোনো টাকা, যেকোনো অঙ্কে সই" ভাঙত। ⓘ এখন বের করা একটা অপেক্ষমাণ কাগজ: গণনার কাগজেরই আরেক ধরন
 * (`kind = issue`), কারণটা কাগজেই লেখা — সইকারী যা দেখে সই দেন, শেষে ঠিক সেই কারণের খাতেই বসে।
 *
 * ⓘ MariaDB-নিরাপদ: কেবল ADD COLUMN, একটা FK আর একটা সূচক; নাম হাতে দেওয়া, ৬৪ অক্ষরের অনেক নিচে।
 * পুরনো সারি সবই গণনা — ডিফল্ট `count`।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_counts', function (Blueprint $table) {
            $table->string('kind', 16)->default('count')->after('document_no');
            $table->foreignId('reason_code_id')->nullable()->after('narration')
                ->constrained('mdm_reason_codes', indexName: 'inv_stock_counts_reason_fk')->restrictOnDelete();

            $table->index(['company_id', 'kind', 'status'], 'inv_stock_counts_kind');
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_counts', function (Blueprint $table) {
            $table->dropForeign('inv_stock_counts_reason_fk');
            $table->dropIndex('inv_stock_counts_kind');
            $table->dropColumn(['kind', 'reason_code_id']);
        });
    }
};
