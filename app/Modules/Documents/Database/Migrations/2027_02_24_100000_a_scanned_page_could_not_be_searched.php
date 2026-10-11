<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা স্ক্যান করা পাতার লেখা খোঁজা যেত না — ডকুমেন্টের পঞ্চম ধাপ (৯ অক্টোবর ২০২৬)।
 *
 * ⭐ পরিকল্পনা §৭: ছবি → OCR → লেখা → তথ্য → খোঁজের সূচি। §২১: `document_ocr`।
 *
 * ── ⛔ OCR সার্ভারে নয়, বাইরে তো নয়ই ─────────────────────────────────
 * লেখা চেনা হয় **ব্যবহারকারীর ব্রাউজারে**, tesseract.js (WebAssembly) দিয়ে, যার সব ফাইল আমাদের নিজের
 * সার্ভার থেকে আসে (`public/vendor/tesseract`)। শেয়ার্ড সার্ভারে Tesseract বসানো যায় না, আর কোনো কাগজ
 * বাইরের সার্ভারে যায় না — মালিকের প্রথম বাঁধন। ⓘ এখানে কেবল ফল: লেখা, মানুষের ঠিক করা তথ্য, আর কোন
 * যন্ত্রে কোন ভাষায় পড়া হলো।
 *
 * ⓘ এক ভার্সন, এক লেখা — আবার পড়লে সেই সারিটাই বদলায় (লেখা ফাইল নয়, ফাইলটা বদলায় না)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dms_document_ocr', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('dms_documents')->cascadeOnDelete();
            $table->foreignId('version_id')->unique()->constrained('dms_document_versions')->cascadeOnDelete();

            $table->longText('text');

            // ⓘ মানুষের দেখে ঠিক করা তথ্য — বিল নম্বর, তারিখ, সরবরাহকারী, অঙ্ক
            $table->json('fields')->nullable();

            $table->string('language', 16);
            $table->string('engine', 60);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->unsignedSmallInteger('pages')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'document_id']);

            // ⭐ খোঁজের সূচি (§১৬ "Content") — MariaDB InnoDB FULLTEXT
            $table->fullText('text', 'dms_document_ocr_text_fulltext');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dms_document_ocr');
    }
};
