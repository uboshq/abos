<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কে কোন খবর পেতে চান — ঘণ্টার নিজের সুইচ।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: *"বিজ্ঞপ্তির সেটিংস ta koro"* (ফিন্যান্স মানচিত্রের §৩২)। খবর
 * পাঠানোর ব্যবস্থাটা আছে, কিন্তু বন্ধ করার কোনো উপায় ছিল না। ⓘ যে খবর
 * কেউ চান না, সেটা বন্ধ করতে না পারলে মানুষ **সবগুলোই** দেখা ছেড়ে দেন —
 * তখন যেটা সত্যিই জরুরি সেটাও হারিয়ে যায়।
 *
 * ── কেন সারিটা কোম্পানির নয় ─────────────────────────────────────────
 * বাকি প্রায় সব টেবিল কোম্পানি ধরে ভাগ করা, এটা নয়: পছন্দটা **মানুষের**,
 * কোম্পানির নয়। ⛔ কোম্পানি ধরে রাখলে দুই কোম্পানিতে কাজ করা একজনকে একই
 * সুইচ দুইবার বন্ধ করতে হত, আর দ্বিতীয়বার ভুলে গেলে খবর আসতেই থাকত।
 *
 * ── কেন কেবল "বন্ধ"গুলো লেখা হয় ─────────────────────────────────────
 * সারি না থাকা মানে **চালু** — নতুন কোনো ধরনের খবর যোগ হলে সেটা সবাই
 * পান, আর যাঁর দরকার নেই তিনি বন্ধ করেন। ⚠️ উল্টোটা করলে নতুন খবর কেউ
 * পেত না, আর সেটা নীরব: কেউ জানত না কী মিস করছেন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_choices', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /* কোন ধরনের খবর — `approval.rejected`, `report_ready`, … */
            $table->string('type', 64);

            /* এখানে সারি থাকলে পছন্দটা লেখা আছে; false মানে পাঠানো হবে না। */
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'type'], 'notify_choice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_choices');
    }
};
