<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বন্ধ মাসেও এন্ট্রি বসত।
 *
 * ── কী ছিল না ────────────────────────────────────────────────────────
 * অর্থবছর বন্ধ করার ব্যবস্থা ছিল, আর `PostingEngine` সেটা মানত। তার
 * নিচে কিছুই ছিল না: **একটা মাস বন্ধ করার কোনো উপায়ই ছিল না।** ফলে
 * জুনের রিপোর্ট সবাইকে পাঠানোর পরেও জুনে নতুন ভাউচার বসানো যেত, আর
 * পরদিন একই রিপোর্ট অন্য সংখ্যা দেখাত — কেউ টের পেত না কেন।
 *
 * Control Panel-এ "কত দিন পেছনের তারিখে এন্ট্রি নেওয়া যাবে" ঘরটাও ছিল,
 * কিন্তু ওই সংখ্যাটা কোথাও পড়াই হত না।
 *
 * ── year ও month আলাদা কলামে কেন, তারিখ নয় ──────────────────────────
 * তালাটা একটা মাসের, একটা দিনের নয়। তারিখ রাখলে "১ জুলাই থেকে বন্ধ"
 * লেখা যেত, আর তখন প্রশ্ন উঠত ৩০ জুন খোলা কি না। দুইটা সংখ্যা রাখলে
 * প্রশ্নটাই ওঠে না।
 *
 * ── unique কেন ───────────────────────────────────────────────────────
 * একই মাস দুইবার বন্ধ হলে খোলার সময় একটা রয়ে যেত, আর মাসটা বন্ধই
 * থাকত — অথচ পর্দা বলত খোলা।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            /*
             * কারণটা বাধ্যতামূলক নয়, কিন্তু চাওয়া হয়।
             *
             * ছয় মাস পরে "এই মাসটা বন্ধ কেন" প্রশ্নের উত্তর কেবল এখানেই
             * থাকে — অডিটে কে বন্ধ করেছেন তা লেখা থাকে, কেন তা নয়।
             */
            $table->string('reason', 255)->nullable();

            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'month']);
            $table->index(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_locks');
    }
};
