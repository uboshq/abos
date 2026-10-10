<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রতিটা চিঠি শূন্য থেকে টাইপ হত — ডকুমেন্টের সপ্তম ধাপ (৯ অক্টোবর ২০২৬)।
 *
 * ⭐ পরিকল্পনা §২ (Document Templates, Document Editor), §২০ (Retention Policies, Archive Policies), §২১
 * (`document_templates`)।
 *
 * ── ⓘ দুইটা টেবিল ───────────────────────────────────────────────────────
 * `dms_templates` — ছাঁচ: লেখা, তার ভিতরে `{{ নাম }}` ঘর; ভরে দিলে ABOS-এর ছাপার যন্ত্রে PDF হয়ে নতুন কাগজ।
 * `dms_retention_policies` — রাখার নিয়ম: কোন ফোল্ডার/ধরনের কাগজ কতদিন পরে আর্কাইভে, কতদিন পরে রিসাইকেল
 *   বিনে। ⛔ চিরতরে মোছা কখনো নিয়মে নয় — কেবল মানুষ, বিন থেকে, নিজের চাবিতে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dms_templates', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 24);
            $table->string('title', 120);
            $table->string('doc_type', 24);
            $table->string('folder', 24);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('dms_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // ⓘ কোন কাগজে — ফাঁকা মানে সব
            $table->string('folder', 24)->nullable();
            $table->string('doc_type', 24)->nullable();

            // ⓘ কোন তারিখ ধরে — কাগজের তারিখ, মেয়াদ, বা তোলার দিন
            $table->string('basis', 16)->default('created');

            $table->unsignedSmallInteger('archive_after_days')->nullable();
            $table->unsignedSmallInteger('bin_after_days')->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dms_retention_policies');
        Schema::dropIfExists('dms_templates');
    }
};
