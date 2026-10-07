<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অফারের ছাড় — চালানের সারিতে, আর সেখান থেকে বিলের সারিতে (অডিট §১১, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⓘ কেন নতুন ঘর, `discount_percent` নয় ─────────────────────────────
 * অফারের সুবিধা টাকায় আসে ([[PromotionDesk::apply()]] `worth` জমায়)। ⛔ শতাংশে
 * নামালে পয়সা হারাত — ১,০০০-এর উপর ৩৩.৩৩ টাকা কোনো চার-ঘরের শতাংশে হুবহু বসে না,
 * আর বিলের অঙ্ক আর অফারের খাতার অঙ্ক এক পয়সা আলাদা হত।
 *
 * ── ⓘ বিলের সারিতেও কেন ─────────────────────────────────────────────
 * বিলের `discount` = মানুষের নিজের ছাড় + অফারের ভাগ। ⚠️ ভাগটা আলাদা না রাখলে খসড়া
 * বিল আবার সংরক্ষণে পর্দা পুরো ছাড়টা ফেরত পাঠাত আর অফারের ভাগ দ্বিতীয়বার যোগ হত
 * ([[ChallanOfferShare]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challan_lines', function (Blueprint $table): void {
            $table->decimal('promotion_discount', 18, 4)->default(0)->after('discount_percent');
        });

        Schema::table('sal_invoice_lines', function (Blueprint $table): void {
            $table->decimal('promotion_discount', 18, 4)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn('promotion_discount');
        });

        Schema::table('sal_challan_lines', function (Blueprint $table): void {
            $table->dropColumn('promotion_discount');
        });
    }
};
