<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * অফারের কোনো ছাদ ছিল না, আর কেউ গুনত না।
 *
 * ── ⭐ মালিকের স্পেক, §১৫ ────────────────────────────────────────────
 * *"Budget = ৳10,00,000 | Used = ৳8,50,000 | Remaining = ৳1,50,000
 * (Threshold 80% হলে warning)"*, আর §২০: *"Budget exceeded → Block"*।
 *
 * ── ⛔ ছাদ না থাকলে যা হত ───────────────────────────────────────────
 * ⓘ ঈদের অফার চলত যতদিন মেয়াদ, যত বিল কাটা হোক। ⚠️ মালিকের মার্জিন
 * ৩.৮২% — একটা জনপ্রিয় অফার এক সপ্তাহে গোটা মাসের লাভ খেয়ে ফেলতে
 * পারত, আর মাস শেষের আগে কেউ জানত না।
 *
 * ── ⚠️ কেন এটা `promotions` টেবিলের কলাম নয় ─────────────────────────
 * ⓘ স্পেকে চার রকম বাজেট: মোট, ছাড়, উপহার, পরিমাণ। ⛔ চারটা কলাম
 * বসালে বেশিরভাগ অফারে তিনটা খালি থাকত, আর পঞ্চম রকমের দিন টেবিলটা
 * বদলাতে হত। ⓘ সারি হলে একটা অফারের যত খুশি ছাদ থাকতে পারে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_budgets', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();

            /*
             * ⓘ কীসের ছাদ — `total` (টাকায়, সব সুবিধা মিলে), `discount`
             * (কেবল ছাড়), `gift` (কেবল উপহারের মূল্য), `quantity`
             * (উপহারের পরিমাণ)।
             */
            $table->string('kind', 16);

            $table->decimal('ceiling', 18, 4);

            /*
             * ⚠️ সতর্কতার সীমা শতাংশে — ডিফল্ট ৮০, স্পেকের উদাহরণ।
             *
             * ⓘ ছাদে পৌঁছানোর **আগে** জানানো, যাতে মালিক ঠিক করতে পারেন
             * বাড়াবেন না থামাবেন। ⛔ কেবল ছাদে পৌঁছে থামলে অফারটা হঠাৎ
             * মাঝপথে বন্ধ হত, আর কাউন্টারে ক্রেতা দাঁড়িয়ে শুনতেন *"আজ
             * আর হবে না"*।
             */
            $table->unsignedTinyInteger('warn_at_percent')->default(80);

            $table->timestamps();

            $table->unique(['promotion_id', 'kind'], 'pbg_kind_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_budgets');
    }
};
