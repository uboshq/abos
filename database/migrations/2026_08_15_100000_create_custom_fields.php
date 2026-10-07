<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * নিজস্ব ঘর — কোম্পানি নিজে যোগ করে।
 *
 * ── কেন দরকার ────────────────────────────────────────────────────────
 * প্রতিটা ডিপো একটু আলাদা। একজনের গ্রাহকের সাথে "রুট নম্বর" লাগে,
 * আরেকজনের "দোকানের মালিকের নাম"। রাখার জায়গা না থাকলে মানুষ ওগুলো
 * বিবরণের ঘরে লিখে রাখেন — আর তখন সেটা দিয়ে খোঁজা যায় না, রিপোর্টে
 * আসে না, আর দুইজন দুই রকম বানানে লেখেন।
 *
 * ── কেন দুইটা টেবিল, JSON কলাম নয় ───────────────────────────────────
 * `customers.custom` নামে একটা JSON কলাম রাখা যেত, আর লেখা সহজ হত।
 * কিন্তু তখন "যাদের রুট নম্বর ৭" খুঁজতে প্রতিটা সারির JSON খুলতে হত —
 * আর ওই প্রশ্নটাই মানুষ সবচেয়ে বেশি করবে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /*
             * কোন জিনিসের ঘর — drill source-এর নাম (customer, product…)।
             *
             * মডিউল নিজে ঘোষণা করে কোন জিনিসে নিজস্ব ঘর বসানো যায়, তাই
             * কোরকে কোনো মডিউলের নাম জানতে হয় না।
             */
            $table->string('entity', 64);

            /*
             * চাবি স্থির, লেবেল নয়।
             *
             * লেবেল বদলায় ("রুট" → "রুট নম্বর"), কিন্তু চাবি বদলালে
             * পুরনো মানগুলো কোন ঘরের তা বলা যেত না। তাই তৈরির পর চাবি
             * আর সম্পাদনা করা যায় না।
             */
            $table->string('key', 64);

            $table->string('label_en', 120);
            $table->string('label_bn', 120);

            // text · number · date · boolean · select
            $table->string('type', 16);

            // select-এর বিকল্পগুলো; বাকি ধরনে খালি
            $table->json('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'entity', 'key'], 'custom_field_scope');
            $table->index(['company_id', 'entity', 'is_active']);
        });

        Schema::create('custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained('custom_fields')->cascadeOnDelete();

            $table->string('entity', 64);
            $table->unsignedBigInteger('entity_id');

            /*
             * মানটা লেখা, তার নিজের ধরনে নয়।
             *
             * এক টেবিলে সংখ্যা, তারিখ, লেখা ও পতাকা সবই আসে। আলাদা কলাম
             * রাখলে প্রতিটা সারিতে তিনটা কলাম খালি থাকত, আর নতুন ধরন
             * এলে টেবিল বদলাতে হত।
             */
            $table->text('value')->nullable();

            $table->timestamps();

            $table->unique(['custom_field_id', 'entity_id'], 'custom_value_scope');
            $table->index(['company_id', 'entity', 'entity_id']);
        });

        /*
         * মান ধরে খোঁজার ইনডেক্স — উপসর্গের দৈর্ঘ্য সহ।
         *
         * "যাদের রুট নম্বর ৭" — এই প্রশ্নটার জন্যই মানগুলো আলাদা
         * টেবিলে। কিন্তু MySQL TEXT কলামে দৈর্ঘ্য ছাড়া ইনডেক্স নেয় না,
         * আর Blueprint-এ উপসর্গ বলার উপায় নেই — তাই সরাসরি বিবৃতি।
         *
         * ১৯১ অক্ষর: utf8mb4-তে প্রতিটা অক্ষর সর্বোচ্চ ৪ বাইট, আর
         * ইনডেক্সের সীমা ৭৬৭ বাইট (পুরনো row format-এ)। খোঁজা হয়
         * ছোট মান ধরে — রুট নম্বর, কোড — তাই প্রথম ১৯১ অক্ষরেই
         * যথেষ্ট, আর লম্বা নোট তবু পুরোটা জমা থাকে।
         */
        DB::statement(
            'CREATE INDEX custom_value_lookup ON custom_field_values '
            .'(company_id, custom_field_id, value(191))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
