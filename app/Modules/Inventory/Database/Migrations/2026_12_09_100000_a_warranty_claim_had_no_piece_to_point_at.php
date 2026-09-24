<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ওয়ারেন্টির দাবি এল, আর দেখানোর মতো কোনো পিস ছিল না।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Serial Number · Item · Purchase · Supplier · Warehouse · Customer ·
 * Sales Invoice · Warranty Start · Warranty End · Current Status ·
 * Service History"* — ইলেকট্রনিক্স ও ওয়ারেন্টিওয়ালা পণ্যের জন্য।
 *
 * ── ⓘ লট থাকতে সিরিয়াল কেন ──────────────────────────────────────────
 * লট একটা **দল** — একসাথে আসা পঞ্চাশ বস্তা, একটাই মেয়াদ। ⚠️ ওতে
 * রিকলের প্রশ্নের উত্তর মেলে (*"ঐ চালানটা কোথায় গেল"*), ⛔ কিন্তু
 * ওয়ারেন্টির প্রশ্নের নয়: *"এই একটা পিস কবে কার কাছে গেল"*।
 *
 * ⓘ একটা টিভির সিরিয়াল নম্বর একটাই, আর দাবিটা ঐ একটা পিসের। ⛔ লট
 * দিয়ে উত্তর দিতে গেলে বলতে হত *"এই পঞ্চাশটার কোনো একটা"*, আর ওটা
 * কোনো উত্তর নয়।
 *
 * ── ⚠️ মালিকের সিদ্ধান্ত: পণ্যে একটা টিক, বৈশ্বিক মোড নয় ─────────────
 * ⛔ সব পণ্যে সিরিয়াল চাইলে চাল-ডালের প্রতিটা বস্তার নম্বর বসাতে হত,
 * আর গুদাম থেমে যেত। ⓘ টিক না থাকলে ঐ পণ্যের কিছুই বদলায় না —
 * `track_batch` ও `qc_required`-এর হুবহু যমজ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();

            /* ⓘ যে লটে এসেছিল — লট ধরা পণ্যে দুইটাই থাকে, বিরোধ নেই */
            $table->foreignId('batch_id')->nullable()
                ->constrained('inv_batches')->nullOnDelete();

            /*
             * ⭐ নম্বরটা — আর এটাই একমাত্র জিনিস যা দুইবার থাকতে পারে না।
             *
             * ⛔ একই নম্বর দুইবার বসলে ওয়ারেন্টির দাবিতে দুইটা কাগজ
             * বেরোত, আর কোনটা সত্যি তা বলার উপায় থাকত না। ⚠️ শর্তটা
             * কোম্পানি ধরে, বৈশ্বিক নয়: দুইটা কোম্পানির দুইটা পণ্যের
             * নম্বর মিলে যেতেই পারে, আর সেটা কারও ভুল নয়।
             */
            $table->string('serial_no', 80);

            $table->foreignId('warehouse_id')->nullable()
                ->constrained('inv_warehouses')->nullOnDelete();

            /*
             * ⓘ কোথা থেকে এল, আর কোথায় গেল — দুইটাই আলগা সূত্র।
             *
             * ⚠️ `purchase_receipt_id` আর `sales_invoice_id` বসানোর লোভটা
             * বড়, ⛔ কিন্তু তাতে Inventory দুইটা মডিউলের উপর দাঁড়াত,
             * অথচ মালিকের সীমানার টেবিলে সিরিয়াল **Inventory-র**।
             *
             * ⓘ চলাচলের সারিও ঠিক এভাবেই তার উৎস মনে রাখে।
             */
            $table->string('in_source_type', 60)->nullable();
            $table->unsignedBigInteger('in_source_id')->nullable();
            $table->date('received_on')->nullable();

            $table->string('out_source_type', 60)->nullable();
            $table->unsignedBigInteger('out_source_id')->nullable();
            $table->date('issued_on')->nullable();

            /*
             * ⚠️ গ্রাহকের নামটা **লেখা থাকে**, সম্পর্ক নয়।
             *
             * ⓘ একই কারণে: Customer মডিউলের উপর দাঁড়ালে সীমানা উল্টাত।
             * ⛔ আর ওয়ারেন্টির কাগজে যা লাগে সেটা নামটাই — গ্রাহক
             * মুছে গেলেও দাবিটা টেকে।
             */
            $table->string('sold_to', 200)->nullable();

            $table->date('warranty_from')->nullable();
            $table->date('warranty_to')->nullable();

            $table->string('status', 20);
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * ⛔ `char(36)`, ২৬ নয় — ২৪ সেপ্টেম্বর ২০২৬-এ মেপে ধরা।
             *
             * ⚠️ প্রথমে `string('public_id', 26)` লেখা হয়েছিল, আর
             * [[HasPublicId]] বসায় একটা **UUID v7** — ৩৬ অক্ষর। ⓘ ফল:
             * প্রতিটা সেভে `SQLSTATE[22001] Data too long`, অর্থাৎ
             * পর্দায় ৫০০।
             *
             * ⭐ বাকি প্রতিটা টেবিলে ঘরটা `char(36)`; নতুন টেবিল
             * বানানোর সময় ওটাই দেখে নেওয়া উচিত ছিল।
             */
            $table->char('public_id', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();

            /*
             * ⚠️ সূচকের নামগুলো হাতে দেওয়া — টেবিলের নামটাই ১৮ অক্ষর,
             * আর Laravel-এর বানানো নাম দুইয়ের বেশি কলামে ৬৪ ছাড়াত।
             * ⛔ ৬৪ ছাড়ালে প্রতিটা সেশনে `migrate:fresh` ভাঙে।
             */
            $table->unique(['company_id', 'serial_no'], 'inv_serial_once');
            $table->index(['company_id', 'product_id'], 'inv_serial_item');
            $table->index(['company_id', 'status'], 'inv_serial_state');
            $table->unique('public_id', 'inv_serial_public');
        });

        Schema::table('inv_products', function (Blueprint $table) {
            /*
             * ⓘ `qc_required`-এর ঠিক পাশে, একই কারণে — তিনটাই একই
             * জাতের সিদ্ধান্ত: এই পণ্যে বাড়তি হিসাব রাখা হবে কি না।
             */
            $table->boolean('track_serial')->default(false)->after('qc_required');
        });
    }

    public function down(): void
    {
        Schema::table('inv_products', function (Blueprint $table) {
            $table->dropColumn('track_serial');
        });

        Schema::dropIfExists('inv_serial_numbers');
    }
};
