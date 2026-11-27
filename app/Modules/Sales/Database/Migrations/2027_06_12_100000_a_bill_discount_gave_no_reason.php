<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বিলের ছাড়ের কারণ — নতুন সরাসরি বিক্রয়ের পর্দা (মালিকের অনুমোদিত নমুনা, ২৭–২৮ নভেম্বর ২০২৬; NEXUS-এর ধাপ A-র সাথে মিল):
 *   বিলের ছাড়ের পাশে 📝 চাপলে "ছাড়ের কারণ" — কাগজে ছাপা হয়, অডিটে থাকে। ⓘ চালানে লেখা হয়, বিলে যায় (ছাড়ের মতোই)।
 * ⓘ ৩০০ অক্ষর পর্যন্ত; ছাড় না থাকলে কারণ জমা হয় না (null)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_challans', function (Blueprint $table) {
            $table->string('discount_reason', 300)->nullable()->after('discount_amount');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('discount_reason', 300)->nullable()->after('bill_discount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropColumn('discount_reason'));
        Schema::table('delivery_challans', fn (Blueprint $table) => $table->dropColumn('discount_reason'));
    }
};
