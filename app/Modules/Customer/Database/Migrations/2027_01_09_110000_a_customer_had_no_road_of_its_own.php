<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * গ্রাহকের বিক্রয়-পথ।
 *
 * ⓘ nullable, আর পুরনো সারিতে কিছু বসানো হয় না: কোন গ্রাহক কোন পথে কেনেন,
 * সেটা কোম্পানিই জানে। ⛔ পক্ষের ধরন থেকে আন্দাজ করে বসালে রিপোর্টে
 * একটা সংখ্যা দেখা যেত যেটা কেউ কোনোদিন বলেনি — খালি থাকলে রিপোর্ট
 * সৎভাবে "পথ বসানো হয়নি" দেখায়, আর সেটাই কাজের তালিকা।
 *
 * ⚠️ `nullOnDelete` — তবু কাজে লাগা পথ কখনো মোছে না: মাস্টার তালিকার
 * "মুছুন" আগে দেখে কেউ সারিটার দিকে দেখাচ্ছে কি না, আর দেখালে কেবল
 * নিষ্ক্রিয় করে ([[MasterListService::delete()]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('channel_id')->nullable()->after('party_type_id')
                ->constrained('mdm_sales_channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('channel_id');
        });
    }
};
