<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা লাইসেন্সের মেয়াদ গেল আর কেউ শুনল না — ডকুমেন্টের তৃতীয় ধাপ (৯ অক্টোবর ২০২৬)।
 *
 * ⭐ পরিকল্পনা §১২: মেয়াদের খবর ৯০ → ৬০ → ৩০ → ১৫ → ৭ → ১ দিন আগে, আর পেরোলে একবার।
 *
 * ⓘ `dms_expiry_notices` — কোন কাগজের কোন মেয়াদের কোন ধাপের খবর গেছে। ⛔ ঘণ্টায় একবার চলা কাজ
 * ([[DocumentExpiry]]) এই সারি দেখে — একই খবর দ্বিতীয়বার যায় না। ⓘ মেয়াদের তারিখও চাবিতে: কাগজ
 * নবায়ন হয়ে নতুন মেয়াদ পেলে খবরের চক্রটা নতুন করে শুরু হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dms_expiry_notices', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('dms_documents')->cascadeOnDelete();
            $table->date('expiry_date');

            // ⓘ ৯০, ৬০, ৩০, ১৫, ৭, ১ — আর ০ মানে "মেয়াদ শেষ"
            $table->unsignedSmallInteger('threshold');

            // ⓘ কতজনকে গেল — ০ হলেও সারি বসে, যাতে প্রতি ঘণ্টায় আবার চেষ্টা না হয়
            $table->unsignedSmallInteger('sent_to')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'expiry_date', 'threshold'], 'dms_expiry_notice_once');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dms_expiry_notices');
    }
};
