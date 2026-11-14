<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ কোপাইলট নাম চিনত কেবল অক্ষর মিলিয়ে — "cosmos biskut" বা "বিস্কিট" প্রশ্নে "কসমস বিস্কুট ৪০ গ্রাম" পেত না (১৩ নভেম্বর ২০২৬;
 *   মালিক: "ম্যাকমিনিতে আরও কিছু নতুন এআই সংযোগ করা হয়েছে")।
 *
 * `assistant_vectors` — প্রতিটা পণ্যের মানে-ছাপ (bge-m3, ১০২৪ সংখ্যা, float32 হিসেবে প্যাক করা ৪ KB)। রাতে বানানো হয়
 *   ([[MeaningIndex::refresh()]]), প্রশ্নের সময় কেবল পড়া — উত্তর ধীর হয় না। নাম বদলালে `hash` বদলায়, পরের রাতে নতুন ছাপ।
 * ⛔ ছাপ থেকে নাম ফেরানো যায় না, আর এতে টাকা বা সংখ্যা নেই — কেবল পণ্যের নামের মানে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_vectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // ⓘ কী জিনিসের ছাপ — আপাতত কেবল product
            $table->string('kind', 20);
            $table->unsignedBigInteger('ref_id');
            $table->string('model', 60);
            // ⓘ ছাপ বানানোর সময়ের লেখার sha1 — নাম বদলালে নতুন ছাপ
            $table->char('hash', 40);
            $table->binary('vector');
            $table->timestamps();

            $table->unique(['company_id', 'kind', 'ref_id'], 'assistant_vec_one');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_vectors');
    }
};
