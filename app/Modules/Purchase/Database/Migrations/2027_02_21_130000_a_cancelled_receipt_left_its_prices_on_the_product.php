<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ রসিদের সারি মনে রাখে পাকা হওয়ার আগে পণ্যের দাম কত ছিল — fe, ১৩ অক্টোবর ২০২৬ ([[PurchaseReceiptService::cancel()]])।
 *
 * ⓘ রসিদ পাকা হলে পণ্যের ক্রয়মূল্য আর (বলা থাকলে) বিক্রয়মূল্য রসিদের দরে বসে; বাতিলে আগের দাম ফেরানোর উপায় ছিল না — বাতিল রসিদের
 * দামই পণ্যে থেকে যেত। ⓘ দুটো ঘরই শূন্য-যোগ্য: আজকের সারিতে কিছু নেই, তাদের বাতিলে দাম যেমন আছে তেমনই থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pur_receipt_lines', 'prior_purchase_price')) {
            Schema::table('pur_receipt_lines', function (Blueprint $table): void {
                $table->decimal('prior_purchase_price', 18, 4)->nullable()->after('tax');
                $table->decimal('prior_sale_price', 18, 4)->nullable()->after('prior_purchase_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pur_receipt_lines', 'prior_purchase_price')) {
            Schema::table('pur_receipt_lines', function (Blueprint $table): void {
                $table->dropColumn(['prior_purchase_price', 'prior_sale_price']);
            });
        }
    }
};
