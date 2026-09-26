<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * অফারটা বলত কার জন্য আর কী শর্তে — কিন্তু লেখার জায়গা ছিল না।
 *
 * ── ⭐ স্পেকের §৬ ধাপ ২, ৩ ও ৪ ───────────────────────────────────────
 * সুযোগ (কার জন্য, কোন পণ্যে) · শর্ত (কত কিনলে) · সুবিধা (কী পাবেন)।
 *
 * ── ⚠️ কেন তিনটা আলাদা টেবিল, এক টেবিলে নয় ─────────────────────────
 * ⓘ তিনটাই *"অফারের একটা সারি"*, তাই প্রথমে মনে হয় এক টেবিলে `kind`
 * কলাম দিয়ে চালানো যায়। ⛔ কিন্তু তিনটার **আকার আলাদা**:
 *
 *   ⓵ সুযোগ = একটা দিক আর একটা আইডি (`branch` → ৩)
 *   ⓶ শর্ত  = একটা দিক আর একটা পরিসর (`quantity` → ৫০ থেকে ৯৯)
 *   ⓷ সুবিধা = একটা ধরন আর একটা পরিমাণ, কখনো পণ্যসহ
 *
 * ⚠️ এক টেবিলে মেশালে প্রতিটা সারির অর্ধেক কলাম `null` থাকত, আর *"এই
 * সারিতে কোন কলামগুলো মানে রাখে"* সেটা কেবল `kind` পড়ে বোঝা যেত —
 * অর্থাৎ ডাটাবেজ নিজে আর কিছু পাহারা দিত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── সুযোগ · কার জন্য, কোন পণ্যে ─────────────────────────────
         *
         * ⓘ সারি না থাকা মানে *"সব"*। ⚠️ তাই একটা অফারে কোনো
         * `customer` সারি না থাকলে সে **সব ক্রেতার** জন্য, আর
         * `ALL` বলে কোনো মান রাখতে হয় না।
         */
        Schema::create('promotion_scopes', function (Blueprint $table) {
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
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();

            /* ⓘ [[ScopeKind]] — branch · warehouse · customer · product … */
            $table->string('kind', 16);

            /*
             * ⚠️ কোনো বিদেশি চাবি নেই, আর সেটা ইচ্ছাকৃত।
             *
             * ⓘ `target_id` একেক সারিতে একেক টেবিলের দিকে তাকায় —
             * শাখা, গুদাম, ক্রেতা, পণ্য, ব্র্যান্ড। ⛔ একটা বিদেশি চাবি
             * একটাই টেবিলের দিকে তাকাতে পারে।
             *
             * ⚠️ দাম আছে: মুছে ফেলা ক্রেতার সারি পড়ে থাকতে পারে। ⓘ তাই
             * ইঞ্জিন মেলানোর সময় **join** করে, আর না মিললে সারিটা চুপচাপ
             * বাদ পড়ে — একটা মৃত সারি অফারটাকে ভাঙে না।
             */
            $table->unsignedBigInteger('target_id');

            $table->timestamps();

            $table->unique(['promotion_id', 'kind', 'target_id'], 'psc_uq');
            $table->index(['company_id', 'kind', 'target_id'], 'psc_lookup_ix');
        });

        /*
         * ── শর্ত · কত কিনলে সুবিধাটা খোলে ───────────────────────────
         *
         * ⭐ স্ল্যাব মানেই এখানে কয়েকটা সারি (§৭-ঙ): ৫০–৯৯, ১০০–১৯৯,
         * ২০০+। ⓘ প্রতিটার সাথে নিজের সুবিধার সারি জোড়া থাকে।
         */
        Schema::create('promotion_conditions', function (Blueprint $table) {
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
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();

            /* ⓘ [[ConditionKind]] — quantity · value · product · time */
            $table->string('kind', 16);

            /*
             * ⚠️ পরিসরের দুই মাথা, আর দুইটাই `nullable`।
             *
             * ⓘ `from` খালি মানে *"নিচের কোনো সীমা নেই"*, `to` খালি মানে
             * *"উপরের কোনো সীমা নেই"* — স্ল্যাবের শেষ ধাপটা (২০০+) ঠিক
             * এভাবেই লেখা হয়।
             *
             * ⛔ শূন্য দিয়ে *"সীমা নেই"* বোঝানো যেত না: শূন্য নিজেই একটা
             * বৈধ সীমা (*"শূন্যের বেশি কিনলে"*)।
             */
            $table->decimal('value_from', 18, 4)->nullable();
            $table->decimal('value_to', 18, 4)->nullable();

            /* ⓘ `kind = product` হলে কোন পণ্যটা — Buy A Get B-তে A */
            $table->unsignedBigInteger('target_id')->nullable();

            /*
             * ⓘ `kind = time` হলে দিনের সময় আর সপ্তাহের দিন।
             *
             * ⛔ এটা অফারের মেয়াদ নয় — মেয়াদ বসে `promotions`-এ।
             * ⚠️ এটা মেয়াদের **ভিতরে** বারবার ফিরে আসা শর্ত: *"রোজ দুপুর
             * ১২টা–৩টা"*, *"কেবল শুক্রবার"*।
             */
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();
            $table->string('weekdays', 16)->nullable();

            /*
             * ⭐ স্ল্যাবের ধাপগুলো ক্রমে পড়তে হয়, তাই ক্রমটা লেখা থাকে।
             *
             * ⚠️ `id` ধরে সাজানো যেত না: ⓘ কেউ মাঝখানে একটা ধাপ যোগ
             * করলে সেটা সবার শেষে বসত, আর ৫০–৯৯ ধাপটা ২০০+ এর পরে পড়ত।
             */
            $table->integer('step_order')->default(0);

            $table->timestamps();

            $table->index(['promotion_id', 'kind', 'step_order'], 'pcn_step_ix');
        });

        /*
         * ── সুবিধা · ক্রেতা কী পান ──────────────────────────────────
         */
        Schema::create('promotion_benefits', function (Blueprint $table) {
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
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();

            /*
             * ⭐ কোন শর্তের সাথে জোড়া।
             *
             * ⓘ `null` মানে *"অফারটা খাটলেই এই সুবিধা"* — শর্তহীন ছাড়।
             * ⚠️ স্ল্যাবে এটা কখনো `null` হয় না: প্রতিটা ধাপের নিজের
             * সুবিধা, আর জোড়াটা না থাকলে কোন ধাপে কত তা বলার উপায় নেই।
             */
            $table->foreignId('promotion_condition_id')->nullable()
                ->constrained('promotion_conditions')->cascadeOnDelete();

            /* ⓘ [[BenefitKind]] — percent · amount · goods · credit · points */
            $table->string('kind', 16);

            $table->decimal('amount', 18, 4);

            /*
             * ⚠️ উপহারের পণ্য — আর সে **আসল পণ্য**, কাল্পনিক আইটেম নয়।
             *
             * ⓘ স্পেক §৮: উপহার দিলে Inventory Stock কমবে। ⛔ তাই এখানে
             * সত্যিকারের বিদেশি চাবি: পণ্যটা মুছে গেলে অফারটা এমন কিছু
             * দেওয়ার প্রতিশ্রুতি দিত যা আর নেই।
             */
            $table->foreignId('gift_product_id')->nullable()
                ->constrained('inv_products')->nullOnDelete();
            /* ⚠️ `mdm_units`, `md_units` নয় — নামটা `route:list`-এর মতোই মেপে নেওয়া */
            $table->foreignId('gift_unit_id')->nullable()
                ->constrained('mdm_units')->nullOnDelete();

            /*
             * ⛔ সর্বোচ্চ কতটা — স্পেক §৮-এর *"Maximum 20 Carton Gift
             * per Invoice"*।
             *
             * ⚠️ এটা না থাকলে একটা বড় বিল গোটা উপহারের মজুদ শুষে নিত,
             * আর পরের ক্রেতারা কিছুই পেতেন না — নীরবে, কারণ অফারটা
             * তখনো *"চলছে"* বলত।
             */
            $table->decimal('cap_per_bill', 18, 4)->nullable();

            $table->timestamps();

            $table->index(['promotion_id', 'kind'], 'pbn_kind_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_benefits');
        Schema::dropIfExists('promotion_conditions');
        Schema::dropIfExists('promotion_scopes');
    }
};
