<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ সরবরাহকারীর সংক্ষিপ্ত নাম — মালিক, ৬ অক্টোবর ২০২৬।
 *
 * *"SUP-0002 = code দেওয়ার দরকার নাই — Star Line Food Products Ltd. = full নাম না লিখে short name লেখার ব্যবস্থা করো,
 * supplier edit-এ একটা box দাও"*। ড্যাশবোর্ডের সরু ঘরে আর রিপোর্টে পুরো নামের বদলে এটা দেখায় ("Star Line")।
 *
 * ⓘ ঐচ্ছিক — খালি থাকলে নামটাই (বাংলা, না থাকলে ইংরেজি); কোড কখনো নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('short_name', 60)->nullable()->after('name_bn');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('short_name');
        });
    }
};
