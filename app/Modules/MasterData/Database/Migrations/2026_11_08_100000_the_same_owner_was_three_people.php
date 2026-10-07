<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * যাঁদের সাথে টাকার সম্পর্ক — এক তালিকা, এক সারি, একজন মানুষ।
 *
 * ── কেন এই টেবিলটা লাগল, ১৩ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * মালিক নিজে ধরেছেন: অর্থ মডিউলের পাঁচ জায়গায় "কে" ঘরটা মুক্ত লেখা ছিল —
 * মূলধন, উত্তোলন, উত্তোলনের মাসিক সীমা, হাতে-ধার, আর আমানতের ধারক।
 * পাঁচটাই আলাদা আলাদা, অর্থাৎ একই মানুষ পাঁচ জায়গায় পাঁচ বানানে থাকতে
 * পারতেন।
 *
 * ⛔ সবচেয়ে দামি ফল উত্তোলনের সীমায়: সীমা বসত নামে, আর উত্তোলনও মেলানো
 * হত নামে (`assertWithinCap`)। বানান না মিললে সীমাটা খুঁজেই পাওয়া যেত
 * না আর চুপচাপ কিছুই আটকাত না — টাকা বেরিয়ে যাওয়ার একটা নীরব পথ।
 *
 * ── কেন `mdm_people`, আর কেন Finance-এর ভেতরে নয় ─────────────────────
 * তালিকাটা পাঁচ জায়গায় ব্যবহৃত হয়, আর কালই হিসাব বা HR থেকেও লাগতে
 * পারে। মাস্টার ডাটায় রাখলে নিবন্ধন একটাই — [[MasterListController]]-এর
 * `KINDS`-এ একটা সারি, আর রুট-পর্দা-ফর্ম-কোড-নকলপাহারা সব আপনাআপনি আসে।
 * Finance-এর ভেতরে রাখলে ষষ্ঠ ব্যবহারকারীর দিন ওটা সরাতে হত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_people', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            /*
             * মোবাইল — ঐচ্ছিক, কিন্তু দিলে সেটাই পরিচয়ের সবচেয়ে শক্ত সূত্র।
             *
             * নামে নকল ধরা নরম (দুইজন সত্যিকারের "মোঃ রহিম" থাকতে পারেন),
             * কিন্তু একটা নম্বর দুইজনের হয় না — তাই নম্বর মিললে
             * [[App\Core\Services\DuplicationEngine]] থামায়।
             *
             * ⓘ `nullable`, কারণ মালিকের ভাইয়ের নম্বর হাতের কাছে না
             * থাকলেও তাঁর মূলধন আজই বসাতে হয়। বাধ্যতামূলক করলে মানুষ
             * একটা ভুয়া নম্বর লিখতেন, আর তখন কঠিন পাহারাটাই মিথ্যা হত।
             */
            $table->string('mobile', 32)->nullable();

            // "মালিকের ছোট ভাই", "২০২৪-এর অংশীদার" — মনে রাখার জন্য,
            // খোঁজার জন্য নয়
            $table->string('note', 191)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);

            /*
             * নামে ইনডেক্স — ব্যাক-ফিল ও পরে মিলিয়ে দেওয়ার কাজে লাগে।
             *
             * ⚠️ `unique` **নয়**, আর সেটাই এখানে মূল সিদ্ধান্ত: একই নামের
             * দুইজন সত্যিকারের আলাদা মানুষ থাকতে পারেন, তাই ডাটাবেজ ওটা
             * আটকাবে না। সতর্ক করার কাজটা নকল-পাহারার, আর সে থামে কিন্তু
             * জেনেশুনে এগোতেও দেয় — ডাটাবেজের constraint সেটা পারত না।
             */
            $table->index(['company_id', 'name_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_people');
    }
};
