<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ মাসশেষের সমন্বয় জাবেদা দাগসহ — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ঘ (৭ অক্টোবর ২০২৬)।
 *
 * ⓘ `vouchers.is_adjusting` — বকেয়া, অগ্রিম সরানো, অবচয়, প্রভিশন; JV সিরিজেই থাকে, কেবল দাগ। আজকের ভাউচারে `false`
 * (পুরনো কাগজ যেমন আছে থাকে)।
 * ⓘ `vouchers.reversal_of_id` — নিজে-উল্টো জাবেদা কোন আসলের ([[AdjustingReversals]]); (company_id, reversal_of_id) অনন্য, তাই
 * একটা আসল একবারই উল্টায় — দুই শিডিউলার একসাথে চললেও।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vouchers', 'is_adjusting')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->boolean('is_adjusting')->default(false)->after('reverse_on');
            });
        }

        if (! Schema::hasColumn('vouchers', 'reversal_of_id')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->unsignedBigInteger('reversal_of_id')->nullable()->after('is_adjusting');
                $table->unique(['company_id', 'reversal_of_id'], 'vouchers_reversal_of_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vouchers', 'reversal_of_id')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropUnique('vouchers_reversal_of_unique');
                $table->dropColumn('reversal_of_id');
            });
        }

        if (Schema::hasColumn('vouchers', 'is_adjusting')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropColumn('is_adjusting');
            });
        }
    }
};
