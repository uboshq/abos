<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * দুইটা সূচক — যেগুলোর অভাব আজ দেখা যায় না, আগামী বছর যাবে।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * নিরীক্ষায় মেপে দেখা: এই দুইটা টেবিলই সবচেয়ে দ্রুত বাড়ে, আর দুইটাতেই
 * হট কোয়েরিগুলো এমন ক্রমে কলাম চায় যা বিদ্যমান সূচকে নেই।
 *
 * ⚠️ লাইভে আজ ১৯.৯ মেগাবাইট, তাই পার্থক্যটা মাপাই যাবে না। ⛔ সেটাই
 * ফাঁদ: সূচক বসানোর সবচেয়ে ভালো সময় টেবিল ছোট থাকতে, আর সবচেয়ে খারাপ
 * সময় যখন পাতা আটকে যায় — তখন `ALTER TABLE` নিজেই মিনিট ধরে চলে।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── বিক্রয় চালান: (কোম্পানি, অবস্থা, তারিখ) ──────────────────
         *
         * ⓘ আছে `(company_id, customer_id, status)` — কিন্তু `status`
         * তৃতীয়, তাই "এই কোম্পানির সব খসড়া" খুঁজতে সেটা কাজে লাগে না:
         * MySQL বাঁদিক থেকে কলাম ধরে, আর মাঝেরটা বাদ দেওয়া যায় না।
         *
         * ⛔ যে কোয়েরিগুলো ভোগে (প্রতিটা ড্যাশবোর্ড লোডে চলে):
         *   · `SalesWidgets:84`  — খসড়া কয়টা
         *   · `SalesWidgets:165` — প্রথম চালানের তারিখ
         *   · `SalesInvoiceController:99,108` — অবস্থা ধরে ছেঁকে তারিখে সাজানো
         */
        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->index(['company_id', 'status', 'trx_date'], 'sal_inv_status_date');
        });

        /*
         * ── মজুদের চলাচল: (কোম্পানি, পণ্য, তারিখ) ────────────────────
         *
         * ⓘ আছে `(company_id, product_id, warehouse_id)` — তারিখের
         * আগেই থেমে যায়। ⛔ ফলে [[StockFacts]]-এর সহ-কোয়েরিগুলো
         * `(company_id, product_id)` ধরে পৌঁছে তারপর **ঐ পণ্যের গোটা
         * ইতিহাস স্ক্যান করে** তারিখের শর্তটা বসাতে।
         *
         * ⚠️ আর ওগুলো চলে **প্রতিটা পণ্যের জন্য**, মজুদ-বিশ্লেষণের
         * প্রতি পাতায় পাঁচবার।
         */
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->index(['company_id', 'product_id', 'trx_date'], 'inv_mov_product_date');
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->dropIndex('sal_inv_status_date');
        });

        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->dropIndex('inv_mov_product_date');
        });
    }
};
