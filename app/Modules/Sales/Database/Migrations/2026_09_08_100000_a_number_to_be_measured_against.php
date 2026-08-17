<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * লক্ষ্যমাত্রা — যার সাথে অর্জন মাপা হয়।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * ABOS বলতে পারত "এই মাসে করিম ৪,২০,০০০ টাকা বিক্রি করেছেন"। কিন্তু
 * ডিপোতে প্রশ্নটা কখনো ওটা নয় — প্রশ্নটা হলো **"টার্গেটের কত পারসেন্ট
 * হলো?"** আর তার উত্তর দেওয়ার কোনো উপায় ছিল না, কারণ টার্গেট বলে
 * কোনো সংখ্যা কোথাও লেখা হত না। মালিকের উত্তর (১৬ আগস্ট): *ডিপোতে
 * বিক্রয়কর্মীর টার্গেট ধরা হয়।*
 *
 * ── কেন ব্যবহারকারী ধরে, কর্মী তালিকা ধরে নয় ────────────────────────
 * অর্জনটা আসে বিলের `created_by` থেকে — অর্থাৎ যিনি বিলটা কেটেছেন,
 * আর তিনি একজন **ব্যবহারকারী**। কর্মী তালিকা (`hr_employees`) ধরে
 * বসালে দুইটা সমস্যা: বিক্রয় মডিউলকে Hr-এর ভেতরে হাত দিতে হত (সীমানার
 * পরীক্ষা যেটা ধরে), আর কর্মী ও লগইনের মধ্যে কোনো বাঁধা সম্পর্কও নেই —
 * তখন অর্জন কার ঘরে বসবে তা মেলানো যেত না।
 *
 * ── কেন মাস, আর কেন প্রথম তারিখটাই লেখা হয় ──────────────────────────
 * ডিপোর টার্গেট মাসের, সপ্তাহের নয়। মাসটা একটা তারিখ হিসেবে রাখা
 * (সবসময় ১ তারিখ) — "2026-09" ধরনের লেখা রাখলে তুলনা ও ছাঁকা দুইটাই
 * স্ট্রিং ধরে করতে হত, আর সেপ্টেম্বর-অক্টোবরের ক্রম ঠিক থাকলেও
 * ২০২৬-২০২৭ অর্থবছরের ক্রম ভাঙত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_targets', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /*
             * কার টার্গেট।
             *
             * restrictOnDelete নয়, cascade নয় — ব্যবহারকারী মোছা হয়ই
             * না (নিষ্ক্রিয় হয়), তাই প্রশ্নটাই ওঠে না। তবু FK আছে,
             * কারণ ছাড়া রাখলে একদিন কেউ হাতে সারি বসাত এমন id দিয়ে
             * যার কোনো ব্যবহারকারী নেই, আর রিপোর্টে নামহীন একটা সারি
             * ঝুলে থাকত।
             */
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // মাসের প্রথম তারিখ — সবসময়
            $table->date('month');

            $table->decimal('amount', 18, 4);
            $table->string('narration', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * একজনের এক মাসে একটাই টার্গেট।
             *
             * ডাটাবেসেই, সেবায় নয়: দুইজন একই সময়ে একই মাসের টার্গেট
             * বসালে সেবার পরীক্ষাটা দুইবার পাশ করত আর দুইটা সারি বসত —
             * তখন "টার্গেট কত" প্রশ্নটার দুইটা উত্তর থাকত।
             */
            $table->unique(['company_id', 'user_id', 'month']);
            $table->index(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_targets');
    }
};
