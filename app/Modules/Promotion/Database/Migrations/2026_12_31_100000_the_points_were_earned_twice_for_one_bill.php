<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা বিলের পয়েন্ট দুইবার জমা পড়ত, আর কোথাও লেখা থাকত না কেন।
 *
 * ── ⭐ মালিকের স্পেক, §৭-ঠ ও §১৩ ─────────────────────────────────────
 * *"ভবিষ্যতের কেনাকাটার জন্য পয়েন্ট"* — ক্রেতা আজ কিনে পয়েন্ট পান, পরে
 * সেটা খরচ করেন, আর মেয়াদ ফুরোলে পয়েন্ট চলে যায়।
 *
 * ── ⚠️ কেন একটা খাতা, একটা "ব্যালান্স" কলাম নয় ───────────────────────
 * ⓘ সবচেয়ে সহজ নকশা হত ক্রেতার সারিতে `points_balance` — কিনলে বাড়ে,
 * খরচ করলে কমে। ⛔ কিন্তু তখন *"এই ৩৫০ পয়েন্ট কোথা থেকে এল"* প্রশ্নের
 * কোনো উত্তর থাকত না, আর একটা দুইবার-চাপা বোতাম সংখ্যাটা নীরবে
 * দ্বিগুণ করে দিত — কেউ কোনোদিন ধরতে পারত না।
 *
 * ⭐ তাই এটা **খাতা**: প্রতিটা অর্জন, খরচ, মেয়াদ-শেষ আর ফেরত একটা করে
 * সারি, আর ব্যালান্স মানে যোগফল। ⛔ সারি কখনো বদলায় না, মোছে না —
 * ভুল হলে উল্টো সারি লেখা হয়, ঠিক হিসাবের খাতার মতো।
 *
 * ── ⚠️ দুইবার-না-হওয়ার পাহারা ডাটাবেজেও, কেবল সেবায় নয় ─────────────
 * ⓘ সেবা ক্রেতার সারিতে তালা দিয়ে আগে দেখে নেয়। ⛔ কিন্তু একদিন কেউ
 * তালা ছাড়া একটা পথ লিখবেন — তখন নিচের দুইটা `unique` শেষ দেয়াল:
 *   ⓵ একটা বসানো অফারে একটাই অর্জন
 *   ⓶ একটা সারির একটাই ফেরত, একটাই মেয়াদ-শেষ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'plo_co_fk')->cascadeOnDelete();

            /*
             * ⚠️ `restrictOnDelete` — খাতাওয়ালা ক্রেতা মোছা যায় না।
             *
             * ⓘ ক্রেতা নরম-মোছা হন, তাই রোজকার কাজে এটা বাধা দেয় না। ⛔ কিন্তু
             * কেউ শক্ত করে মুছতে চাইলে খাতাটা আগে দাঁড়ায় — নাহলে পয়েন্টের
             * দায়টা (যেটা আসলে টাকার দায়) নিঃশব্দে উড়ে যেত।
             */
            $table->foreignId('customer_id')->constrained('customers', 'id', 'plo_cust_fk')->restrictOnDelete();

            /*
             * ⓘ অর্জনের সারিতে কোন বসানো অফার থেকে পয়েন্ট এল — অন্য সারিতে খালি।
             *
             * ⭐ নিচের `unique`-এর অর্ধেক এই ঘর: একই বসানো অফারে দ্বিতীয়
             * অর্জন ডাটাবেজই ফিরিয়ে দেয়। ⓘ MySQL/MariaDB দুইটাই একাধিক
             * `NULL`-কে আলাদা ধরে, তাই খরচের সারিগুলো আটকায় না।
             */
            $table->foreignId('promotion_application_id')->nullable()
                ->constrained('promotion_applications', 'id', 'plo_app_fk')->restrictOnDelete();

            /* ⓘ earn · redeem · expire · reverse — [[LoyaltyKind]] */
            $table->string('kind', 16);

            /*
             * ⭐ চিহ্নসহ — অর্জন ধনাত্মক, বাকি সব ঋণাত্মক (খরচের ফেরত ছাড়া)।
             *
             * ⓘ চিহ্নটা সারিতেই থাকলে ব্যালান্স একটা `SUM`। ⛔ চিহ্ন ধরনের
             * উপর ছেড়ে দিলে প্রতিটা প্রতিবেদনকে মনে রাখতে হত কোন ধরন বিয়োগ —
             * আর একটা প্রতিবেদন একদিন ভুলত।
             */
            $table->decimal('points', 18, 4);

            /*
             * ⓘ কোন দিন পর্যন্ত খরচ করা যায় (ঐ দিনটাও ধরে) — `NULL` মানে কখনো ফুরোয় না।
             * ⚠️ কেবল যে সারি পয়েন্ট **আনে** তার জন্য অর্থবহ।
             */
            $table->date('expires_on')->nullable();

            /*
             * ⭐ ফেরত ও মেয়াদ-শেষ কোন সারির বিরুদ্ধে।
             *
             * ⓘ `(reverses_entry_id, kind)` অনন্য — একটা সারির একটাই ফেরত,
             * আর একটাই মেয়াদ-শেষ। ⛔ ফেরতের দরজা দুইবার চাপা হলে দ্বিতীয়
             * সারিটা ডাটাবেজই ফিরিয়ে দেয়।
             */
            $table->foreignId('reverses_entry_id')->nullable()
                ->constrained('promotion_loyalty_entries', 'id', 'plo_rev_fk')->restrictOnDelete();

            /* ⓘ কোন কাগজ — বিল, সরাসরি বিক্রয় ইত্যাদি; ABOS-এর চেনা `source_type` + `source_id` */
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');

            /*
             * ⚠️ ঘটনাটা **কখন** — `created_at` থেকে আলাদা, আর সেটা ইচ্ছাকৃত।
             *
             * ⓘ মেয়াদ-শেষের সারি লেখা হয় যখন রাতের কাজ চলে, কিন্তু সে
             * বলে *"এই মুহূর্তে পয়েন্ট ফুরিয়েছে"*। ⛔ `created_at` দিয়ে
             * ইতিহাস পড়লে পুরনো দিনের ব্যালান্স জিজ্ঞেস করলে ভুল উত্তর আসত।
             */
            $table->dateTime('occurred_at');

            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'plo_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['promotion_application_id', 'kind'], 'plo_app_kind_uq');
            $table->unique(['reverses_entry_id', 'kind'], 'plo_rev_kind_uq');
            $table->index(['company_id', 'customer_id', 'occurred_at'], 'plo_cust_ix');
            $table->index(['company_id', 'source_type', 'source_id'], 'plo_src_ix');
            $table->index(['company_id', 'kind', 'expires_on'], 'plo_exp_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_loyalty_entries');
    }
};
