<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * আদায়ের ব্যাংক চার্জ — টাকার পরিকল্পনা ৫ (মালিক, ৭ অক্টোবর ২০২৬; সমন্বয়ক, ১২ অক্টোবর ২০২৬)।
 *
 * ⓘ ডিলার ৫০,০০০ পাঠালেন, ব্যাংকে এল ৪৯,৯৫০। আগে গ্রহণে অঙ্ক ৪৯,৯৫০ করলে ডিলারের পাওনাও ৪৯,৯৫০ কমত — ৫০ টাকা তাঁর
 * নামে বকেয়া থেকে যেত, অথচ তিনি পুরোটাই পাঠিয়েছেন। এখন `amount` ডিলারের পাওনা থেকে যা কমে (৫০,০০০), আর `bank_charge`
 * তার যে অংশ ব্যাংক কেটে রেখেছে (৫০) — খাতায় ব্যাংক ৪৯,৯৫০ Dr, ব্যাংক চার্জ ৫০ Dr, ডিলারের পাওনা ৫০,০০০ Cr।
 * ⓘ আগের আদায়গুলো শূন্য চার্জে — যেমন ছিল তেমনই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_collections', function (Blueprint $table) {
            $table->decimal('bank_charge', 18, 4)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('sal_collections', function (Blueprint $table) {
            $table->dropColumn('bank_charge');
        });
    }
};
