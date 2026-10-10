<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা কাগজ নিজে বলতে পারত না সে কী — ডকুমেন্টের ষষ্ঠ ধাপ, Document Intelligence (ABE) (৯ অক্টোবর ২০২৬)।
 *
 * ⭐ পরিকল্পনা §৮ — শ্রেণি চেনা, তথ্য তোলা, সারাংশ, তুলনা, কাগজকে প্রশ্ন, আর শব্দকোষ। ⛔ মালিকের প্রথম বাঁধন:
 * কোনো বাইরের AI নয়, কোনো মডেল নয় — নিয়ম, প্যাটার্ন আর গোনা, আমাদের নিজের সার্ভারে।
 *
 * ⓘ `dms_abe_rules` — কোম্পানির নিজের নিয়ম, প্রশাসনের পর্দা থেকে:
 *   `classify` — এই শব্দগুলো থাকলে এই ধরনের কাগজ (ওজনসহ);
 *   `extract`  — এই ধরনের কাগজে এই প্যাটার্নে এই তথ্য (একটা ধরার দল সহ নিয়মিত রাশি)।
 * মালিকের ধরনগুলোর জন্য মূল নিয়ম কোডেই থাকে ([[DocumentIntelligence::BUILT_IN]]); টেবিলে কেবল যোগ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dms_abe_rules', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('doc_type', 24);
            $table->string('label', 120)->nullable();

            // ⓘ classify — কমা দিয়ে আলাদা শব্দ; extract — একটা ধরার দলসহ প্যাটার্ন
            $table->string('keywords', 500)->nullable();
            $table->string('pattern', 500)->nullable();
            $table->unsignedSmallInteger('weight')->default(1);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'kind', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dms_abe_rules');
    }
};
