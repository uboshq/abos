<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ আদেশে কাটা টাকার কুপন বিলে পৌঁছাত না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (প্রমোশন ১৮; [[CouponDesk::carryToBill()]])।
 *
 * ⓘ বিলে কুপন কাটলে ছাড়টা ক্রেডিট নোট হয়ে খাতায় বসে ([[SalesCouponPapers::redeemed()]]); আদেশে কাটলে তখন আয় খাতায় নেই, তাই
 * কিছু বসত না — আর আদেশ বিল হওয়ার পরেও না। গ্রাহক ছাড় পেলেন বলে কুপন খরচ হলো, অথচ তাঁর পাওনা কমল না।
 *
 * ⭐ এখন আদেশের বিল পাকা হলে কুপনের ছাড় সেই বিলের ক্রেডিট নোটে যায়। একটা আদেশ কয়েকটা বিলে ভাগ হতে পারে, তাই প্রতিটা ব্যবহার
 * মনে রাখে কতটা ইতিমধ্যে বিলে গেছে — একই ছাড় দুইবার নয়, আর বিলের অঙ্কের বেশি নয় (বাকিটা পরের বিলে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_coupon_redemptions', function (Blueprint $table) {
            $table->decimal('carried_amount', 18, 4)->default(0)->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_coupon_redemptions', function (Blueprint $table) {
            $table->dropColumn('carried_amount');
        });
    }
};
