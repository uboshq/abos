<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * রাখা খসড়া বিল খুলতে গেলে কাউন্টার ফাঁকা পর্দা দেখাত।
 *
 * ── ⭐ মালিকের নকশা, ২৬ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * *"etokkhon bill kore rakhlo ta save thakbe … sudu challan inv print hobe
 * na approval e zabe na. conf. korte hole abaer ei skinei aste hobe"*।
 *
 * ⓘ খসড়া বিল আর খসড়া চালান আগেও লেখা হত, কিন্তু পর্দার অনেক কিছু তাদের
 * কোনো ঘরে বসে না — কোন সারির ছাড় শতাংশে লেখা ছিল, কোন জমা কোন হিসাবে,
 * গোল করার দিক। ⚠️ কাগজ থেকে পর্দা আবার বানাতে গেলে এগুলো অনুমানে ভরতে
 * হত, আর অনুমানে ভরা বিল পাকা করা মানে অন্য একটা বিল পাকা করা।
 *
 * ⭐ তাই পর্দাটা হুবহু সংরক্ষিত থাকে — যে ছবিটা কাউন্টার ব্রাউজারে রাখে
 * ([[direct-sale.js]] `screenSnapshot`), সেটাই। ⓘ খালি মানে এটা কাউন্টারের
 * রাখা খসড়া নয় (সাধারণ বিল, বা সইয়ের অপেক্ষায় থাকা পুরনো পথের খসড়া)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table) {
            $table->json('counter_draft')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table) {
            $table->dropColumn('counter_draft');
        });
    }
};
