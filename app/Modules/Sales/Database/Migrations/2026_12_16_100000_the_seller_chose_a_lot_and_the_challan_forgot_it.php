<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিক্রেতা লট বাছলেন, আর চালান সেটা মনে রাখল না।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৫ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * লট ধরা পণ্যে লট বাছা **বাধ্যতামূলক**। ⓘ তাঁকে দুইটা বিকল্প দেওয়া
 * হয়েছিল — না বাছলে FEFO চলবে, নাকি বাছা বাধ্যতামূলক — আর তিনি
 * দ্বিতীয়টা বেছেছেন।
 *
 * ── ⛔ ঘরটা না থাকলে বাছাইটা হারাত ──────────────────────────────────
 * ⓘ কাউন্টার লটটা পাঠাত, সেবা যাচাই করত, আর তারপর সারিটা চালানে বসত
 * **লট ছাড়া**। ⚠️ মাল বেরোনোর সময় [[StockService::issue()]] আবার
 * FEFO চালাত — অর্থাৎ বিক্রেতার বাছাইটা নীরবে উপেক্ষা হত, আর কোথাও
 * কিছু ভাঙত না।
 *
 * ⛔ ক্ষতিটা কেবল রিকলের দিন ধরা পড়ত: কাগজে এক লট, গুদাম থেকে গেছে
 * অন্যটা।
 *
 * ── ⓘ nullable, আর সেটাই ঠিক ────────────────────────────────────────
 * ডিপোর চাল-ডাল-সাবানে লট ধরা নেই, আর ঐ সারিগুলো আগের মতোই খালি ঘরে
 * বসে। ⚠️ আর পুরনো প্রতিটা চালানের সারিও খালি — সেগুলোর লট
 * চলাচলের সারিতে লেখা আছে, আর পিছনে ফিরে বসানোর কোনো উপায় নেই।
 *
 * ⓘ `nullOnDelete` নয়, `restrictOnDelete` — ⛔ লট মুছে ফেললে চালানের
 * সারিটা চুপচাপ "কোন লট জানা নেই" হয়ে যেত, আর সেটাই ঠিক সেই সুতোটা
 * ছিঁড়ত যার জন্য লট রাখা হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challan_lines', function (Blueprint $table): void {
            /*
             * ⚠️ নামটা হাতে দেওয়া, আর `scl_batch_fk` **নয়** — ⛔ ঐ নামটা
             * একই দিনে `inv_stock_count_lines`-এ বসেছে (abos-3c), আর
             * InnoDB-তে ফরেন কি-র নাম **গোটা ডাটাবেজে** অনন্য হতে হয়।
             *
             * ⓘ সংঘর্ষটা এখানে নয়, **যার মাইগ্রেশন পরে চলবে তার
             * ওখানে** ভাঙত — আর বার্তাটা এমন একটা টেবিলের নাম বলত
             * যার সাথে ভুলটার কোনো সম্পর্ক নেই।
             *
             * ⚠️ সংক্ষিপ্ত নাম দেওয়ার অভ্যাসটা এসেছে অন্য কারণে: ৬৪
             * অক্ষর পেরোনো নামে `migrate:fresh` প্রত্যেকের মেশিনে ভাঙে।
             */
            $table->foreignId('batch_id')->nullable()->after('product_id')
                ->constrained('inv_batches', indexName: 'sal_chl_batch_fk')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sal_challan_lines', function (Blueprint $table): void {
            $table->dropForeign('sal_chl_batch_fk');
            $table->dropColumn('batch_id');
        });
    }
};
