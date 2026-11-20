<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ জমার খবর গৃহীত হলে রসিদ ভাউচার (RCV-…), আদায় (COL-…) নয় — মালিক, ২০ নভেম্বর ২০২৬: "টাকা ঢোকার কাগজ একটাই"।
 *
 *   · `voucher_id` — যে রসিদ ভাউচার এই খবরের টাকা খাতায় তুলল (খসড়া থাকলে সইয়ের অপেক্ষায়)।
 *   · ⓘ `collection_id` থাকে — আগের খবরগুলো তাদের পুরনো আদায়ের কাগজ দেখায়, ছাপে; নতুন খবরে আর বসে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            if (! Schema::hasColumn('sal_deposit_claims', 'voucher_id')) {
                $table->foreignId('voucher_id')->nullable()->after('collection_id')
                    ->constrained('vouchers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            if (Schema::hasColumn('sal_deposit_claims', 'voucher_id')) {
                $table->dropConstrainedForeignId('voucher_id');
            }
        });
    }
};
