<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * পড়া আর মেনে নেওয়া একই সারিতে বসত।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ১৮ ও ১৯ ───────────────
 * *"Read Required · Acknowledgement Required · Deadline · Reminder ·
 * Escalation"*, আর পাঁচটা অবস্থা: Unread · Read · Acknowledged ·
 * Overdue · Escalated।
 *
 * ── ⚠️ কেন `notice_reads` দিয়ে কাজ চালানো যায় না ────────────────────
 * ⓘ পড়া মানে *"আমি লেখাটা দেখেছি"* — পাতাটা খুললেই বসে যায়। মেনে
 * নেওয়া মানে *"আমি এটা মানছি"* — মানুষকে একটা বোতামে চাপতে হয়।
 *
 * ⛔ এক ঘরে রাখলে **নতুন নীতিমালা কেউ মেনেছেন কি না** প্রশ্নের উত্তর
 * হত *"পাতাটা কে খুলেছেন"*। ⚠️ আর সেই উত্তরটা আদালতে বা অডিটে কোনো
 * উত্তরই নয়।
 *
 * ⓘ তিনটা আলাদা টেবিল, তিনটা আলাদা প্রশ্ন: পড়া ([[NoticeRead]]),
 * সরানো ([[NoticeDismissal]]), আর মেনে নেওয়া (এটা)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('acknowledged_at');

            /*
             * ⓘ কোন যন্ত্র থেকে — অডিটের জন্য।
             *
             * ⚠️ স্পেকের ধারা ২৪ প্রতিটা গুরুত্বপূর্ণ কাজে IP ও যন্ত্র
             * চায়। ⛔ ছয় মাস পরে *"আমি ওটা মানিনি"* বলা হলে সারিটাই
             * একমাত্র উত্তর, আর খালি সারি কোনো উত্তর নয়।
             */
            $table->string('ip', 45)->nullable();
            $table->string('agent', 255)->nullable();

            $table->timestamps();

            /*
             * ⚠️ একজন একটা নোটিশ একবারই মানেন।
             *
             * ⓘ দুইবার চাপলে **প্রথমবারের সময়টাই** থাকে — কারণ প্রশ্নটা
             * সবসময় *"কখন প্রথম মেনেছিলেন"*, আর শেষবার কখন চাপলেন তাতে
             * কারও কিছু যায় আসে না।
             */
            $table->unique(['notice_id', 'user_id'], 'nack_once');
            $table->index(['company_id', 'notice_id'], 'nack_count');
        });

        Schema::create('notice_reminders', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * ⓘ কততম তাগাদা — ১, ২, তারপর এসকেলেশন।
             *
             * ⚠️ সংখ্যাটা সারিতে রাখা হয়, গোনা হয় না: ⛔ সারি গুনে বের
             * করলে একটা সারি মুছে গেলে তাগাদাটা আবার প্রথম থেকে শুরু হত,
             * আর মানুষ একই তাগাদা দুইবার পেতেন।
             */
            $table->unsignedTinyInteger('round');

            $table->timestamp('sent_at');
            $table->boolean('escalated')->default(false);
            $table->timestamps();

            /*
             * ⛔ একই তাগাদা একই মানুষকে দুইবার নয়।
             *
             * ⚠️ সময়ের কাজ (scheduler) একই মিনিটে দুইবার চললে — আর
             * সেটা ঘটে — দুইটা ইমেল যেত। ⓘ ইউনিকটাই সেটা আটকায়, আর
             * আটকানোটা ডাটাবেজে, কোডে নয়।
             */
            $table->unique(['notice_id', 'user_id', 'round'], 'nrem_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reminders');
        Schema::dropIfExists('notice_acknowledgements');
    }
};
