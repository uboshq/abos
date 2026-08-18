<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিক্রয়মূল্য ঠিক হয় মাল আসার মুহূর্তে।
 *
 * ── কী বাদ পড়েছিল ───────────────────────────────────────────────────
 * ক্রয় বিলে ও Direct Receive-এ ক্রয়দরের পাশে বিক্রয়মূল্যের ঘরটা ছিল
 * (markup ও margin সহ), কিন্তু **মাল বুঝে নেওয়ার পর্দায় ছিল না** —
 * অথচ ওখানেই মালটা সত্যিকারে গুদামে ঢোকে।
 *
 * ডিপোতে চালান আসে আগে, বিল আসে পরে — কখনো দিন সাতেক পরে। ততক্ষণ
 * মালটা তাকে থাকে পুরনো দামে, আর নতুন দরে কেনা মাল পুরনো দরে বিক্রি
 * হয়ে যায়। ৪% মার্জিনের ব্যবসায় ওই কয়দিনেই মুনাফাটা মুছে যেতে পারে।
 *
 * তাই ঘরটা তিন পথেই এক রকম: **ক্রয়দর → markup % → margin % →
 * বিক্রয়মূল্য**।
 *
 * ── nullable কেন ─────────────────────────────────────────────────────
 * খালি রাখা মানে "দাম বদলাচ্ছি না" — পণ্যের আগের দামটাই টিকে থাকে।
 * শূন্য বসালে সেটা একটা দাম হয়ে যেত, আর প্রথম বিক্রিতেই পুরোটা লোকসান
 * দেখাত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_receipt_lines', function (Blueprint $table) {
            $table->decimal('sales_price', 18, 4)->nullable()->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('pur_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('sales_price');
        });
    }
};
