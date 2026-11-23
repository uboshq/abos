<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বেতনের খাত "মজুরি" কি না — শ্রম আইন ধারা ২(৪৫) যা মজুরি থেকে বাদ দেয় (fe-র উত্তর ১, ২১ অক্টোবর ২০২৬; [[EarnedLeave::encashment()]];
 * a6, ২১ অক্টোবর ২০২৬)।
 *
 *   · `hr_salary_heads.outside_wages` — হ্যাঁ হলে এই খাত ধারা ২(৪৫)-এর মজুরি নয়: বোনাস বা উৎসব ভাতা, মালিকের ভবিষ্য তহবিলের চাঁদা,
 *     যাতায়াত বা বিশেষ খরচের ভাতা। না-নেওয়া অর্জিত ছুটির দৈনিক হার (মাসিক মজুরি ÷ ৩০) এই খাতগুলো ধরে না।
 *
 * ⓘ ডিফল্ট "না" — চলতি প্রতিটা খাত আগের মতো মজুরি; যাতায়াত ভাতা (CONVEY) নতুন কোম্পানির প্রমিত খাতে "হ্যাঁ" হয়ে বসে, চলতিগুলো
 * খাতের পর্দায় টিক দিয়ে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('hr_salary_heads', 'outside_wages')) {
            return;
        }

        Schema::table('hr_salary_heads', function (Blueprint $table): void {
            $table->boolean('outside_wages')->default(false)->after('prorated_by_attendance');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hr_salary_heads', 'outside_wages')) {
            return;
        }

        Schema::table('hr_salary_heads', function (Blueprint $table): void {
            $table->dropColumn('outside_wages');
        });
    }
};
