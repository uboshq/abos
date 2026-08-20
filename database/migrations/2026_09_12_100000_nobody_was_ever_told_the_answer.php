<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিজ্ঞপ্তি — কারো জন্য কিছু ঘটেছে, আর সেটা তাঁকে বলা হয়।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ঘণ্টা আগে থেকেই ছিল, আর তাতে "আপনার তিনটা সিদ্ধান্ত বাকি"ও দেখাত।
 * কিন্তু ওটা **গণনা করা** তথ্য, সংরক্ষিত নয় — অর্থাৎ যা এখনো ঝুলে আছে
 * কেবল সেটাই দেখা যেত।
 *
 * ফলে সবচেয়ে দরকারি খবরটাই কেউ কখনো পেত না: **আপনার কাগজটার কী হলো।**
 * যিনি খরচের দাবি তুলেছেন, তাঁর দাবি অনুমোদিত হলো না বাতিল হলো — সেটা
 * সিদ্ধান্তের মুহূর্তেই "অপেক্ষমাণ" তালিকা থেকে হারিয়ে যেত, আর তারপর
 * কোথাও থাকত না।
 *
 * বাস্তবে মানুষ তখন ফোন করে জিজ্ঞেস করেন। অনুমোদনের পুরো ব্যবস্থাটা
 * তখন কাগজে থাকে আর কাজে চলে ফোনে।
 *
 * ── কেন এটা সংরক্ষিত সারি, গণনা নয় ──────────────────────────────────
 * "ঘটে গেছে" এমন ঘটনা গণনা করে পাওয়া যায় না — ঘটনার পরে অবস্থাটাই আর
 * নেই। তাই ঘটনার মুহূর্তে একটা সারি বসাতে হয়, আর সেটাই এই টেবিল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /* কার জন্য — বিজ্ঞপ্তি সবসময় একজনের, দলের নয়। */
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /* কী ধরনের ঘটনা — `approval.approved`, `approval.rejected`, … */
            $table->string('type', 64);

            $table->string('title', 191);
            $table->string('body', 500)->nullable();

            /*
             * কোথায় গেলে জিনিসটা দেখা যাবে।
             *
             * nullable, কারণ কিছু ঘটনার কোনো পর্দা নেই। কিন্তু যেটার
             * আছে, সেটার লিংক থাকা বাধ্যতামূলক ধরা উচিত — যে বিজ্ঞপ্তি
             * পড়ে কী করতে হবে বোঝা যায় না, সে কেবল উদ্বেগ ছড়ায়।
             */
            $table->string('url', 500)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            /*
             * প্রধান প্রশ্নটা সবসময় একটাই: "আমার না-পড়া কী কী আছে।"
             * তাই ইনডেক্সও ঠিক সেই ক্রমে।
             */
            $table->index(['user_id', 'read_at', 'id']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
