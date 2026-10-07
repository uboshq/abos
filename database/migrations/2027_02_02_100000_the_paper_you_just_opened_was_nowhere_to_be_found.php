<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ কে কোন কাগজ শেষ কবে খুলেছেন — Ctrl+K-এর খালি বাক্সের জন্য, ২ অক্টোবর ২০২৬।
 *
 * ── ⓘ কী ছিল না ────────────────────────────────────────────────────────
 * ডিজাইন-চেকলিস্টের ধাপ ৭, ৫ নম্বর: *"Ctrl+K খালি অবস্থায় সাম্প্রতিক কাগজ
 * আর প্রস্তাবিত কাজ"*। ⛔ খোঁজা হলো — "কে কোন কাগজ খুলেছেন" কোথাও লেখা হয়
 * না: অডিট লেখে বদল, `document_deliveries` লেখে ছাপা/পাঠানো, কেউ খোলা নয়।
 *
 * ── ⚠️ সারিতে কেবল ঠিকানা, কাগজের কিছু নয় ──────────────────────────────
 * নম্বর, নাম, টাকা — কিছুই এখানে জমা হয় না। ⛔ জমা হলে যিনি আজ আর কাগজটা
 * খুলতে পারেন না, তিনি তবু পুরনো সারি থেকে নম্বর আর নাম দেখতেন। ⭐ তাই
 * প্রতিবার দেখানোর আগে কাগজটা আবার তোলা হয়, আজকের দেয়াল দিয়ে
 * ([[StartingPoints::recent()]])।
 *
 * ⓘ সারি প্রতি মানুষ প্রতি কোম্পানিতে একটা কাগজে একটাই — আবার খুললে
 * সময়টা বদলায়, নতুন সারি বসে না ([[recent_paper_once]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_papers', function (Blueprint $table) {
            $table->id();

            $table->publicId();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            /* ⓘ মডেলের শ্রেণি — যে মডিউল `drill_sources`-এ ঘোষণা করেছে */
            $table->string('paper_type', 150);
            $table->unsignedBigInteger('paper_id');

            $table->timestamp('opened_at');

            $table->timestamps();

            /* ⚠️ নামগুলো ছোট, হাতে — নিজে বানালে ৬৪ অক্ষর পেরোত */
            $table->unique(['user_id', 'company_id', 'paper_type', 'paper_id'], 'recent_paper_once');
            $table->index(['user_id', 'company_id', 'opened_at'], 'recent_paper_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_papers');
    }
};
