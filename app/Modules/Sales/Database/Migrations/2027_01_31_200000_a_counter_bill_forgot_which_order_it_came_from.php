<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * কাউন্টারের বিল মনে রাখত না সে কোন কাগজ থেকে এসেছে — বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ+চ।
 *
 * ⭐ ডিপোর যাচাই থেকে DO কাউন্টারে খোলে ([[CounterSaleSource]]); বিক্রেতা "খসড়া রাখুন" চাপলে, বা সই লাগলে, বিক্রি
 * খসড়া থাকে আর পাকা হয় পরে — তখন উৎসটা "বিল হয়েছে" লেখার জন্য খসড়া বিলকে উৎস মনে রাখতে হয়।
 *
 * ⓘ চাবি (`do`) আর আইডি — উৎসের ধরন কাউন্টার জানে না, তাই বিদেশি চাবি (FK) নয়। ⚠️ সূচকের নাম ছোট (৬৪-র নিচে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->string('counter_source', 16)->nullable();
            $table->unsignedBigInteger('counter_source_id')->nullable();

            $table->index(['company_id', 'counter_source', 'counter_source_id'], 'sal_inv_counter_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->dropIndex('sal_inv_counter_source_idx');
            $table->dropColumn(['counter_source', 'counter_source_id']);
        });
    }
};
