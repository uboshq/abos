<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ভাউচারের সারিও বলতে পারে খরচটা কোন কেন্দ্রের।
 *
 * খতিয়ানে ঘরটা বসেছে আগের মাইগ্রেশনে। কিন্তু খতিয়ান লেখা হয় ভাউচার
 * থেকে, আর ভাউচারের সারিতে ঘরটা না থাকলে সেখান থেকে কিছুই পাঠানো যেত
 * না — অর্থাৎ খতিয়ানের ঘরটা চিরকাল খালি থাকত।
 *
 * সম্পাদনার সময়ও দরকার: ভাউচার আবার খুললে কোন সারি কোন কেন্দ্রের ছিল
 * সেটা এখান থেকেই ফেরে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table): void {
            $table->foreignId('cost_center_id')->nullable()->after('party_id')
                ->constrained('mdm_cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cost_center_id');
        });
    }
};
