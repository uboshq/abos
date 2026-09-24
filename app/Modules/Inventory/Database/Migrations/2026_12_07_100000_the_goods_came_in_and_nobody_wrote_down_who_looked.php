<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মাল এল, আর কে দেখল তা কেউ লিখে রাখল না।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Quality Inspection · Quarantine · Approved · Rejected · Rework ·
 * Disposal"*, আর প্রতিটা পরিদর্শনের নিজের কাগজ: নম্বর · পণ্য · লট ·
 * পরিমাণ · মাপকাঠি · ফল · পরিদর্শক · তারিখ · মন্তব্য · সংযুক্তি।
 *
 * ── ⛔ আজ পর্যন্ত যা হত ──────────────────────────────────────────────
 * মাল এলে গুদামের লোক দেখে নিতেন, আর খারাপ হলে **আটকে** দিতেন
 * (`HOLD-DMG` বা `HOLD-RET`)। ⓘ অর্থাৎ সিদ্ধান্তটা হত, কিন্তু
 * **সিদ্ধান্তের কাগজটা হত না**।
 *
 * ⚠️ ফল: তিন মাস পরে সরবরাহকারী বলতেন *"আমার মাল খারাপ ছিল না"*, আর
 * প্রমাণ হিসেবে আমাদের হাতে থাকত কেবল একটা আটকানোর সারি — কে দেখেছিল,
 * কী দেখে বাতিল করেছিল, কোনো ছবি ছিল কি না, কিছুই নয়।
 *
 * ⛔ আর সরবরাহকারীর কার্যক্ষমতার হিসাবেও *"বাতিলের হার"* বসানো যেত না,
 * কারণ বাতিল বলে গোনার মতো কোনো ঘটনা রেকর্ড হত না।
 *
 * ── ⓘ কেন চালানের সাথে বাঁধা নয় ──────────────────────────────────────
 * ⚠️ পরিদর্শন কেবল ক্রয়ের মালে হয় না — উৎপাদনের মাল, ফেরত আসা মাল,
 * এমনকি গুদামে পড়ে থাকা মালও পরে দেখা হতে পারে। ⛔ `purchase_receipt_id`
 * বাধ্যতামূলক করলে Inventory আবার Purchase-এর উপর দাঁড়াত, অথচ
 * মালিকের সীমানার টেবিলে পরিদর্শন **Inventory-র**।
 *
 * ⭐ তাই সূত্রটা ঐচ্ছিক আর আলগা (`source_type` + `source_id`) — ঠিক
 * যেভাবে চলাচলের সারি তার উৎস মনে রাখে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            /* ⓘ প্রতিটা কাগজের নিজের নম্বর — সিরিজ `QC` */
            $table->string('document_no', 40);
            $table->date('inspected_on');

            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();

            /* ⚠️ লট ধরা পণ্যে লাগে, বাকিতে খালি — [[Batch]]-এর মতোই */
            $table->foreignId('batch_id')->nullable()
                ->constrained('inv_batches')->nullOnDelete();

            $table->foreignId('warehouse_id')->nullable()
                ->constrained('inv_warehouses')->nullOnDelete();

            /*
             * ⭐ কত দেখা হলো, আর তার কতটা কোন দিকে গেল।
             *
             * ⚠️ তিনটা আলাদা ঘর, একটা "ফল" নয়: ⓘ একই চালানে পঞ্চাশ
             * বস্তার চল্লিশটা ভালো আর দশটা খারাপ হতে পারে, আর ⛔ একটা
             * ফল বসালে ঐ চল্লিশটাও আটকে যেত।
             */
            $table->decimal('inspected_qty', 18, 4)->default(0);
            $table->decimal('accepted_qty', 18, 4)->default(0);
            $table->decimal('rejected_qty', 18, 4)->default(0);

            /*
             * ⓘ কী দেখে সিদ্ধান্ত — মাপকাঠিগুলো লেখা থাকে, ছকে নয়।
             *
             * ⚠️ প্রতিটা মাপকাঠির আলাদা সারি বানানোর লোভটা বড়, ⛔ কিন্তু
             * তাতে একটা মাস্টার তালিকা লাগত যা কেউ ভরত না, আর তখন
             * পরিদর্শনের কাগজেই কিছু লেখা যেত না।
             */
            $table->text('criteria')->nullable();
            $table->text('remarks')->nullable();

            /* ⓘ আলগা সূত্র — চালান, উৎপাদন, ফেরত; কিছুই না-ও হতে পারে */
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('status', 20);

            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
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
             * ⚠️ সূচকের নাম হাতে দেওয়া — Laravel-এর বানানো নাম
             * `inv_quality_inspections_company_id_inspected_on_index`
             * ৫৪ অক্ষর, সীমার ভিতরে, ⛔ কিন্তু টেবিলের নামটাই ২৪ অক্ষর,
             * তাই দুইয়ের বেশি কলাম জুড়লেই ৬৪ ছাড়াত — আর ৬৪ ছাড়ালে
             * প্রতিটা সেশনে `migrate:fresh` ভাঙে।
             */
            $table->index(['company_id', 'inspected_on'], 'inv_qc_when');
            $table->index(['company_id', 'product_id'], 'inv_qc_item');
            $table->index(['source_type', 'source_id'], 'inv_qc_source');
            $table->unique('public_id', 'inv_qc_public');
        });

        Schema::table('inv_products', function (Blueprint $table) {
            /*
             * ⭐ এই পণ্যে পরিদর্শন লাগে কি না — মালিকের সিদ্ধান্ত।
             *
             * ⛔ ডিফল্ট বন্ধ, আর সেটাই একমাত্র নিরাপদ ডিফল্ট: ⚠️ চালু
             * ধরলে আজ থেকে প্রতিটা চাল-ডালের বস্তা পরিদর্শনের অপেক্ষায়
             * আটকে থাকত, আর গুদাম বন্ধ হয়ে যেত।
             *
             * ⓘ `track_batch`-এর হুবহু যমজ — একই কারণে, একই জায়গায়।
             */
            $table->boolean('qc_required')->default(false)->after('track_batch');
        });
    }

    public function down(): void
    {
        Schema::table('inv_products', function (Blueprint $table) {
            $table->dropColumn('qc_required');
        });

        Schema::dropIfExists('inv_quality_inspections');
    }
};
