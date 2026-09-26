<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * উপহারটা বেরিয়ে যেত, আর গুদাম কোনোদিন জানত না।
 *
 * ── ⭐ মালিকের স্পেক, §৮ ও §১৮ ───────────────────────────────────────
 * *"Gift কোনো imaginary item হবে না — Gift product অবশ্যই Inventory-এর
 * actual product হতে হবে। Gift issue হলে Inventory Stock কমবে।"*
 *
 * ── ⛔ এই দুইটা টেবিল না থাকলে যা হত ────────────────────────────────
 * ⓘ উপহারটা বিলে ছাপা হত (*"Product B — 5 Carton — FREE"*), ক্রেতা
 * মালটা নিয়ে যেতেন, আর খাতায় কোথাও লেখা থাকত না।
 *
 * ⚠️ ফল তিনটা, আর তিনটাই নীরব:
 *   ⓵ মজুদ বলত পাঁচ কার্টন আছে, গুদামে থাকত না
 *   ⓶ *"এ মাসে কত উপহার দিলাম"* — কোনো উত্তর নেই (§১৭)
 *   ⓷ মাল ফেরত এলে কোন উপহারটা ফেরত নিতে হবে তা জানা যেত না (§১৮)
 *
 * ── ⚠️ কেন দুইটা টেবিল, একটা নয় ────────────────────────────────────
 * ⓘ *"অফারটা এই বিলে বসেছিল"* আর *"এই উপহারটা বেরিয়েছিল"* — দুইটা
 * আলাদা ঘটনা। ⛔ একটা ছাড়ের অফারে কোনো উপহার বেরোয় না, আর একটা বিলে
 * একাধিক উপহার বেরোতে পারে।
 *
 * ⚠️ এক টেবিলে মেশালে ছাড়ের সারিতে পণ্য ও গুদামের ঘর খালি পড়ে থাকত,
 * আর *"কত উপহার"* গুনতে গেলে প্রতিবার ছেঁকে নিতে হত।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── কোন বিলে কোন অফার বসেছিল, আর কত সুবিধা ──────────────────
         *
         * ⭐ এটাই §১৮-এর ভিত্তি: *"পুরনো বিলে বসানো অফার অপরিবর্তিত
         * থাকবে"*।
         *
         * ── ⚠️ মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ২০২৬ ────────────────
         * *"barate hole barabe, komate hole komabe"* — মেয়াদ দুই দিকেই
         * বদলানো যায়। ⛔ তাই নিরাপত্তাটা তারিখে বসানো যায় না।
         *
         * ⭐ এখানে বসে: সুবিধার অঙ্কটা **এই সারিতে জমে যায়**। ⓘ অফারের
         * মেয়াদ পিছিয়ে আনলে ঐ বিলের ছাড় ফেরত যায় না, কারণ ছাড়টা আর
         * অফারের কাগজ থেকে পড়া হয় না — এখান থেকে পড়া হয়।
         */
        Schema::create('promotion_applications', function (Blueprint $table) {
            $table->id();

            /*
             * ⭐ বাইরের কী — [[PublicIdTest]] প্রতিটা টেবিলে এটা চায়।
             *
             * ⓘ আমি ভেবেছিলাম শুধু মূল কাগজগুলোতে লাগে, ভিতরের সারিতে নয়।
             * ⛔ কিন্তু নম্বরগুলো একদিন API-তে যায়, আর ডাটাবেজের `id`
             * বাইরে দিলে কেউ গুনে বলতে পারে আপনার কয়টা অফার আছে।
             */
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            /*
             * ⚠️ `restrictOnDelete` — অফারটা মুছে ফেলা যাবে না যদি কোনো
             * বিলে বসে থাকে।
             *
             * ⛔ `cascade` দিলে একটা পুরনো অফার মুছলে ঐ বিলগুলোর ছাড়ের
             * ইতিহাসও উড়ে যেত, আর খাতায় টাকাটা মিলত না।
             */
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();

            /*
             * ⚠️ কাগজটা কোন মডিউলের, তা নাম ধরে — বিদেশি চাবি নয়।
             *
             * ⓘ একই অফার বসতে পারে বিক্রয় বিলে, সরাসরি বিক্রয়ে, বিক্রয়
             * আদেশে। ⛔ একটা বিদেশি চাবি একটাই টেবিলের দিকে তাকাতে পারে।
             *
             * ⓘ ছাঁচটা ABOS-এর চেনা: `source_type` + `source_id`, ঠিক
             * যেভাবে মজুদের নড়াচড়া লেখা হয়।
             */
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();

            /*
             * ⚠️ টেবিলটার নাম শুধু `customers`, `cus_customers` নয়।
             *
             * ⓘ বাকি মডিউলগুলো উপসর্গ ব্যবহার করে (`inv_`, `mdm_`),
             * তাই ধরে নেওয়া সহজ — আর ধরে নেওয়া নামের উপর দাঁড়ানো কোড
             * এখানে দুইবার ভেঙেছে। ⛔ নামটা মাইগ্রেশন পড়ে নেওয়া।
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('inv_products')->nullOnDelete();

            /* ⓘ কোন সুবিধার সারিটা বসেছিল — স্ল্যাবের কোন ধাপ */
            $table->unsignedBigInteger('promotion_benefit_id')->nullable();

            /*
             * ⭐ **জমে যাওয়া** সংখ্যাগুলো — অফারের কাগজ থেকে নয়, এখান থেকে।
             *
             * ⓘ `kind` লেখা থাকে কারণ অফারের ধরন পরে বদলালেও এই বিলের
             * সুবিধাটা যা ছিল তাই থাকে।
             */
            $table->string('benefit_kind', 16);
            $table->decimal('benefit_amount', 18, 4);
            $table->decimal('worth', 18, 4)->default(0);

            /*
             * ⛔ হাতে বদলানো হয়েছিল কি না — স্পেক §১৯।
             *
             * ⚠️ কারণটা বাধ্যতামূলক: ⓘ ওভাররাইড মানে নিয়মের বাইরে টাকা
             * দেওয়া, আর *"কেন"* না জানলে পরে কেউ বলতে পারে না ওটা
             * বৈধ ছিল কি না।
             */
            $table->boolean('was_overridden')->default(false);
            $table->string('override_reason', 300)->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'source_type', 'source_id'], 'pap_src_ix');
            $table->index(['company_id', 'promotion_id'], 'pap_prom_ix');
        });

        /*
         * ── উপহার সত্যিই বেরিয়েছে, আর কোন লট থেকে ───────────────────
         *
         * ⭐ স্পেক §৮-এর সংরক্ষণের তালিকা: Gift Reference · Promotion
         * Code · Invoice No · Warehouse · Batch · Serial · User · Time।
         */
        Schema::create('promotion_gift_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();

            /* ⓘ `GIFT-2026-000001` — স্পেক §২১ */
            $table->string('code', 32);

            $table->foreignId('promotion_application_id')
                ->constrained('promotion_applications')->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();

            /*
             * ⚠️ লটটা `nullable`, আর সেটা ইচ্ছাকৃত।
             *
             * ⓘ সব পণ্য লট রাখে না (`track_batch`)। ⛔ বাধ্যতামূলক করলে
             * লট-না-রাখা পণ্যে উপহার দেওয়াই যেত না।
             *
             * ⚠️ কিন্তু যে পণ্য লট রাখে, তার জন্য এটা **বাধ্যতামূলক** —
             * আর ঐ নিয়মটা সেবায়, ডাটাবেজে নয়। ⓘ কারণ ডাটাবেজ জানে না
             * কোন পণ্য লট রাখে।
             */
            $table->foreignId('batch_id')->nullable()->constrained('inv_batches')->nullOnDelete();

            $table->decimal('qty', 18, 4);
            $table->foreignId('unit_id')->nullable()->constrained('mdm_units')->nullOnDelete();

            /*
             * ⭐ উপহারের **খরচ** — মালিকের দিক, ক্রেতার দিক নয়।
             *
             * ⓘ ক্রেতার কাছে উপহারের মূল্য বিক্রয়মূল্য; মালিকের কাছে
             * খরচ ক্রয়মূল্য। ⚠️ §১৭-এর *"Promotion Cost"* এই সংখ্যাটা
             * ধরে গোনা হয়, বিক্রয়মূল্য ধরে নয় — নাহলে খরচটা ফুলে
             * দেখাত আর অফারটা অকারণে খারাপ মনে হত।
             */
            $table->decimal('unit_cost', 18, 4)->default(0);

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();

            /*
             * ⛔ ফেরত — §১৮।
             *
             * ⓘ ১০০ কিনে ৫ উপহার পাওয়ার পর ২০ ফেরত দিলে যোগ্যতা আবার
             * হিসাব হয়। ⚠️ উপহারটা ফেরত নিতে হলে সেটা এখানেই লেখা
             * থাকে — সারিটা মোছা হয় না, কারণ মোছা মানে ইতিহাস মোছা।
             */
            $table->decimal('returned_qty', 18, 4)->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'code'], 'pgi_co_code_uq');
            $table->index(['company_id', 'product_id'], 'pgi_prod_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_gift_issues');
        Schema::dropIfExists('promotion_applications');
    }
};
