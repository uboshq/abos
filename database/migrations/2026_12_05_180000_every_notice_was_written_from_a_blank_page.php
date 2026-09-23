<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রতিটা নোটিশ শুরু হত সাদা পাতা থেকে।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ২০ ────────────────────
 * *"বারবার ব্যবহারের জন্য Template Engine থাকতে হবে"* — ছুটি, অফিস
 * বন্ধ, সার্ভার রক্ষণাবেক্ষণ, নিরাপত্তা সতর্কতা।
 *
 * ── ⚠️ কেন টেমপ্লেটে অগ্রাধিকার আর লক্ষ্যও থাকে ─────────────────────
 * ⓘ কেবল লেখাটা রাখলে অর্ধেক কাজ হত। ⛔ *"সার্ভার রক্ষণাবেক্ষণ"*
 * নোটিশে প্রতিবার হাতে `CRITICAL` বাছতে হলে কোনো একদিন কেউ ভুলতেন,
 * আর ঐ নোটিশটা বারেই যেত না — অথচ ঠিক ওটাই বারে সবচেয়ে বেশি দরকার।
 *
 * ⓘ লক্ষ্যটাও একই কারণে: *"গুদামের সবাইকে"* প্রতিবার হাতে বাছা মানে
 * একদিন কেউ সবাইকে পাঠিয়ে দেবেন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_templates', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            $table->foreignId('notice_category_id')->nullable()
                ->constrained('notice_categories', indexName: 'ntpl_cat_fk')->nullOnDelete();

            $table->string('title', 255)->nullable();
            $table->string('summary', 300)->nullable();
            $table->text('body')->nullable();
            $table->string('priority', 16)->nullable();
            $table->string('type', 40)->nullable();

            /*
             * ⓘ লক্ষ্যের চাবিগুলো JSON-এ, নিজের টেবিলে নয়।
             *
             * ⚠️ টেমপ্লেটের লক্ষ্য কোনো সম্পর্ক নয়, একটা **খসড়া** —
             * নোটিশ বানানোর সময় ওগুলো [[NoticeAudience]]-এ বসে যায়।
             * ⛔ নিজের টেবিল দিলে একই জিনিসের দুইটা ঘর হত, আর
             * টেমপ্লেট মুছলে কোনটা সাফ হবে তা নিয়ে প্রশ্ন উঠত।
             */
            $table->json('audience')->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'ntpl_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_templates');
    }
};
