<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * চালকের নাম ছিল, নম্বর ছিল না — মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (রাত)।
 *
 * *"চালকের নাম … tarpase Mobile no. চালকের নাম & Mobile no ekbar save korle
 * porbortite sajest korbe"* — মাল পথে আটকালে প্রথম ফোনটা চালককেই যায়, আর
 * নম্বরটা কাগজে না থাকলে কাউন্টারে কেউ জানেন না।
 *
 * ⓘ আলাদা চালক-তালিকা নয়: পরামর্শ আসে এই কোম্পানির আগের চালান আর গাড়ির
 * মাস্টার থেকে ([[DirectSaleController]]) — একবার লিখলেই পরের বার আসে, কাউকে
 * আলাদা করে তালিকা সাজাতে হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            $table->string('driver_phone', 32)->nullable()->after('driver_name');
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            $table->dropColumn('driver_phone');
        });
    }
};
