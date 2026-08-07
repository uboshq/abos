<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মালের দাম — যে চালানে ঢুকেছিল, সেই দামেই বেরোবে (FIFO)।
 *
 * ── কেন এই দুইটা টেবিল দরকার হলো ────────────────────────────────────
 * এতদিন বিক্রয়ের সময় খরচের দর নেওয়া হত পণ্য-মাস্টারে লেখা ক্রয়মূল্য
 * থেকে, আর মাল খতিয়ানে ঢুকত চালানের আসল দরে। দুইটা কখনোই এক হয় না।
 * চালিয়ে দেখা গেছে: ১,০০০ টাকার ১০ বস্তার ৪টা বেচে মজুদ খাত থেকে
 * ১৩,৬০০ বেরিয়ে গেছে, আর খাতটা ঋণাত্মক হয়ে বসে আছে — যা একটা গুদাম
 * ধরে রাখতে পারে না।
 *
 * মালিকের সিদ্ধান্ত: **FIFO**। অর্থাৎ প্রতিটা চালান নিজের দাম নিয়ে
 * আলাদা স্তর হয়ে থাকে, আর যেটা আগে ঢুকেছে সেটাই আগে বেরোয়।
 *
 * ── কেন দুইটা টেবিল, একটা নয় ────────────────────────────────────────
 * স্তরে কেবল "কত বাকি" রাখলে সংখ্যাটা ঠিক থাকত, কিন্তু প্রশ্নের উত্তর
 * থাকত না: এই বিক্রয়ের ৫৫০ টাকা খরচ কোথা থেকে এল? দ্বিতীয় টেবিলটা
 * প্রতিটা টান আলাদা করে লেখে — কোন স্তর, কতটা, কোন দরে। তাতে যেকোনো
 * খরচের অঙ্ক থেকে তার চালানে পৌঁছানো যায় (নিয়ম ১)।
 *
 * ── কেন গুদাম নেই স্তরে ─────────────────────────────────────────────
 * মালের দাম গুদাম বদলালে বদলায় না — ময়মনসিংহ থেকে নেত্রকোনায় পাঠালে
 * বস্তাটা একই দামের বস্তাই থাকে। তাই স্তর কোম্পানির, গুদামের নয়, আর
 * ট্রান্সফার কোনো স্তর তৈরি বা খরচ করে না। কোন গুদামে কত আছে সেটা
 * চলাচলের টেবিলই বলে, আর সেটাই তার কাজ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();

            /*
             * কোন নথিতে মালটা ঢুকেছিল — চালান, receipt-হীন বিল, বা
             * বিক্রয় ফেরত। ড্রিল-ডাউন এখান থেকেই পথ খুঁজে নেয়।
             */
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('document_no', 64)->nullable();

            /*
             * তারিখটা আলাদা কলাম, নথি থেকে জোড়া লাগানো নয়।
             *
             * FIFO-র ক্রম এই তারিখে, আর একই তারিখে হলে id-তে। নথি ধরে
             * ধরে তারিখ খুঁজলে প্রতিটা টানে চারটা টেবিলে জোড়া লাগত।
             */
            $table->date('trx_date');

            $table->decimal('qty_in', 18, 4);

            // যা এখনো বেরোয়নি — শূন্য হলে স্তরটা নিঃশেষ
            $table->decimal('qty_remaining', 18, 4);

            $table->decimal('unit_cost', 18, 4);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * টান দেওয়ার কোয়েরিটা দিনে হাজারবার চলে: "এই পণ্যের যে
             * স্তরগুলোয় এখনো মাল আছে, পুরনো আগে"। কলামগুলো ঠিক ওই ক্রমে।
             */
            $table->index(['company_id', 'product_id', 'trx_date', 'id'], 'cost_layer_fifo');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('inv_cost_layer_uses', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('cost_layer_id')->constrained('inv_cost_layers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();

            // যে নথি মালটা বের করল
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('document_no', 64)->nullable();
            $table->date('trx_date');

            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);

            // qty × unit_cost — জমিয়ে রাখা, কারণ স্তরের দর পরে সংশোধন
            // হলেও এই টানটা যে দামে হয়েছিল সেটাই ইতিহাস
            $table->decimal('amount', 18, 4);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'source_type', 'source_id'], 'cost_use_source');
            $table->index(['company_id', 'product_id', 'trx_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_cost_layer_uses');
        Schema::dropIfExists('inv_cost_layers');
    }
};
