<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * নির্ধারিত রিপোর্ট — রোজ সকালে কারো হাতে চাওয়ার বদলে নিজে থেকে তৈরি হয়।
 *
 * প্রতিটা সারি একটা সূচি: কোন রিপোর্ট, কোন ছাঁকনিতে, কোন ফরম্যাটে, কখন,
 * আর কার কাছে। ফাইলটা সেই সময়ে তৈরি হয়ে বসে থাকে, বিজ্ঞপ্তি যায়।
 *
 * ── কয়টা সিদ্ধান্ত স্কিমাতেই ─────────────────────────────────────────
 * `created_by` = অনুমতির মালিক — ক্রন সেই ব্যবহারকারীর অনুমতি-প্রসঙ্গে
 * রিপোর্ট রেন্ডার করে, নাহলে যে ক্রয়মূল্য পর্দায় দেখতে পান না তা তাঁর
 * ফাইলে চলে যেত। `recipients` অভ্যন্তরীণ ব্যবহারকারীর id — বাইরের ইমেইল
 * নয় (তাদেরও অনুমতি যাচাই করা যায়)।
 *
 * সময় UTC-তে জমা (`next_run_at`), কিন্তু হিসাব হয় `timezone`-এ — "সকাল
 * ৮টা" কার ঘড়িতে, সেটা স্পষ্ট না রাখলে ছয় মাস পর কেউ ভাঙা লজিক পেত।
 * মাসিকে `day_of_month` (১–২৮) বা `on_month_end` — ৩১ ফেব্রুয়ারিতে নেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // কোন রিপোর্ট (ReportEngine-এর key) আর কোন ছাঁকনিতে
            $table->string('report_key', 100);
            $table->json('filters')->nullable();

            // csv · xlsx · json · pdf
            $table->string('format', 8)->default('xlsx');

            // daily · weekly · monthly
            $table->string('frequency', 8);
            // "08:00" — timezone-এর ঘড়িতে
            $table->string('at_time', 5)->default('08:00');
            // সপ্তাহে: 0(রবি)–6; মাসে: 1–28, নয়তো on_month_end
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->boolean('on_month_end')->default(false);

            // "সকাল ৮টা" কার ঘড়িতে — স্পষ্ট, নাহলে UTC ধরে নেওয়া হত
            $table->string('timezone', 64)->default('Asia/Dhaka');

            // অভ্যন্তরীণ ব্যবহারকারীর id — প্রত্যেকেরও রিপোর্ট-অনুমতি লাগে
            $table->json('recipients');

            // অনুমতির মালিক — ক্রন এঁর প্রসঙ্গে রেন্ডার করে (বাধ্যতামূলক)
            $table->foreignId('created_by')->constrained('users');

            $table->boolean('is_active')->default(true);

            // ক্রনের হিসাব — UTC-তে জমা
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 32)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ক্রন প্রতিবার "কোন সূচির সময় হয়েছে" খোঁজে — এই ইনডেক্স ধরে
            $table->index(['is_active', 'next_run_at']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
