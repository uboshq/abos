<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * নোটিশটা সরিয়ে দেওয়া আর পড়ে ফেলা এক ঘরে বসত।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ১২ ────────────────────
 * বারে *"Dismiss যেখানে অনুমোদিত"*, আর CRITICAL-এ *"Dismiss disabled"*।
 *
 * ── ⚠️ কেন `notice_reads` দিয়ে কাজ চালানো গেল না ────────────────────
 * ⓘ সহজ উত্তর ছিল: বারের ক্রসে চাপলে নোটিশটাকে *"পড়া হয়েছে"* লিখে
 * দেওয়া। ⛔ কিন্তু ক্রসে চাপা মানে **"এটা আমার চোখের সামনে থেকে
 * সরাও"**, আর পড়া মানে **"আমি এটা পড়েছি"** — দুইটা আলাদা কথা।
 *
 * ⚠️ এক ঘরে রাখলে ধাপ ৪-এর *"কতজন পড়েছেন"* সংখ্যাটা মিথ্যা হয়ে যেত,
 * আর মিথ্যাটা থাকত **বেশির দিকে**: যাঁরা কেবল বিরক্ত হয়ে সরিয়েছেন
 * তাঁরাও পাঠক হিসেবে গোনা হতেন।
 *
 * ⓘ আর ঐ সংখ্যাটা দেখেই সিদ্ধান্ত হয় নোটিশটা আবার পাঠাতে হবে কি না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_dismissals', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('dismissed_at');
            $table->timestamps();

            /*
             * ⓘ একজন একটা নোটিশ একবারই সরাতে পারেন।
             *
             * ⚠️ ইউনিক না দিলে দুইবার ক্রসে চাপলে দুইটা সারি বসত, আর
             * *"কতজন সরিয়েছেন"* সংখ্যাটা মানুষের সংখ্যা না হয়ে ক্লিকের
             * সংখ্যা হয়ে যেত।
             */
            $table->unique(['notice_id', 'user_id'], 'ntd_once');
            $table->index(['user_id', 'company_id'], 'ntd_mine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_dismissals');
    }
};
