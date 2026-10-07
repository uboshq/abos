<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাউন্টারের বিলের ছাড় বিলে পৌঁছাত না।
 *
 * ── ⛔ কী ভাঙা ছিল (পাঁচ-মিলের পরীক্ষা, ২৭ সেপ্টেম্বর ২০২৬) ────────────
 * পর্দা নিত ১,২৪২ − ৪০ (বিলের ছাড়) − ২ (রাউন্ডিং) = ১,২০০, অথচ বিলের মোট
 * থাকত ১,২৪২ — ছাড়টা কেবল চালানে বসত। ⚠️ ক্রেতা পর্দার অঙ্ক দিলে ৪২
 * টাকা বকেয়া নিয়ে বাড়ি যেতেন।
 *
 * ⓘ `discount` ঘরটা সারির ছাড়ের যোগফল — সারি বদলালেই নতুন করে গোনা হয়
 * ([[SalesInvoiceService::replaceLines()]])। ⛔ বিলের ছাড় সেখানে বসালে
 * প্রথম সারি-হালনাগাদেই মুছে যেত। তাই আলাদা ঘর, রাউন্ডিংয়ের পাশে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table) {
            $table->decimal('bill_discount', 18, 4)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table) {
            $table->dropColumn('bill_discount');
        });
    }
};
