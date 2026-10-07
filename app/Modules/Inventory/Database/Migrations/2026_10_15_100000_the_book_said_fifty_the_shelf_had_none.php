<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মাল গোনা — খাতায় যা লেখা, তাকে যা সত্যিই আছে।
 *
 * নগদ গণনার (cash_counts) হুবহু যমজ, শুধু টাকার বদলে মাল। মিলে গেলে কোনো
 * সমন্বয় হয় না, শুধু রেকর্ড থাকে যে ওই দিন গোনা হয়েছিল। না মিললে
 * পার্থক্যটা পরে (অনুমোদনে) একটা স্টক-সমন্বয় হয়ে বসে — কারণ খাতার
 * সংখ্যাটা তখন মিথ্যা, আর মিথ্যা রেখে দিলে পরের গণনাও ভুল হবে।
 *
 * ── দুইটা টেবিল কেন ──────────────────────────────────────────────────
 * একটা গণনায় বহু পণ্য গোনা হয়, তাই হেডার + লাইন। হেডার বলে কোন গুদাম,
 * কবে, কে গুনল; প্রতিটা লাইন একটা পণ্যের book_qty (গণনার মুহূর্তে খাতার
 * সংখ্যার snapshot), counted_qty (হাতে পাওয়া) আর difference।
 *
 * ── সবচেয়ে বিপজ্জনক সিদ্ধান্ত: গোনা-হয়নি ≠ শূন্য ────────────────────
 * লাইন বসে **কেবল যে পণ্য গণনাকারী সত্যিই গোনেন**। তালিকায় নেই মানে "গোনা
 * হয়নি", "নেই" নয়। তাই অনুমোদন কেবল line-আছে পণ্যকেই ছোঁয় — গোটা গুদাম
 * শূন্য করে দেওয়ার ভুলটা এখানেই, স্কিমাতেই আটকানো। cycle count (কম পণ্যের
 * গণনা) আর full count একই আকারে ধরা পড়ে, আলাদা ধরন লাগে না — পার্থক্য
 * শুধু কয়টা লাইন বসল।
 *
 * ── book_qty কেন লাইনে জমানো, চলতি হিসাব থেকে নয় ─────────────────────
 * গণনাকারী এক মুহূর্তে গোনেন; ওই মুহূর্তের খাতার সংখ্যার সাথে মেলানোই সৎ
 * পার্থক্য। অনুমোদন হয়তো পরদিন — ততক্ষণে মাল নড়ে গেছে। snapshot না রাখলে
 * "গণনার সময় পার্থক্য কত ছিল" প্রশ্নের উত্তর হারিয়ে যেত। (বাস্তব সমন্বয়
 * তবু অনুমোদনের মুহূর্তের চলতি floor থেকেই গোনা হয় — StockAdjustmentService
 * তা-ই করে — তাই দুই তারিখের মাঝের নড়াচড়া হারায় না।)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 64);
            $table->date('count_date');

            // একটা গণনা একটা গুদামের — নেত্রকোনার তাক আর ময়মনসিংহের খাতা
            // মেলানোর কোনো মানে নেই। restrict, কারণ গণনার ইতিহাস গুদামের
            // চেয়েও বেশি দিন থাকা দরকার।
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();

            $table->string('narration', 500)->nullable();
            $table->string('status', 16)->default('draft');

            // যিনি গুনলেন আর যিনি অনুমোদন করলেন — দুইজনের নামই, নগদ গণনার মতো
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'warehouse_id', 'count_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('inv_stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('stock_count_id')->constrained('inv_stock_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            // book_qty — গণনার মুহূর্তে খাতার সংখ্যার snapshot (উপরের টীকা)
            $table->decimal('book_qty', 18, 4);
            $table->decimal('counted_qty', 18, 4);
            // counted − book; ঋণাত্মক মানে খাতার চেয়ে কম পাওয়া গেছে
            $table->decimal('difference', 18, 4);

            // পার্থক্যের টাকা দেখাতে গণনার মুহূর্তের একক খরচ; নেই থাকতে পারে
            $table->decimal('unit_cost', 18, 4)->nullable();

            // কেন পার্থক্য — অনুমোদনের সময় ভরে (মাস্টার তালিকা থেকে, মুক্ত লেখা নয়)
            $table->foreignId('reason_code_id')->nullable()->constrained('mdm_reason_codes')->nullOnDelete();

            $table->timestamps();

            // এক গণনায় এক পণ্য একবারই — নাহলে যোগফল দ্বিগুণ গুনত
            $table->unique(['stock_count_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_count_lines');
        Schema::dropIfExists('inv_stock_counts');
    }
};
